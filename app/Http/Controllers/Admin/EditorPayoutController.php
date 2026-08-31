<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EditorPayoutService;
use App\Services\Messaging\MessagingService;
use App\Support\ReportingWeek;
use Illuminate\Http\Request;

class EditorPayoutController extends Controller
{
    public function __construct(
        private readonly EditorPayoutService $service,
        private readonly MessagingService $messagingService,
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => 'nullable|string|in:paid,unpaid',
            'search' => 'nullable|string|max:255',
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'service_type' => 'nullable|string|max:50',
        ]);

        return response()->json($this->service->getAdminEarnings($filters));
    }

    public function detail(Request $request, User $editor)
    {
        if ($editor->role !== 'editor') {
            return response()->json(['message' => 'Editor not found'], 404);
        }

        $filters = $request->validate([
            'status' => 'nullable|string|in:paid,unpaid',
            'start' => 'nullable|date',
            'end' => 'nullable|date',
            'service_type' => 'nullable|string|max:50',
        ]);

        return response()->json([
            'data' => $this->service->getEditorDetail($editor, $filters),
        ]);
    }

    public function markPaid(Request $request)
    {
        $validated = $request->validate([
            'payout_ids' => 'required|array|min:1',
            'payout_ids.*' => 'integer|exists:editor_payouts,id',
        ]);

        $result = $this->service->markPaid($validated['payout_ids'], $request->user());

        return response()->json([
            'message' => 'Editor earnings marked paid.',
            'data' => $result,
        ]);
    }

    public function report(Request $request)
    {
        return response()->json($this->index($request)->getData(true));
    }

    public function sendReport(Request $request)
    {
        $filters = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date',
        ]);

        if (!empty($filters['start']) || !empty($filters['end'])) {
            $rangeStart = !empty($filters['start']) ? $filters['start'] : $filters['end'];
            $rangeEnd = !empty($filters['end']) ? $filters['end'] : $rangeStart;
            [$start, $end] = ReportingWeek::normalizeRange($rangeStart, $rangeEnd);
        } else {
            [$start, $end] = ReportingWeek::lastCompleted();
        }
        $summaries = $this->service->buildEmailSummaries($start, $end);

        $sent = 0;
        foreach ($summaries as $summary) {
            if (empty($summary['email'])) {
                continue;
            }

            $html = view('emails.payout-report', [
                'recipientName' => $summary['name'],
                'summary' => $summary,
                'rangeStart' => $start,
                'rangeEnd' => $end,
                'audience' => 'editor',
            ])->render();

            $this->messagingService->sendEmail([
                'to' => $summary['email'],
                'subject' => sprintf(
                    'Weekly earnings recap (%s - %s)',
                    optional($start)->format('M d') ?? 'Start',
                    optional($end)->format('M d') ?? 'End'
                ),
                'body_html' => $html,
                'body_text' => strip_tags($html),
                'send_source' => 'EDITOR_PAYOUT_REPORT',
                'sender_name' => 'R/E Pro Photos',
            ]);
            $sent++;
        }

        return response()->json([
            'message' => 'Editor payout reports sent.',
            'sent_count' => $sent,
        ]);
    }
}
