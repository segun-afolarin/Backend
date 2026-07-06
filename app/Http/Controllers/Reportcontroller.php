<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportConfirmation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
     * This is the "you're in Abuja, you see Abuja reports" feed.
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
                    // Location is inherited from the reporter's profile so it
                    // automatically lands in the right state-based feed
                    'state'       => $user->state,
                    'country'     => $user->country ?? 'Nigeria',
                    'latitude'    => $validated['latitude'] ?? $user->latitude,
                    'longitude'   => $validated['longitude'] ?? $user->longitude,
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

    // ── Transformers — shape data exactly as the ReportGrid / ReportFormPanel expect ──

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
            // The photo evidence the current user personally uploaded when confirming
            'myEvidence'  => $c->evidence_url,
        ];
    }

    private function transformNearbyReport(Report $r, array $confirmedByMeIds): array
    {
        return [
            'id'                 => $r->reference_code,
            'title'              => $r->title,
            'location'           => $r->address,
            'status'             => $this->statusLabel($r->status),
            'date'               => $r->created_at->format('d F Y'),
            'score'              => $r->ai_score . '%',
            'confirmations'      => $r->confirmations_count ?? $r->confirmations()->count(),
            'requiredConfirmations' => $r->required_confirmations,
            'image'              => $r->image_urls[0] ?? null,
            'description'        => $r->description,
            'fields'             => $this->deriveFields($r),
            'updates'            => $this->deriveUpdates($r->status),
            'submittedBy'        => ['name' => $r->user->name, 'avatar' => $r->user->avatar],
            'confirmedByMe'      => in_array($r->id, $confirmedByMeIds, true),
            'confirmedBy'        => $r->confirmations->map(fn ($c) => [
                'name'   => $c->user->name,
                'avatar' => $c->user->avatar,
            ])->values()->all(),
            'reportId'           => $r->id, // raw id, needed to call POST /reports/{id}/confirm
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