<?php

namespace Tests\Unit;

use App\Models\VoicePushSubscription;
use App\Services\Voice\VoicePushTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;
use Tests\TestCase;

class VoicePushTransportTest extends TestCase
{
    public function test_real_rfc_encryption_and_vapid_signing_use_bounded_ttl_without_external_network(): void
    {
        $keys = VAPID::createVapidKeys();
        $browserKeys = VAPID::createVapidKeys();
        config(['voice_push.subject' => 'mailto:test@example.test', 'voice_push.public_key' => $keys['publicKey'], 'voice_push.private_key' => $keys['privateKey']]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201)]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $transport = new class($client) extends VoicePushTransport
        {
            public function __construct(private ClientInterface $http) {}

            protected function client(): ClientInterface
            {
                return $this->http;
            }
        };
        $subscription = new VoicePushSubscription(['endpoint' => 'https://fcm.googleapis.com/fcm/send/local-test', 'public_key' => $browserKeys['publicKey'], 'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]);
        $result = $transport->send($subscription, ['offer_id' => '00000000-0000-4000-8000-000000000001', 'event' => 'incoming', 'private_marker' => 'no-plaintext-on-wire'], 45);
        $this->assertSame(['accepted' => true, 'expired' => false, 'status' => 201], $result);
        $request = $history[0]['request'];
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertSame('45', $request->getHeaderLine('TTL'));
        $this->assertSame('high', $request->getHeaderLine('Urgency'));
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('no-plaintext-on-wire', (string) $request->getBody());
        $this->assertGreaterThan(100, $request->getBody()->getSize());
    }
}
