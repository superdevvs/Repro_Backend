<?php

namespace App\Services\Voice;

use App\Jobs\SendVoicePush;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoicePushDelivery;
use App\Models\VoicePushSubscription;
use App\Support\LockedWrite;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use RuntimeException;
use Throwable;

class VoicePushService
{
    public function __construct(private readonly VoiceBrowserSessionService $sessions, private readonly VoicePushTransport $transport) {}

    public function configuration(): array
    {
        $blockers = [];
        if (! config('voice_push.enabled')) {
            $blockers[] = 'Device call notifications have not been enabled by the administrator.';
        }
        try {
            if (! preg_match('/^(mailto:[^\s@]+@[^\s@]+|https:\/\/[^\s]+)$/', (string) config('voice_push.subject'))) {
                throw new RuntimeException('Invalid subject');
            }
            VAPID::validate(['subject' => config('voice_push.subject'), 'publicKey' => config('voice_push.public_key'), 'privateKey' => config('voice_push.private_key')]);
        } catch (Throwable) {
            $blockers[] = 'The notification signing keys are missing or invalid.';
        }
        if (! str_starts_with((string) config('app.url'), 'https://') && ! app()->environment('testing', 'local')) {
            $blockers[] = 'Device notifications require a secure HTTPS dashboard.';
        }
        if (config('voice_push.connection') === 'sync') {
            $blockers[] = 'A background notification queue must be configured.';
        }

        return ['configured' => $blockers === [], 'blockers' => $blockers, 'public_key' => $blockers === [] ? config('voice_push.public_key') : null];
    }

    public function settings(User $user): array
    {
        return [...$this->configuration(), 'preferences' => ['incoming_calls' => $this->enabledFor($user->id)],
            'devices' => VoicePushSubscription::where('user_id', $user->id)->whereNull('revoked_at')->latest()->get()->map(fn ($s) => [
                'id' => $s->id, 'label' => $s->label, 'last_success_at' => $s->last_success_at?->toIso8601String(),
                'last_error' => $s->last_error, 'created_at' => $s->created_at->toIso8601String(),
            ])->all()];
    }

    public function enabledFor(int $userId): bool
    {
        return (bool) (DB::table('voice_push_preferences')->where('user_id', $userId)->value('incoming_calls') ?? true);
    }

    public function endpointAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        foreach (config('voice_push.endpoint_hosts', []) as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)) && $host !== substr($allowed, 2))) {
                return true;
            }
        }

        return false;
    }

    public function subscribe(User $user, array $data): array
    {
        $existing = VoicePushSubscription::where('endpoint_hash', hash('sha256', $data['endpoint']))->first();
        abort_if((! $existing || $existing->user_id !== $user->id || $existing->revoked_at) && VoicePushSubscription::where('user_id', $user->id)->whereNull('revoked_at')->count() >= 20, 422, 'Remove an old call alert device before adding another.');
        abort_unless($this->configuration()['configured'], 409, 'Device notifications are not configured.');
        abort_unless($this->endpointAllowed($data['endpoint']), 422, 'This browser notification service is not supported.');
        $public = $this->decodeKey($data['keys']['p256dh']);
        $auth = $this->decodeKey($data['keys']['auth']);
        abort_unless(strlen($public) === 65 && ord($public[0]) === 4 && strlen($auth) === 16, 422, 'The browser notification keys are invalid.');
        $token = Str::random(64);
        $subscription = LockedWrite::run(fn () => VoicePushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $user->id, 'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            'label' => $data['label'], 'scope' => (string) Str::uuid(), 'revoke_hash' => hash('sha256', $token), 'revoked_at' => null, 'last_error' => null,
        ]), 'subscribe voice push');

        return ['id' => $subscription->id, 'scope' => $subscription->scope, 'revoke_token' => $token];
    }

    public function revoke(VoicePushSubscription $subscription): void
    {
        $subscription->update(['revoked_at' => now()]);
        VoicePushDelivery::where('subscription_id', $subscription->id)->where('status', 'queued')->update(['status' => 'cancelled']);
    }

    public function notifyIncoming(VoiceCall $call, string $offerId, array $eligibleUserIds, CarbonInterface $expiresAt): void
    {
        if (! $this->configuration()['configured']) {
            return;
        }
        VoicePushSubscription::whereIn('user_id', $eligibleUserIds)->whereNull('revoked_at')->get()->each(function ($subscription) use ($call, $offerId, $expiresAt) {
            if ($this->eligible($subscription) && $this->enabledFor($subscription->user_id)) {
                $this->queue($subscription, $offerId, 'incoming', $expiresAt, $call->id);
            }
        });
    }

    public function closeOffer(string $offerId, string $reason = 'claimed'): void
    {
        // Persist closure before enqueuing it so a delayed incoming job cannot ring again.
        $deliveries = VoicePushDelivery::where('offer_id', $offerId)->where('event', 'incoming')->get();
        foreach ($deliveries as $delivery) {
            $subscription = $delivery->subscription;
            if ($subscription && ! $subscription->revoked_at) {
                $this->queue($subscription, $offerId, 'closed', now()->addMinutes(2), $delivery->voice_call_id);
            }
        }
    }

    public function test(VoicePushSubscription $subscription): VoicePushDelivery
    {
        abort_unless($this->configuration()['configured'], 409, 'Device notifications are not configured.');

        return $this->queue($subscription, (string) Str::uuid(), 'test', now()->addMinute());
    }

    private function queue(VoicePushSubscription $subscription, string $offerId, string $event, CarbonInterface $expiresAt, ?int $callId = null): VoicePushDelivery
    {
        $delivery = LockedWrite::run(fn () => VoicePushDelivery::firstOrCreate(['subscription_id' => $subscription->id, 'offer_id' => $offerId, 'event' => $event], [
            'voice_call_id' => $callId, 'scope' => $subscription->scope, 'expires_at' => $expiresAt, 'status' => 'queued',
        ]), 'queue voice push');
        if ($delivery->wasRecentlyCreated) {
            SendVoicePush::dispatch($delivery->id);
        }

        return $delivery;
    }

    public function deliver(string $id): void
    {
        // Duplicate/retried queue jobs must not send concurrently. The shared cache
        // lock outlives the bounded HTTP request and is released on exceptions.
        Cache::lock('voice-push-delivery:'.$id, 30)->get(fn () => $this->deliverOnce($id));
    }

    private function deliverOnce(string $id): void
    {
        $delivery = VoicePushDelivery::find($id);
        if (! $delivery || ! in_array($delivery->status, ['queued', 'retrying'], true)) {
            return;
        }
        $subscription = $delivery->subscription;
        if (! $subscription || $subscription->revoked_at || $subscription->scope !== $delivery->scope || $delivery->expires_at->isPast()) {
            $delivery->update(['status' => 'expired']);

            return;
        }
        if (! $this->configuration()['configured']) {
            $delivery->update(['status' => 'blocked', 'error_code' => 'configuration_unavailable']);

            return;
        }
        if (! $this->endpointAllowed($subscription->endpoint)) {
            $this->revoke($subscription);
            $delivery->update(['status' => 'blocked', 'error_code' => 'endpoint_not_allowed']);

            return;
        }
        if (! $this->eligible($subscription)) {
            $this->revoke($subscription);
            $delivery->update(['status' => 'cancelled']);

            return;
        }
        if ($delivery->event === 'incoming') {
            $offer = DB::table('voice_incoming_offers')->where('id', $delivery->offer_id)->first();
            $eligibleIds = $offer ? json_decode($offer->eligible_user_ids ?? '[]', true) : [];
            $available = DB::table('voice_staff_phones')->where('user_id', $subscription->user_id)->value('available') ?? true;
            if (! $available || ! app(VoiceIncomingOfferService::class)->eligible($subscription->user, $delivery->voice_call_id) || ! $this->enabledFor($subscription->user_id) || ! $offer || $offer->status !== 'waiting' || \Carbon\Carbon::parse($offer->expires_at)->isPast() || ! in_array($subscription->user_id, $eligibleIds, true)
                || VoicePushDelivery::where('offer_id', $delivery->offer_id)->where('event', 'closed')->exists()) {
                $delivery->update(['status' => 'cancelled']);

                return;
            }
        }
        $payload = ['version' => 1, 'event' => $delivery->event, 'offer_id' => $delivery->offer_id, 'scope' => $subscription->scope,
            'expires_at' => $delivery->expires_at->toIso8601String(), 'url' => $delivery->event === 'incoming' ? '/calls?offer='.$delivery->offer_id : '/calls/settings'];
        try {
            $result = $this->transport->send($subscription, $payload, max(0, (int) now()->diffInSeconds($delivery->expires_at)));
        } catch (Throwable) {
            $delivery->update(['status' => 'retrying', 'error_code' => 'push_transport_failed']);
            $subscription->update(['last_error' => 'push_transport_failed']);
            // Never place endpoint/key/provider exceptions in queue failure logs.
            throw new RuntimeException('The call notification transport failed.');
        }
        $delivery->update(['status' => $result['accepted'] ? 'accepted' : 'rejected', 'http_status' => $result['status'], 'error_code' => $result['accepted'] ? null : 'push_service_rejected']);
        if ($result['accepted']) {
            $subscription->update(['last_success_at' => now(), 'last_error' => null]);
        } elseif ($result['expired']) {
            $this->revoke($subscription);
        } else {
            $subscription->update(['last_error' => 'push_service_rejected']);
            if (($result['status'] ?? 0) === 429 || ($result['status'] ?? 0) >= 500) {
                $delivery->update(['status' => 'retrying']);
                throw new RuntimeException('The call notification service is temporarily unavailable.');
            }
        }
    }

    private function eligible(VoicePushSubscription $subscription): bool
    {
        return $subscription->user && $this->sessions->canOperate($subscription->user);
    }

    private function decodeKey(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
