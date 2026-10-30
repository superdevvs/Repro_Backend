<?php

namespace App\Services\Messaging;

use App\Models\MessageThread;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\NotificationReadStateService;
use App\Services\RolePermissionService;
use App\Services\SupportTicketService;
use App\Services\Voice\VoiceLinePolicyService;
use Illuminate\Support\Carbon;

class UnreadCountService
{
    /**
     * Accurate per-channel unread/attention totals for badge surfaces.
     *
     * @return array{email: int, sms: int, call: int, support: int, total: int}
     */
    public function forUser(int $userId, bool $includeCalls = true, ?array $notificationReadState = null): array
    {
        $user = \App\Models\User::find($userId);
        $email = $user && app(DashboardMessagingPolicy::class)->canEmail($user) ? $this->countUnreadThreads('EMAIL', $userId) : 0;
        $sms = app(SmsInboxPolicy::class)->canSms($user) ? $this->countUnreadThreads('SMS', $userId) : 0;
        // Keep the badge unit consistent: unread conversations, including only
        // shared groups where this staff account is an explicit current member.
        if ($user) { $sms += app(SharedMmsService::class)->unreadGroupCount($user); }
        $call = $user && $includeCalls ? $this->countAttentionCalls($user) : 0;
        $support = $user ? app(SupportTicketService::class)->unreadCount($user,
            $notificationReadState ?? app(NotificationReadStateService::class)->forUser($user)) : 0;

        return [
            'email' => $email,
            'sms' => $sms,
            'call' => $call,
            'support' => $support,
            'total' => $email + $sms + $call + $support,
        ];
    }

    public function countUnreadThreads(string $channel, int $userId): int
    {
        $smsUser = strtoupper($channel) === 'SMS' ? \App\Models\User::find($userId) : null;
        if (strtoupper($channel) === 'SMS' && ! app(SmsInboxPolicy::class)->canSms($smsUser)) {
            return 0;
        }

        return MessageThread::query()
            ->where('channel', strtoupper($channel))
            ->when(strtoupper($channel) === 'SMS', fn ($q) => app(SmsInboxPolicy::class)->scope($q))
            ->when($smsUser, fn ($q) => app(SmsInboxPolicy::class)->forUser($q, $smsUser))
            ->when(strtoupper($channel) === 'EMAIL', fn ($q) => $q->whereDoesntHave('messages', fn ($messages) => $messages->whereIn('id', \App\Models\SupportTicketMessage::whereNotNull('source_message_id')->select('source_message_id'))))
            ->where(function ($query) use ($userId) {
                // IDs may be stored as int or string depending on encoder / DB.
                $query->whereJsonContains('unread_for_user_ids_json', $userId)
                    ->orWhereJsonContains('unread_for_user_ids_json', (string) $userId);
            })
            ->count();
    }

    /**
     * Match VoiceCallController filter=needs_attention so the Calls nav badge
     * agrees with the Calls inbox. Windowed to 30 days so the badge stays
     * actionable (voice has no per-user unread list).
     */
    public function countAttentionCalls(User $user, ?Carbon $since = null): int
    {
        if (! $user->isAccountEligibleForAuthentication() || ! app(RolePermissionService::class)->userCan($user, 'voice-calls', 'view')) {
            return 0;
        }
        $since ??= now()->subDays(30);
        $query = VoiceCall::query();
        app(VoiceLinePolicyService::class)->scope($query, $user);

        return $query
            ->where('created_at', '>=', $since)
            ->where(function ($query) {
                $query->whereIn('disposition', ['handoff_to_staff', 'callback_needed'])
                    ->orWhere('needs_follow_up', true)
                    ->orWhereJsonContains('metadata->needs_follow_up', true);
            })
            ->count();
    }
}
