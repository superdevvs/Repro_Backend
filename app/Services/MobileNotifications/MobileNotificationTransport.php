<?php

namespace App\Services\MobileNotifications;

use App\Models\MobileNotificationDelivery;
use App\Models\MobileNotificationDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

class MobileNotificationTransport
{
    public function ready(): bool
    {
        $path = config('mobile_notifications.private_key_path');
        return config('mobile_notifications.enabled')
            && in_array(config('mobile_notifications.environment'), ['sandbox', 'production'], true)
            && config('mobile_notifications.topic') === 'com.reprophotos.dashboard.ios'
            && preg_match('/\A[A-Z0-9]{10}\z/', (string) config('mobile_notifications.team_id'))
            && preg_match('/\A[A-Z0-9]{10}\z/', (string) config('mobile_notifications.key_id'))
            && is_string($path) && is_file($path) && is_readable($path);
    }

    protected function providerToken(): string
    {
        $path = config('mobile_notifications.private_key_path');
        $cacheKey = 'mobile-apns-jwt:'.hash('sha256', implode('|', [config('mobile_notifications.key_id'), config('mobile_notifications.team_id'), $path, filemtime($path)]));
        return Cache::remember($cacheKey, now()->addMinutes(40), function () use ($path) {
            $jwk = JWKFactory::createFromKey(file_get_contents($path));
            $jws = (new JWSBuilder(new AlgorithmManager([new ES256])))->create()
                ->withPayload(json_encode(['iss' => config('mobile_notifications.team_id'), 'iat' => time()], JSON_THROW_ON_ERROR))
                ->addSignature($jwk, ['alg' => 'ES256', 'kid' => config('mobile_notifications.key_id')])->build();
            return (new CompactSerializer)->serialize($jws, 0);
        });
    }

    public function payload(MobileNotificationDelivery $delivery): array
    {
        return ['aps' => ['alert' => ['title' => 'R/E Pro', 'body' => 'You have a new update.'], 'sound' => 'default'],
            'repro' => ['version' => 1, 'event_id' => $delivery->id, 'user_id' => (string) $delivery->user_id,
                'destination' => match ($delivery->kind) {
                    'voicemail' => 'calls',
                    'shoot_activity', 'account_activity' => 'notifications',
                    default => 'messages',
                }]];
    }

    /** APNs acceptance is not evidence of handset delivery. No payload/token/error-body logging. */
    public function send(MobileNotificationDevice $device, MobileNotificationDelivery $delivery): array
    {
        if (! $this->ready() || $device->environment !== config('mobile_notifications.environment')) {
            return ['status' => 'unavailable', 'provider_status' => null, 'revoke' => false];
        }
        try {
            $host = $device->environment === 'sandbox' ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
            $response = Http::withToken($this->providerToken())->withHeaders([
                'apns-topic' => config('mobile_notifications.topic'), 'apns-push-type' => 'alert',
                'apns-priority' => '10', 'apns-id' => $delivery->id,
                'apns-collapse-id' => $delivery->id, 'apns-expiration' => (string) $delivery->expires_at->timestamp,
            ])->withOptions(['version' => 2.0, 'allow_redirects' => false])->connectTimeout(5)->timeout(10)
                ->post($host.'/3/device/'.$device->token, $this->payload($delivery));
            $retryDelay = match ($response->status()) {
                500, 503 => 900, // Apple requires at least fifteen minutes for 5XX responses.
                429 => match ($response->json('reason')) {
                    'TooManyRequests' => 60,
                    'TooManyProviderTokenUpdates' => 1200,
                    default => null,
                },
                default => null,
            };
            return ['status' => match ($response->status()) {
                    200 => 'accepted',
                    default => $retryDelay === null ? 'rejected' : 'retryable',
                }, 'provider_status' => $response->status(),
                'retry_after_seconds' => $retryDelay,
                'revoke' => $response->status() === 410 || ($response->status() === 400
                    && in_array($response->json('reason'), ['BadDeviceToken', 'DeviceTokenNotForTopic'], true))];
        } catch (\Throwable) {
            // A timed-out request may have arrived. Do not replay it as a new alert.
            return ['status' => 'uncertain', 'provider_status' => null, 'revoke' => false];
        }
    }
}
