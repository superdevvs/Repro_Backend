<?php

namespace App\Services\MobileNotifications;

use App\Jobs\SendMobileNotification;
use App\Models\Message;
use App\Models\MobileNotificationDelivery;
use App\Models\MobileNotificationDevice;
use App\Models\SmsSharedMessage;
use App\Models\ShootActivityLog;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\VoiceVoicemail;
use App\Services\Messaging\SharedMmsService;
use App\Services\Messaging\SmsInboxPolicy;
use App\Services\Voice\VoiceBrowserSessionService;
use App\Services\Voice\VoiceLinePolicyService;
use Illuminate\Support\Facades\DB;

class MobileNotificationService
{
    // Allows the documented APNs 15/30-minute 5XX backoff without replaying
    // an alert indefinitely. Retry never extends this original deadline.
    public const DELIVERY_LIFETIME_MINUTES = 60;
    public function queueSMS(int $messageID): void { $this->queue('sms', (string) $messageID); }
    public function queueSharedGroup(string $groupID, string $messageID): void
    {
        if (SmsSharedMessage::whereKey($messageID)->where('group_id', $groupID)->exists()) {
            $this->queue('shared_group', $messageID);
        }
    }
    public function queueVoicemail(int $id): void { $this->queue('voicemail', (string) $id); }
    public function queueEmail(int $id): void { $this->queue('email', (string) $id); }
    public function queueSupport(int $id): void { $this->queue('support', (string) $id); }
    public function queueShootActivity(int $id): void { $this->queue('shoot_activity', (string) $id); }
    public function queueAccountActivity(int $id): void { $this->queue('account_activity', (string) $id); }

    public function notBefore(User $user, string $kind, string $id): ?\Carbon\CarbonImmutable
    {
        return app(MobileNotificationAudience::class)->notBefore($user, $kind, $id);
    }

    public function allowed(User $user, string $kind, string $id): bool
    {
        if (! $user->isAccountEligibleForAuthentication() || ! app(MobileNotificationAudience::class)->pushEnabled($user)) { return false; }
        if ($kind === 'sms') {
            $message = Message::find($id);
            return $message && $message->channel === 'SMS' && $message->direction === 'INBOUND'
                && ! $message->hidden_from_inbox && $message->thread
                && $message->thread->isUnreadForUser($user->id)
                && app(SmsInboxPolicy::class)->canView($user, $message->thread);
        }
        if ($kind === 'shared_group') {
            $message = SmsSharedMessage::find($id);
            return $message && $message->direction === 'INBOUND' && $message->group
                && app(SharedMmsService::class)->eligible($user)
                && $message->group->members()->whereKey($user->id)->exists()
                && app(SharedMmsService::class)->messageUnreadFor($user, $message);
        }
        if ($kind === 'voicemail') {
            $voicemail = VoiceVoicemail::find($id);
            return $voicemail && $voicemail->saved_at && ! $voicemail->resolved_at
                && (! $voicemail->expires_at || $voicemail->expires_at->isFuture()) && $voicemail->voiceCall
                && app(VoiceBrowserSessionService::class)->canOperate($user)
                && app(VoiceLinePolicyService::class)->canAccess($user, $voicemail->voiceCall);
        }
        return app(MobileNotificationAudience::class)->allowed($user, $kind, $id);
    }

    private function queue(string $kind, string $id): void
    {
        if (! config('mobile_notifications.enabled')) { return; }
        // Resolve ownership/permissions after commit. Never hold up inbound
        // provider acceptance or replay provider webhooks to retry a push.
        DB::afterCommit(function () use ($kind, $id) {
            try {
            MobileNotificationDevice::whereNull('revoked_at')->where('expires_at', '>', now())->chunkById(100, function ($devices) use ($kind, $id) {
                foreach ($devices as $device) {
                    $user = $device->activeOwner();
                    if (! $user || ! $this->allowed($user, $kind, $id)) { continue; }
                    $notBefore = $this->notBefore($user, $kind, $id);
                    $delivery = MobileNotificationDelivery::firstOrCreate([
                        'device_id' => $device->id, 'event_key' => $kind.':'.$id.':'.$user->id,
                    ], ['user_id' => $user->id, 'kind' => $kind, 'source_id' => $id, 'status' => 'queued',
                        'not_before' => $notBefore, 'expires_at' => ($notBefore ?? now())->addMinutes(self::DELIVERY_LIFETIME_MINUTES)]);
                    if ($delivery->wasRecentlyCreated && ! $notBefore?->isFuture()) {
                        SendMobileNotification::dispatch($delivery->id)->onQueue(config('mobile_notifications.queue'))->afterCommit();
                    }
                }
            });
            } catch (\Throwable) {
                // The scheduled outbox repair retries creation/enqueue, without
                // turning an accepted inbound webhook into a provider retry.
                \Illuminate\Support\Facades\Log::warning('Mobile notification outbox enqueue deferred.');
            }
        });
    }

    public function reconcile(): void
    {
        if (! config('mobile_notifications.enabled')) { return; }
        // Reconstruct recently missed outbox rows. Stable event keys deduplicate.
        Message::where('channel', 'SMS')->where('direction', 'INBOUND')->where('created_at', '>=', now()->subMinutes(10))
            ->select('id')->chunkById(100, function ($rows) { foreach ($rows as $row) { $this->queueSMS($row->id); } });
        SmsSharedMessage::where('direction', 'INBOUND')->where('updated_at', '>=', now()->subMinutes(10))
            ->select(['id', 'group_id'])->chunkById(100, function ($rows) { foreach ($rows as $row) { $this->queueSharedGroup($row->group_id, $row->id); } });
        VoiceVoicemail::where('saved_at', '>=', now()->subMinutes(10))->select('id')->chunkById(100,
            function ($rows) { foreach ($rows as $row) { $this->queueVoicemail($row->id); } });
        Message::where('channel', 'EMAIL')->where('direction', 'INBOUND')->whereIn('status', ['SENT', 'DELIVERED'])
            ->where('updated_at', '>=', now()->subMinutes(10))->select('id')->chunkById(100,
                function ($rows) { foreach ($rows as $row) { $this->queueEmail($row->id); } });
        SupportTicketMessage::where('created_at', '>=', now()->subMinutes(10))->select('id')->chunkById(100,
            function ($rows) { foreach ($rows as $row) { $this->queueSupport($row->id); } });
        ShootActivityLog::where('created_at', '>=', now()->subMinutes(10))->select('id')->chunkById(100,
            function ($rows) { foreach ($rows as $row) { $this->queueShootActivity($row->id); } });
        UserActivityLog::where('created_at', '>=', now()->subMinutes(10))->select('id')->chunkById(100,
            function ($rows) { foreach ($rows as $row) { $this->queueAccountActivity($row->id); } });
        MobileNotificationDelivery::where('status', 'processing')->where('updated_at', '<', now()->subMinutes(2))->update(['status' => 'uncertain']);
        MobileNotificationDelivery::where('status', 'queued')->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', now()))->chunkById(100, function ($rows) {
            foreach ($rows as $row) { SendMobileNotification::dispatch($row->id)->onQueue(config('mobile_notifications.queue')); }
        });
    }
}
