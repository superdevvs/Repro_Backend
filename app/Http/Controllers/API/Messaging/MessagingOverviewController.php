<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\MessageThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MessagingOverviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Stats for today
        $today = now()->startOfDay();
        
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

        $unreadSmsCount = MessageThread::where('channel', 'SMS')
            ->where('last_direction', 'INBOUND')
            ->whereNotNull('unread_for_user_ids_json')
            ->count();

        $activeAutomations = AutomationRule::where('is_active', true)->count();

        $recentActivity = Message::with(['thread.contact', 'template', 'channelConfig'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'total_sent_today' => $totalSentToday,
            'total_failed_today' => $totalFailedToday,
            'total_scheduled' => $totalScheduled,
            'unread_sms_count' => $unreadSmsCount,
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

