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
     * Reports the authenticated user has confirmed for other citizens,
     * including the evidence photo they personally uploaded.
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
            'category'    => ['required', 'string', 'max:100'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'address'     => ['required', 'string', 'max:500'],
            'latitude'    => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'   => ['nullable', 'numeric', 'between:-180,180'],
            'images'      => ['required', 'array', 'min:1', 'max:3'],
            'images.*'    => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

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
            $report = DB::transaction(function () use ($request, $user, $validated) {
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
                    'user_id'     => $user->id,
                    'category'    => $validated['category'],
                    'title'       => $validated['title'],
                    'description' => $validated['description'],
                    'address'     => $validated['address'],
                    'state'       => $user->state,
                    'country'     => $user->country ?? 'Nigeria',
                    'latitude'    => $validated['latitude'] ?? $user->latitude,
                    'longitude'   => $validated['longitude'] ?? $user->longitude,
                    // Explicitly set — Eloquent's create() only reflects attributes
                    // passed in, not DB-level column defaults, so leaving this out
                    // meant $report->status was null in memory immediately after
                    // create() even though the DB row correctly defaulted to 'pending'.
                    'status'      => 'pending',
                    'images'      => $paths,
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

                return ReportConfirmation::create([
                    'report_id'     => $report->id,
                    'user_id'       => $user->id,
                    'evidence_path' => $path,
                ]);
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
     * Ask OpenAI's vision model whether the uploaded photo(s) plausibly
     * match the selected incident category. Fails "open" (allows the
     * report through) if the API key is missing or OpenAI is unreachable,
     * so a billing issue or outage never blocks legitimate emergency reports.
     */
    private function verifyImagesMatchCategory(string $category, array $files): array
    {
        $apiKey = config('services.openai.key');

        if (!$apiKey) {
            return ['matches' => true, 'reason' => 'AI verification not configured.'];
        }

        $imageContents = [];
        foreach ($files as $file) {
            if (!$file) {
                continue;
            }
            $base64 = base64_encode(file_get_contents($file->getRealPath()));
            $mime   = $file->getMimeType();
            $imageContents[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => "data:{$mime};base64,{$base64}"],
            ];
        }

        if (empty($imageContents)) {
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

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => array_merge(
                                [['type' => 'text', 'text' => $prompt]],
                                $imageContents
                            ),
                        ],
                    ],
                    'max_tokens' => 200,
                ]);

            if (!$response->successful()) {
                Log::error('OpenAI verification failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return ['matches' => true, 'reason' => 'AI verification unavailable.'];
            }

            $content = $response->json('choices.0.message.content');

            // Strip markdown code fences if the model wraps its JSON in them
            $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content ?? ''));

            $parsed = json_decode($content, true);

            if (!is_array($parsed)) {
                Log::warning('Could not parse OpenAI response', ['content' => $content]);
                return ['matches' => true, 'reason' => 'Could not parse AI response.'];
            }

            return [
                'matches' => (bool) ($parsed['matches'] ?? true),
                'reason'  => $parsed['reason'] ?? '',
            ];

        } catch (\Throwable $e) {
            Log::error('OpenAI request threw exception', ['message' => $e->getMessage()]);
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
            'score'         => $r->ai_score . '%',
            'confirmations' => $r->confirmations_count ?? $r->confirmations()->count(),
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
            'status'                => $this->statusLabel($r->status),
            'date'                  => $r->created_at->format('d F Y'),
            // ISO timestamp so the frontend can compute a live "x mins ago" label
            // without re-parsing the human-readable `date` string above.
            'createdAt'             => $r->created_at->toIso8601String(),
            'score'                 => $r->ai_score . '%',
            'category'              => $r->category,
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