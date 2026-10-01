<?php

namespace App\Services\Messaging;

use App\Models\MessageThread;
use App\Models\VoiceCall;
use Illuminate\Support\Carbon;

class UnreadCountService
{
    /**
     * Accurate per-channel unread/attention totals for badge surfaces.
     *
     * @return array{email: int, sms: int, call: int, total: int}
     */
    public function forUser(int $userId, bool $includeCalls = true): array
    {
        $user = \App\Models\User::find($userId);
        $email = $user && app(DashboardMessagingPolicy::class)->canEmail($user) ? $this->countUnreadThreads('EMAIL', $userId) : 0;
        $sms = $this->countUnreadThreads('SMS', $userId);
        $call = $includeCalls ? $this->countAttentionCalls() : 0;

        return [
            'email' => $email,
            'sms' => $sms,
            'call' => $call,
            'total' => $email + $sms + $call,
        ];
    }

    public function countUnreadThreads(string $channel, int $userId): int
    {
        return MessageThread::query()
            ->where('channel', strtoupper($channel))
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
    public function countAttentionCalls(?Carbon $since = null): int
    {
        $since ??= now()->subDays(30);

        return VoiceCall::query()
            ->where('created_at', '>=', $since)
            ->where(function ($query) {
                $query->whereIn('disposition', ['handoff_to_staff', 'callback_needed'])
                    ->orWhere('needs_follow_up', true)
                    ->orWhereJsonContains('metadata->needs_follow_up', true);
            })
            ->count();
    }
}
