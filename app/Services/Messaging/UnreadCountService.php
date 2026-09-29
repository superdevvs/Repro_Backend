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
        $email = $this->countUnreadThreads('EMAIL', $userId);
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
            ->where(function ($query) use ($userId) {
                // IDs may be stored as int or string depending on encoder / DB.
                $query->whereJsonContains('unread_for_user_ids_json', $userId)
                    ->orWhereJsonContains('unread_for_user_ids_json', (string) $userId);
            })
            ->count();
    }

    /**
     * Voice has no per-user unread list. Badge count = recent missed inbound
     * calls plus the Calls inbox "needs attention" set (handoff / callback /
     * needs_follow_up), windowed to 30 days so the badge stays actionable.
     */
    public function countAttentionCalls(?Carbon $since = null): int
    {
        $since ??= now()->subDays(30);

        return VoiceCall::query()
            ->where('created_at', '>=', $since)
            ->where(function ($query) {
                $query->where(function ($missed) {
                    $missed->where('direction', 'INBOUND')
                        ->whereNull('answered_at')
                        ->where(function ($ended) {
                            $ended->whereNotNull('ended_at')
                                ->orWhereIn('status', ['missed', 'failed']);
                        });
                })->orWhere(function ($attention) {
                    $attention->whereIn('disposition', ['handoff_to_staff', 'callback_needed'])
                        ->orWhere('needs_follow_up', true)
                        ->orWhereJsonContains('metadata->needs_follow_up', true);
                });
            })
            ->count();
    }
}
