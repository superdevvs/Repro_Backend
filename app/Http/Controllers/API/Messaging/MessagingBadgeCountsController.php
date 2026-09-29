<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Services\Messaging\UnreadCountService;
use App\Services\RolePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lightweight unread/attention totals for nav badges (Messaging + Emails/SMS/Calls).
 * Broader than /messaging/overview (admin-only) so editing managers and sales reps
 * with messaging access still get accurate counts.
 */
class MessagingBadgeCountsController extends Controller
{
    public function __invoke(
        Request $request,
        UnreadCountService $unreadCounts,
        RolePermissionService $permissions,
    ): JsonResponse {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $userId = (int) $user->id;
        $canEmail = $permissions->userCan($user, 'messaging-email', 'view')
            || $permissions->userCan($user, 'messaging-overview', 'view');
        $canSms = $permissions->userCan($user, 'messaging-sms', 'view');
        $canCalls = $permissions->userCan($user, 'voice-calls', 'view');

        if (! $canEmail && ! $canSms && ! $canCalls) {
            return response()->json([
                'email' => 0,
                'sms' => 0,
                'call' => 0,
                'total' => 0,
            ]);
        }

        $counts = $unreadCounts->forUser($userId, includeCalls: $canCalls);

        return response()->json([
            'email' => $canEmail ? $counts['email'] : 0,
            'sms' => $canSms ? $counts['sms'] : 0,
            'call' => $canCalls ? $counts['call'] : 0,
            'total' => ($canEmail ? $counts['email'] : 0)
                + ($canSms ? $counts['sms'] : 0)
                + ($canCalls ? $counts['call'] : 0),
        ]);
    }
}
