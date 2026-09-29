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

        $totalSentToday = Message::where('channel', 'EMAIL')
            ->whereIn('status', ['SENT', 'DELIVERED'])
            ->whereDate('created_at', $today)
            ->count();

        $totalFailedToday = Message::where('channel', 'EMAIL')
            ->where('status', 'FAILED')
            ->whereDate('created_at', $today)
            ->count();

        $totalScheduled = Message::where('channel', 'EMAIL')
            ->where('status', 'SCHEDULED')
            ->count();

        $counts = $unreadCounts->forUser($userId, includeCalls: true);

        $activeAutomations = AutomationRule::where('is_active', true)->count();

        $recentActivity = Message::with(['thread.contact', 'template', 'channelConfig'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'total_sent_today' => $totalSentToday,
            'total_failed_today' => $totalFailedToday,
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
