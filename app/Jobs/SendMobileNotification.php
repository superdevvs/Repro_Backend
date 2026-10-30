<?php

namespace App\Jobs;

use App\Models\MobileNotificationDelivery;
use App\Models\MobileNotificationDevice;
use App\Services\MobileNotifications\MobileNotificationService;
use App\Services\MobileNotifications\MobileNotificationTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMobileNotification implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 20;
    private const MAX_ATTEMPTS = 3;
    public function __construct(public string $deliveryID) {}

    public function handle(MobileNotificationService $service, MobileNotificationTransport $transport): void
    {
        // Compare-and-set prevents overlapping/duplicate queued jobs from sending.
        $claimed = MobileNotificationDelivery::whereKey($this->deliveryID)->where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', now()))->update(['status' => 'processing']);
        if (! $claimed) { return; }
        $delivery = MobileNotificationDelivery::findOrFail($this->deliveryID);
        $device = MobileNotificationDevice::find($delivery->device_id);
        $user = $device?->activeOwner();
        if (! config('mobile_notifications.enabled') || ! $user || (int) $user->id !== (int) $delivery->user_id
            || $delivery->expires_at->isPast() || ! $service->allowed($user, $delivery->kind, $delivery->source_id)) {
            $delivery->update(['status' => 'expired']);
            return;
        }
        // Re-evaluate fresh account timezone/purpose after waiting. The scheduler
        // releases this same outbox row; no provider request has occurred yet.
        $notBefore = $service->notBefore($user, $delivery->kind, $delivery->source_id);
        if ($notBefore?->isFuture()) {
            // A changed morning window can move an unsent alert, but it cannot
            // extend the deadline of an alert already attempted at APNs.
            if ($delivery->attempts > 0 && ! $notBefore->lt($delivery->expires_at)) {
                $delivery->update(['status' => 'expired']);
                return;
            }
            $delivery->update(['status' => 'queued', 'not_before' => $notBefore,
                'expires_at' => $delivery->attempts > 0 ? $delivery->expires_at
                    : $notBefore->addMinutes(MobileNotificationService::DELIVERY_LIFETIME_MINUTES)]);
            return;
        }
        if ($delivery->attempts >= self::MAX_ATTEMPTS) {
            $delivery->update(['status' => 'retry_exhausted']);
            return;
        }
        // Reserve the attempt before I/O. A crash/timeout remains uncertain and
        // cannot be retried by a second worker or outbox reconciliation.
        $delivery->increment('attempts');
        $result = $transport->send($device, $delivery);
        if ($result['status'] === 'retryable') {
            $delay = (int) ($result['retry_after_seconds'] ?? 900);
            $next = now()->addSeconds($delay * (2 ** ($delivery->attempts - 1)));
            $retry = $delivery->attempts < self::MAX_ATTEMPTS && $next->lt($delivery->expires_at);
            $delivery->update(['status' => $retry ? 'queued' : 'retry_exhausted',
                'not_before' => $retry ? $next : $delivery->not_before, 'provider_status' => $result['provider_status']]);
        } else {
            $delivery->update(['status' => $result['status'], 'provider_status' => $result['provider_status']]);
        }
        if ($result['revoke']) {
            // A late invalid-token response must not revoke a newer registration,
            // even when iOS returned the same token under the same login.
            MobileNotificationDevice::whereKey($device->id)->where('token_hash', $device->token_hash)
                ->where('personal_access_token_id', $device->personal_access_token_id)
                ->where('sequence', $device->sequence)->update(['revoked_at' => now()]);
        }
    }
}
