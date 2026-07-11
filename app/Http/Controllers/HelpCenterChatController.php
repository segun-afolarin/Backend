<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HelpCenterChatController extends Controller
{
    private const MODEL = 'gemini-2.5-flash';

    private const STATUS_LABELS = [
        'pending'     => 'Pending',
        'in_progress' => 'In Progress',
        'resolved'    => 'Resolved',
    ];

    /**
     * Public, unauthenticated help-desk chat endpoint. Anyone can reach this —
     * it must never expose data belonging to a user other than the one proving
     * ownership of a specific report via reference code + email. Passwords are
     * never accepted, stored, or forwarded to the model.
     *
     * POST /api/help-center/chat
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message'          => ['required', 'string', 'max:1000'],
            'history'          => ['array', 'max:20'],
            'history.*.role'   => ['required_with:history', 'in:user,assistant'],
            'history.*.text'   => ['required_with:history', 'string', 'max:1000'],
        ]);

        $apiKey = config('services.gemini_help_center.key');

        if (!$apiKey) {
            return response()->json(['reply' => $this->fallbackReply($validated['message'])]);
        }

        // Defense in depth: strip anything that looks like a password before
        // it ever reaches a prompt or a log line, even if the user pastes
        // one unprompted.
        $safeMessage = $this->scrubSensitive($validated['message']);
        $contents    = $this->buildContents($validated['history'] ?? [], $safeMessage);

        try {
            $reply = $this->converse($contents, $apiKey);
        } catch (\Throwable $e) {
            Log::error('Help center chat failed', ['message' => $e->getMessage()]);
            $reply = $this->fallbackReply($safeMessage);
        }

        return response()->json(['reply' => $reply]);
    }

    /** One or two model round-trips: the first pass may request the track_report tool. */
    private function converse(array $contents, string $apiKey): string
    {
        $first = $this->callGemini($contents, $apiKey);
        $part  = $first['candidates'][0]['content']['parts'][0] ?? null;

        if (isset($part['functionCall'])) {
            $call = $part['functionCall'];
            $args = $call['args'] ?? [];

            $result = $call['name'] === 'track_report'
                ? $this->lookupReport($args['reference_code'] ?? '', $args['email'] ?? '')
                : ['error' => 'unknown_tool'];

            $contents[] = ['role' => 'model', 'parts' => [['functionCall' => $call]]];
            $contents[] = [
                'role'  => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name'     => $call['name'],
                        'response' => $result,
                    ],
                ]],
            ];

            $second = $this->callGemini($contents, $apiKey);
            return trim($second['candidates'][0]['content']['parts'][0]['text'] ?? $this->fallbackReply(''));
        }

        return trim($part['text'] ?? $this->fallbackReply(''));
    }

    private function callGemini(array $contents, string $apiKey): array
    {
        $response = Http::timeout(20)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':generateContent?key=' . $apiKey,
            [
                'systemInstruction' => ['parts' => [['text' => $this->systemPrompt()]]],
                'contents'          => $contents,
                'tools'             => [[
                    'functionDeclarations' => [[
                        'name'        => 'track_report',
                        'description' => 'Look up the status of a single civic report the citizen submitted, using the reference code and the email they used to submit it. Only call this once you have both.',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'reference_code' => ['type' => 'string', 'description' => 'e.g. NA-4F82C1'],
                                'email'          => ['type' => 'string', 'description' => 'Email used when the report was submitted'],
                            ],
                            'required' => ['reference_code', 'email'],
                        ],
                    ]],
                ]],
                'generationConfig' => ['maxOutputTokens' => 400, 'temperature' => 0.4],
            ]
        );

        if (!$response->successful()) {
            throw new \RuntimeException('Gemini request failed: ' . $response->status());
        }

        return $response->json();
    }

    /**
     * Only ever returns status-safe fields for the single report matching
     * BOTH the reference code and the owning user's email. Never matches on
     * code alone, never returns a list, never touches passwords.
     */
    private function lookupReport(string $referenceCode, string $email): array
    {
        $referenceCode = trim($referenceCode);
        $email         = trim(strtolower($email));

        if ($referenceCode === '' || $email === '') {
            return ['found' => false, 'reason' => 'missing_fields'];
        }

        $report = Report::where('reference_code', $referenceCode)
            ->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))
            ->first();

        if (!$report) {
            return ['found' => false, 'reason' => 'no_match'];
        }

        return [
            'found'                  => true,
            'status'                 => self::STATUS_LABELS[$report->status] ?? ucfirst($report->status),
            'submitted_on'           => $report->created_at->format('d F Y'),
            'category'               => $report->category,
            'is_emergency'           => (bool) $report->is_emergency,
            'confirmations'          => $report->confirmations()->count(),
            'required_confirmations' => $report->required_confirmations,
        ];
    }

    private function buildContents(array $history, string $message): array
    {
        $contents = [];
        foreach ($history as $turn) {
            $contents[] = [
                'role'  => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['text']]],
            ];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];
        return $contents;
    }

    private function scrubSensitive(string $message): string
    {
        return preg_replace('/(password|pass|pwd)\s*[:=]?\s*\S+/i', '$1 [redacted]', $message);
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are the NationAura Civic Assistant, a public help-desk chatbot for a Nigerian civic-infrastructure reporting platform (roads, drainage, bridges, power, water). Anyone can open this chat without logging in, so you have no session and no access to any account.

What you help with:
- Explaining how to file a report: pick a category, add location and at least one photo, submit — it's routed to the right agency automatically, and photos are checked by AI to confirm they match the category.
- Explaining the status pipeline: Submitted -> Verified -> Assigned -> In Progress -> Resolved.
- Explaining that reports need citizen confirmations to move forward (3 for emergencies, 5 for normal reports), each with its own photo evidence.
- Explaining community guidelines: only real infrastructure issues, no duplicates, no personal disputes, evidence must be genuine.
- Tracking ONE specific report: ask for its reference code (format like NA-XXXXXX) and the email used to submit it, then call track_report. If it comes back not found, ask them to double-check, or suggest contacting support.

Hard rules:
- NEVER ask for, accept, store, or repeat a password. This platform never needs a password to track a report — only the reference code and email. If someone sends a password anyway, tell them not to share it here and that it isn't needed.
- NEVER claim to look up or describe any other user's report, account, or data.
- If something is outside what you can resolve — disputes, account problems, anything you're not confident about — tell the person to use the Contact Support page or email support@nationaura.ng.
- For life-threatening emergencies, tell them to contact local emergency services directly first.
- Keep replies short, warm, and plain — a few sentences, not an essay.
PROMPT;
    }

    private function fallbackReply(string $message): string
    {
        $m = strtolower($message);

        if (str_contains($m, 'track')) {
            return "I can help you track a report — I'll just need the reference code (like NA-4F82C1) and the email you used to submit it. I never need a password for this.";
        }

        return "NationAura helps citizens report and track infrastructure issues in real time. Ask me how to file a report, how to track one, or how confirmations work — and for anything I can't resolve, I'll point you to our support team.";
    }
}