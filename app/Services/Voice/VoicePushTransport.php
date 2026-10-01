<?php

namespace App\Services\Voice;

use App\Models\VoicePushSubscription;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

class VoicePushTransport
{
    public function send(VoicePushSubscription $subscription, array $payload, int $ttl): array
    {
        $push = new WebPush(['VAPID' => [
            'subject' => config('voice_push.subject'), 'publicKey' => config('voice_push.public_key'), 'privateKey' => config('voice_push.private_key'),
        ]], [], $this->client());
        $report = $push->sendOneNotification(Subscription::create([
            'endpoint' => $subscription->endpoint, 'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token, 'contentEncoding' => 'aes128gcm',
        ]), json_encode($payload, JSON_THROW_ON_ERROR), [
            'TTL' => max(0, min($ttl, 120)), 'urgency' => 'high',
            'topic' => substr(hash('sha256', $payload['offer_id']), 0, 32),
        ]);

        // Vendor acceptance is deliberately not described as device delivery.
        return ['accepted' => $report->isSuccess(), 'expired' => $report->isSubscriptionExpired(), 'status' => $report->getResponse()?->getStatusCode()];
    }

    protected function client(): ClientInterface
    {
        return new Client(['timeout' => 10, 'connect_timeout' => 5, 'allow_redirects' => false]);
    }
}
