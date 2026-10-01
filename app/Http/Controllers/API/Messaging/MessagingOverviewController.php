<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\Message;
use App\Services\Messaging\UnreadCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MessagingOverviewController extends Controller
{
    public function __invoke(Request $request, UnreadCountService $unreadCounts): JsonResponse
    {
        // Stats for today
        $today = now()->startOfDay();
        $userId = (int) $request->user()->id;

        $totalSentToday = Message::whereIn('channel', ['EMAIL', 'SMS'])
            ->whereIn('status', ['SENT', 'DELIVERED'])
            ->whereDate('created_at', $today)
            ->count();

        // Include SMS: carrier/region rejects must surface on the messaging dashboard
        // (2026-09-30 property-contact storm left FAILED rows that email-only stats hid).
        $totalFailedToday = Message::whereIn('channel', ['EMAIL', 'SMS'])
            ->where('status', 'FAILED')
            ->whereDate('created_at', $today)
            ->count();

        $totalScheduled = Message::whereIn('channel', ['EMAIL', 'SMS'])
            ->where('status', 'SCHEDULED')
            ->count();

        $counts = $unreadCounts->forUser($userId, includeCalls: true);

        $activeAutomations = AutomationRule::where('is_active', true)->count();

        $recentActivity = Message::with(['thread.contact', 'template', 'channelConfig'])
            ->whereNotIn('messages.id', \App\Models\SupportTicketMessage::whereNotNull('source_message_id')->select('source_message_id'))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $smsFailedToday = Message::where('channel', 'SMS')
            ->where('status', 'FAILED')
            ->whereDate('created_at', $today)
            ->count();

        return response()->json([
            'total_sent_today' => $totalSentToday,
            'total_failed_today' => $totalFailedToday,
            'sms_failed_today' => $smsFailedToday,
            'total_scheduled' => $totalScheduled,
            // Legacy key kept for existing FE cards.
            'unread_sms_count' => $counts['sms'],
            'unread_email_count' => $counts['email'],
            'unread_call_count' => $counts['call'],
            'unread_total' => $counts['total'],
            'unread_counts' => $counts,
            'active_automations' => $activeAutomations,
            'recent_activity' => $recentActivity,
        ]);
    }

    /**
     * @return array{from: \Illuminate\Support\Carbon, to: \Illuminate\Support\Carbon}
     */
    protected function resolveRange(Request $request): array
    {
        $from = Carbon::parse($request->query('from', now()->subDays(7)));
        $to = Carbon::parse($request->query('to', now()));

        return ['from' => $from, 'to' => $to];
    }
}
