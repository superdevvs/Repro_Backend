<?php

namespace App\Http\Controllers;

use App\Services\EditorPayoutService;
use App\Services\Messaging\MessagingService;
use App\Support\ReportingWeek;
use Illuminate\Http\Request;

class EditorPayoutController extends Controller
{
    public function __construct(
        private readonly EditorPayoutService $service,
        private readonly MessagingService $messagingService,
    ) {}

    public function earnings(Request $request)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'editor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $filters = $request->validate([
            'status' => 'nullable|string|in:paid,unpaid',
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'service_type' => 'nullable|string|max:50',
        ]);

        return response()->json([
            'data' => $this->service->getEditorDetail($user, $filters),
        ]);
    }

    public function report(Request $request)
    {
        return $this->earnings($request);
    }

    public function sendReport(Request $request)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'editor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $filters = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
        ]);

        if (! empty($filters['start']) || ! empty($filters['end'])) {
            $rangeStart = ! empty($filters['start']) ? $filters['start'] : $filters['end'];
            $rangeEnd = ! empty($filters['end']) ? $filters['end'] : $rangeStart;
            [$start, $end] = ReportingWeek::normalizeRange($rangeStart, $rangeEnd);
        } else {
            [$start, $end] = ReportingWeek::lastCompleted();
        }
        $payload = $this->service->getEditorDetail($user, [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ]);

        $summary = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'editor',
            'shoot_count' => $payload['summary']['shoot_count'] ?? 0,
            'service_count' => $payload['summary']['service_count'] ?? 0,
            'gross_total' => $payload['summary']['total_earned'] ?? 0,
            'average_value' => ($payload['summary']['service_count'] ?? 0) > 0
                ? round(($payload['summary']['total_earned'] ?? 0) / max(($payload['summary']['service_count'] ?? 0), 1), 2)
                : 0,
            'commission_rate' => null,
            'commission_total' => null,
            'unpaid_amount' => $payload['summary']['unpaid_amount'] ?? 0,
            'paid_amount' => $payload['summary']['paid_amount'] ?? 0,
        ];

        $rendered = app(\App\Services\SystemEmails\DirectEmailTemplates::class)->render('emails.payout-report', [
            'recipientName' => $user->name,
            'summary' => $summary,
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'audience' => 'editor',
        ], sprintf('Weekly earnings recap (%s - %s)', $start->format('M d'), $end->format('M d')));
        if ($rendered === null) {
            return response()->json(['message' => 'Editor earnings email template is disabled.', 'sent_count' => 0]);
        }

        $this->messagingService->sendEmail([
            'to' => $user->email,
            'subject' => $rendered['subject'],
            'body_html' => $rendered['html'],
            'body_text' => $rendered['text'],
            'send_source' => 'EDITOR_PAYOUT_REPORT',
            'sender_name' => 'R/E Pro Photos',
        ]);

        return response()->json([
            'message' => 'Editor earnings report sent.',
        ]);
    }
}
