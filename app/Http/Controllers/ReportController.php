<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportConfirmation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    /** Emergency reports need fewer citizen confirmations before escalation. */
    private const REQUIRED_CONFIRMATIONS_EMERGENCY = 3;
    private const REQUIRED_CONFIRMATIONS_NORMAL    = 5;

    /**
     * Aggregate, state-scoped dashboard stats.
     * GET /api/reports/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->state) {
            return response()->json([
                'state'                => null,
                'totalReports'         => 0,
                'totalGrowth'          => 0,
                'activeLocations'      => 0,
                'resolved'             => 0,
                'resolvedGrowth'       => 0,
                'pending'              => 0,
                'pendingGrowth'        => 0,
                'awaitingVerification' => 0,
                'inProgress'           => 0,
                'verified'             => 0,
                'verifiedGrowth'       => 0,
                'avgResponseHours'     => null,
                'totalConfirmations'   => 0,
                'activeVerifiers'      => 0,
                'weeklyTrend'          => array_fill(0, 7, 0),
                'topCategory'          => null,
                'todayTotal'           => 0,
                'todayByCategory'      => (object) [],
            ]);
        }

        $base = Report::inState($user->state);

        $todayTotal = (clone $base)->whereDate('created_at', now()->toDateString())->count();

        $todayByCategory = (clone $base)
            ->whereDate('created_at', now()->toDateString())
            ->select('category', DB::raw('count(*) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        $totalReports    = (clone $base)->count();
        $activeLocations = (clone $base)->distinct('address')->count('address');
        $resolved        = (clone $base)->where('status', 'resolved')->count();
        $pending         = (clone $base)->whereIn('status', ['pending', 'in_progress'])->count();

        $awaitingVerification = (clone $base)->where('status', 'pending')->count();
        $inProgress           = (clone $base)->where('status', 'in_progress')->count();
        $verified             = (clone $base)->whereIn('status', ['in_progress', 'resolved'])->count();

        $weekAgo     = now()->subDays(7);
        $twoWeeksAgo = now()->subDays(14);

        $totalGrowth    = $this->weekOverWeekGrowth(clone $base, $weekAgo, $twoWeeksAgo);
        $resolvedGrowth = $this->weekOverWeekGrowth((clone $base)->where('status', 'resolved'), $weekAgo, $twoWeeksAgo);
        $pendingGrowth  = $this->weekOverWeekGrowth((clone $base)->whereIn('status', ['pending', 'in_progress']), $weekAgo, $twoWeeksAgo);
        $verifiedGrowth = $this->weekOverWeekGrowth((clone $base)->whereIn('status', ['in_progress', 'resolved']), $weekAgo, $twoWeeksAgo);

        $avgResponseHours = (clone $base)
            ->where('status', 'resolved')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours')
            ->value('avg_hours');
        $avgResponseHours = $avgResponseHours !== null ? round((float) $avgResponseHours, 1) : null;

        $totalConfirmations = ReportConfirmation::whereHas('report', function ($q) use ($user) {
            $q->inState($user->state);
        })->count();

        $activeVerifiers = ReportConfirmation::whereHas('report', function ($q) use ($user) {
            $q->inState($user->state);
        })->distinct('user_id')->count('user_id');

        $weeklyTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $weeklyTrend[] = (clone $base)->whereDate('created_at', $day)->count();
        }

        $topCategory = (clone $base)
            ->where('created_at', '>=', $weekAgo)
            ->select('category', DB::raw('count(*) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(1)
            ->value('category');

        return response()->json([
            'state'                => $user->state,
            'totalReports'         => $totalReports,
            'totalGrowth'          => $totalGrowth,
            'activeLocations'      => $activeLocations,
            'resolved'             => $resolved,
            'resolvedGrowth'       => $resolvedGrowth,
            'pending'              => $pending,
            'pendingGrowth'        => $pendingGrowth,
            'awaitingVerification' => $awaitingVerification,
            'inProgress'           => $inProgress,
            'verified'             => $verified,
            'verifiedGrowth'       => $verifiedGrowth,
            'avgResponseHours'     => $avgResponseHours,
            'totalConfirmations'   => $totalConfirmations,
            'activeVerifiers'      => $activeVerifiers,
            'weeklyTrend'          => $weeklyTrend,
            'topCategory'          => $topCategory,
            'todayTotal'           => $todayTotal,
            'todayByCategory'      => $todayByCategory,
        ]);
    }

    private function weekOverWeekGrowth($query, $weekAgo, $twoWeeksAgo): int
    {
        $thisWeek = (clone $query)->where('created_at', '>=', $weekAgo)->count();
        $lastWeek = (clone $query)->whereBetween('created_at', [$twoWeeksAgo, $weekAgo])->count();

        if ($lastWeek === 0) {
            return $thisWeek > 0 ? 100 : 0;
        }

        return (int) round((($thisWeek - $lastWeek) / $lastWeek) * 100);
    }

    /**
     * Reports the authenticated user submitted.
     * GET /api/reports/mine
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $reports = Report::where('user_id', $user->id)
            ->withCount('confirmations')
            ->with(['confirmations.user:id,name,avatar'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'reports' => $reports->map(fn (Report $r) => $this->transformOwnReport($r)),
        ]);
    }

    /**
     * Reports the authenticated user has confirmed for other citizens.
     * GET /api/reports/confirmed
     */
    public function confirmed(Request $request): JsonResponse
    {
        $user = $request->user();

        $confirmations = ReportConfirmation::where('user_id', $user->id)
            ->with(['report.user:id,name,avatar'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'reports' => $confirmations->map(fn (ReportConfirmation $c) => $this->transformConfirmedReport($c)),
        ]);
    }

    /**
     * Community reports near the authenticated user, matched by state.
     * GET /api/reports/nearby
     */
    public function nearby(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->state) {
            return response()->json([
                'message' => 'Add your state to your profile to see reports near you.',
                'reports' => [],
                'state'   => null,
            ], 200);
        }

        $reports = Report::inState($user->state)
            ->notOwnedBy($user->id)
            ->withCount('confirmations')
            ->with(['user:id,name,avatar', 'confirmations.user:id,name,avatar'])
            ->orderByDesc('created_at')
            ->get();

        $confirmedIds = ReportConfirmation::where('user_id', $user->id)
            ->pluck('report_id')
            ->all();

        return response()->json([
            'state'   => $user->state,
            'reports' => $reports->map(fn (Report $r) => $this->transformNearbyReport($r, $confirmedIds)),
        ]);
    }

    /**
     * Submit a new incident report.
     * POST /api/reports
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'category'     => ['required', 'string', 'max:100'],
            'title'        => ['required', 'string', 'max:255'],
            'description'  => ['required', 'string'],
            'address'      => ['required', 'string', 'max:500'],
            'latitude'     => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'    => ['nullable', 'numeric', 'between:-180,180'],
            'images'       => ['required', 'array', 'min:1', 'max:3'],
            'images.*'     => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_emergency' => ['nullable', 'boolean'],
        ]);

        $isEmergency = filter_var($request->input('is_emergency', false), FILTER_VALIDATE_BOOLEAN);

        // ── AI verification: does the photo evidence match the selected category? ──
        $verification = $this->verifyImagesMatchCategory($validated['category'], $request->file('images'));

        if (!$verification['matches']) {
            return response()->json([
                'message'     => $verification['reason']
                    ?: "The uploaded photo(s) don't appear to match \"{$validated['category']}\". Please upload a clearer photo of the actual incident.",
                'ai_mismatch' => true,
            ], 422);
        }

        try {
            $report = DB::transaction(function () use ($request, $user, $validated, $isEmergency) {
                $paths = [];
                foreach ($request->file('images', []) as $file) {
                    if ($file->isValid()) {
                        $path = $file->store('reports', 'public');
                        if ($path) {
                            $paths[] = $path;
                        }
                    }
                }

                if (empty($paths)) {
                    throw new \RuntimeException('Evidence upload failed — storage returned no path.');
                }

                return Report::create([
                    'user_id'                => $user->id,
                    'category'                => $validated['category'],
                    'title'                   => $validated['title'],
                    'description'             => $validated['description'],
                    'address'                 => $validated['address'],
                    'state'                   => $user->state,
                    'country'                 => $user->country ?? 'Nigeria',
                    'latitude'                => $validated['latitude'] ?? $user->latitude,
                    'longitude'               => $validated['longitude'] ?? $user->longitude,
                    'status'                  => 'pending',
                    'images'                  => $paths,
                    'is_emergency'            => $isEmergency,
                    'required_confirmations'  => $isEmergency
                        ? self::REQUIRED_CONFIRMATIONS_EMERGENCY
                        : self::REQUIRED_CONFIRMATIONS_NORMAL,
                ]);
            });

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Report submission failed', ['user_id' => $user->id, 'message' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to submit report. Please try again.'], 500);
        }

        return response()->json([
            'message' => 'Report submitted successfully.',
            'report'  => $this->transformOwnReport($report->loadCount('confirmations')),
        ], 201);
    }

    /**
     * Confirm someone else's report with photo evidence.
     * POST /api/reports/{report}/confirm
     */
    public function confirm(Request $request, Report $report): JsonResponse
    {
        $user = $request->user();

        if ($report->user_id === $user->id) {
            return response()->json(['message' => 'You cannot confirm your own report.'], 403);
        }

        $request->validate([
            'evidence' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $existing = ReportConfirmation::where('report_id', $report->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'You already confirmed this report.'], 409);
        }

        // ── AI verification for confirmation evidence too ──
        $verification = $this->verifyImagesMatchCategory($report->category, [$request->file('evidence')]);

        if (!$verification['matches']) {
            return response()->json([
                'message'     => $verification['reason']
                    ?: "The uploaded photo doesn't appear to match this report's category (\"{$report->category}\"). Please upload clearer evidence.",
                'ai_mismatch' => true,
            ], 422);
        }

        try {
            $confirmation = DB::transaction(function () use ($request, $report, $user) {
                $path = $request->file('evidence')->store('confirmations', 'public');

                if (!$path) {
                    throw new \RuntimeException('Evidence upload failed — storage returned no path.');
                }

                $confirmation = ReportConfirmation::create([
                    'report_id'     => $report->id,
                    'user_id'       => $user->id,
                    'evidence_path' => $path,
                ]);

                $count = $report->confirmations()->count();
                $newStatus = $count >= $report->required_confirmations
                    ? 'resolved'
                    : 'in_progress';

                if ($newStatus !== $report->status) {
                    $report->update(['status' => $newStatus]);
                }

                return $confirmation;
            });

        } catch (\Throwable $e) {
            Log::error('Report confirmation failed', [
                'report_id' => $report->id,
                'user_id'   => $user->id,
                'message'   => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to submit confirmation. Please try again.'], 500);
        }

        $report->loadCount('confirmations');

        return response()->json([
            'message'       => 'Confirmation submitted.',
            'confirmations' => $report->confirmations_count,
            'evidence_url'  => $confirmation->evidence_url,
        ], 201);
    }

    /**
     * Ask Gemini's vision model whether the uploaded photo(s) plausibly
     * match the selected incident category. Fails "open" (allows the
     * report through) if the API key is missing or Gemini is unreachable,
     * so a billing/outage issue never blocks legitimate emergency reports.
     */
    private function verifyImagesMatchCategory(string $category, array $files): array
    {
        $apiKey = config('services.gemini.key');

        // TEMPORARY DEBUG LOGGING — remove once verification is confirmed working.
        // Tells us whether the key is reaching Laravel at all before we even
        // attempt the API call, so we don't have to guess blindly.
        Log::info('Gemini verification triggered', [
            'has_key'    => !empty($apiKey),
            'key_prefix' => $apiKey ? substr($apiKey, 0, 6) : null,
            'category'   => $category,
            'file_count' => count($files),
        ]);

        if (!$apiKey) {
            return ['matches' => true, 'reason' => 'AI verification not configured.'];
        }

        $imageParts = [];
        foreach ($files as $file) {
            if (!$file) {
                continue;
            }
            $base64 = base64_encode(file_get_contents($file->getRealPath()));
            $mime   = $file->getMimeType();
            $imageParts[] = [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data'      => $base64,
                ],
            ];
        }

        if (empty($imageParts)) {
            return ['matches' => true, 'reason' => 'No images to verify.'];
        }

        $prompt = "You are verifying a civic incident report submitted in Nigeria. "
            . "The reporter selected this incident category: \"{$category}\". "
            . "Look at the attached photo(s) and judge whether they plausibly show "
            . "evidence of this type of incident (categories include: Flooding, Bad Roads, "
            . "Drain Blockage, Power Failure, Fire Outbreak, Accident). "
            . "Be reasonably lenient — approve if the photo could plausibly relate to the category, "
            . "even if not perfectly clear. Only reject if the photo is obviously unrelated "
            . "(e.g. a selfie, a meme, a completely different scene). "
            . "Respond ONLY with strict JSON, no other text, no markdown: "
            . '{"matches": true or false, "reason": "short one-sentence explanation a citizen would understand"}';

        $parts = array_merge([['text' => $prompt]], $imageParts);

        try {
            $response = Http::timeout(30)
    ->post(
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}",
        [
                        'contents' => [
                            ['parts' => $parts],
                        ],
                        'generationConfig' => [
                            'maxOutputTokens' => 200,
                            'temperature'     => 0.2,
                        ],
                    ]
                );

            // TEMPORARY DEBUG LOGGING — logs the raw Gemini response so we can
            // see exactly what came back before parsing it.
            Log::info('Gemini raw response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            if (!$response->successful()) {
                Log::error('Gemini verification failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['matches' => true, 'reason' => 'AI verification unavailable.'];
            }

            $content = $response->json('candidates.0.content.parts.0.text');

            // Strip markdown code fences if Gemini wraps its JSON in them
            $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content ?? ''));

            $parsed = json_decode($content, true);

            if (!is_array($parsed)) {
                Log::warning('Could not parse Gemini response', ['content' => $content]);
                return ['matches' => true, 'reason' => 'Could not parse AI response.'];
            }

            return [
                'matches' => (bool) ($parsed['matches'] ?? true),
                'reason'  => $parsed['reason'] ?? '',
            ];

        } catch (\Throwable $e) {
            Log::error('Gemini request threw exception', ['message' => $e->getMessage()]);
            return ['matches' => true, 'reason' => 'AI verification unavailable.'];
        }
    }

    // ── Transformers ──────────────────────────────────────────────────────

    private function transformOwnReport(Report $r): array
    {
        return [
            'id'            => $r->reference_code,
            'title'         => $r->title,
            'location'      => $r->address,
            'status'        => $this->statusLabel($r->status),
            'date'          => $r->created_at->format('d F Y'),
            'createdAt'     => $r->created_at->toIso8601String(),
            'score'         => $r->ai_score . '%',
            'confirmations' => $r->confirmations_count ?? $r->confirmations()->count(),
            'requiredConfirmations' => $r->required_confirmations,
            'isEmergency'   => (bool) $r->is_emergency,
            'image'         => $r->image_urls[0] ?? null,
            'description'   => $r->description,
            'fields'        => $this->deriveFields($r),
            'updates'       => $this->deriveUpdates($r->status),
            'confirmedBy'   => $r->confirmations->map(fn ($c) => [
                'name'   => $c->user->name,
                'avatar' => $c->user->avatar,
            ])->values()->all(),
        ];
    }

    private function transformConfirmedReport(ReportConfirmation $c): array
    {
        $r = $c->report;

        return [
            'id'          => $r->reference_code,
            'title'       => $r->title,
            'location'    => $r->address,
            'status'      => $this->statusLabel($r->status),
            'date'        => $r->created_at->format('d F Y'),
            'score'       => $r->ai_score . '%',
            'isEmergency' => (bool) $r->is_emergency,
            'confirmedOn' => $c->created_at->format('d F Y'),
            'image'       => $r->image_urls[0] ?? null,
            'description' => $r->description,
            'submittedBy' => [
                'name'   => $r->user->name,
                'avatar' => $r->user->avatar,
            ],
            'fields'      => $this->deriveFields($r),
            'updates'     => $this->deriveUpdates($r->status),
            'myEvidence'  => $c->evidence_url,
        ];
    }

    private function transformNearbyReport(Report $r, array $confirmedByMeIds): array
    {
        return [
            'id'                    => $r->reference_code,
            'title'                 => $r->title,
            'location'              => $r->address,
            'latitude'              => $r->latitude !== null ? (float) $r->latitude : null,
            'longitude'             => $r->longitude !== null ? (float) $r->longitude : null,
            'status'                => $this->statusLabel($r->status),
            'date'                  => $r->created_at->format('d F Y'),
            'createdAt'             => $r->created_at->toIso8601String(),
            'score'                 => $r->ai_score . '%',
            'category'              => $r->category,
            'isEmergency'           => (bool) $r->is_emergency,
            'confirmations'         => $r->confirmations_count ?? $r->confirmations()->count(),
            'requiredConfirmations' => $r->required_confirmations,
            'image'                 => $r->image_urls[0] ?? null,
            'description'           => $r->description,
            'fields'                => $this->deriveFields($r),
            'updates'               => $this->deriveUpdates($r->status),
            'submittedBy'           => ['name' => $r->user->name, 'avatar' => $r->user->avatar],
            'confirmedByMe'         => in_array($r->id, $confirmedByMeIds, true),
            'confirmedBy'           => $r->confirmations->map(fn ($c) => [
                'name'   => $c->user->name,
                'avatar' => $c->user->avatar,
            ])->values()->all(),
            'reportId'              => $r->id,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending'     => 'Pending',
            'in_progress' => 'In Progress',
            'resolved'    => 'Resolved',
            default       => ucfirst($status),
        };
    }

    private function deriveFields(Report $r): array
    {
        $fields = [$r->category];
        if ($r->is_emergency) {
            $fields[] = 'Emergency';
        }
        if (!empty($r->images)) {
            $fields[] = 'Photo Evidence Uploaded';
        }
        $fields[] = $r->ai_score >= 80 ? 'High Priority' : 'Community Alert';
        return $fields;
    }

    private function deriveUpdates(string $status): array
    {
        return match ($status) {
            'resolved'    => ['Submitted', 'Verified', 'Sent to government', 'Resolved'],
            'in_progress' => ['Submitted', 'Verified', 'Authority notified'],
            default       => ['Submitted'],
        };
    }
}