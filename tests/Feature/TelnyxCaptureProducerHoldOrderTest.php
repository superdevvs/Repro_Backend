<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureTelnyxDuringMaintenance;
use App\Http\Middleware\HoldProducerIngress;
use App\Models\Message;
use App\Support\TelnyxMaintenanceSpool;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Both middlewares are prepended to the global stack and both want to be
 * first. The producer hold (PRODUCER_INGRESS_HOLD) answers its listed producer
 * paths with a retryable 503; the Telnyx capture saves signed texts while the
 * app is down and telnyx:replay-spool later hands them to the normal handler.
 *
 * The hold runs first. It reads only config and the path (no database, no
 * cache), so the capture still runs before the maintenance check and before
 * anything that touches either. Telnyx is not on the default hold list, so a
 * text is captured (or handled live) whatever the hold says. If an operator
 * does list a Telnyx path, the hold wins in maintenance too: otherwise the
 * capture would answer 200 and the deploy's replay, which runs right after
 * `up` while the hold is still on, would write the text the hold was set to
 * keep out.
 */
class TelnyxCaptureProducerHoldOrderTest extends TestCase
{
    use RefreshDatabase;

    private string $spool;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();
        $keys = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keys);
        $this->spool = sys_get_temp_dir().'/telnyx-hold-order-test-'.bin2hex(random_bytes(6)).'/spool';
        config([
            'services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keys)),
            'services.telnyx.webhook_tolerance_seconds' => 300,
            'services.telnyx.maintenance_capture.enabled' => true,
            'services.telnyx.maintenance_capture.spool_path' => $this->spool,
            'services.telnyx.maintenance_capture.replay_group' => '',
            'producer_replay.ingress_hold' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->app->maintenanceMode()->deactivate();
        File::deleteDirectory(dirname($this->spool));
        parent::tearDown();
    }

    public function test_the_producer_hold_runs_first_then_the_capture_both_before_the_maintenance_check(): void
    {
        $global = $this->app->make(HttpKernel::class)->getGlobalMiddleware();

        $this->assertSame([HoldProducerIngress::class, CaptureTelnyxDuringMaintenance::class], array_slice($global, 0, 2));
        $maintenance = array_search(PreventRequestsDuringMaintenance::class, $global, true);
        $this->assertIsInt($maintenance, 'The maintenance check must be in the global stack.');
        $this->assertGreaterThan(1, $maintenance);
    }

    public function test_maintenance_on_hold_off_a_text_is_captured_then_replayed_through_the_normal_handler(): void
    {
        $this->down();

        $this->postText('evt_a', 'msg_a')->assertOk()->assertExactJson(['status' => 'captured']);

        $this->assertCount(1, $this->spoolEntries());
        $this->assertSame(0, Message::count());
        $this->upAndReplay();
        $this->assertTrue(Message::where('provider_message_id', 'msg_a')->exists());
    }

    public function test_hold_on_maintenance_off_a_text_reaches_the_normal_handler(): void
    {
        config(['producer_replay.ingress_hold' => true]);
        $this->assertProducerHeld();

        $this->postText('evt_b', 'msg_b')->assertOk()->assertJson(['status' => 'received']);

        $this->assertTrue(Message::where('provider_message_id', 'msg_b')->exists());
        $this->assertDirectoryDoesNotExist($this->spool);
    }

    public function test_hold_and_maintenance_on_a_text_is_captured_then_replayed_while_the_hold_stays_on(): void
    {
        config(['producer_replay.ingress_hold' => true]);
        $this->down();
        // The order rests on the hold reading only config and the path (review C7): with the hold on and
        // the app down, neither a held producer request nor a text on its way to the capture may reach the
        // database, or the cache (L2), before the capture has answered. Every cache event counts: CacheHit,
        // CacheMissed, KeyWritten, RetrievingKey and their peers in Illuminate\Cache\Events.
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $cacheEvents = [];
        Event::listen('Illuminate\Cache\Events\*', function (string $event) use (&$cacheEvents) {
            $cacheEvents[] = class_basename($event);
        });
        $this->assertProducerHeld();

        $this->postText('evt_c', 'msg_c')->assertOk()->assertExactJson(['status' => 'captured']);

        $this->assertSame([], $queries, 'A query ran before the capture answered');
        $this->assertSame([], $cacheEvents, 'The cache was used before the capture answered');
        // The recorder does see the cache, so the empty list above is not a store that dispatches nothing.
        Cache::get('telnyx-hold-order-probe');
        $this->assertSame(['RetrievingKey', 'CacheMissed'], $cacheEvents);
        $this->assertCount(1, $this->spoolEntries());
        $this->assertSame(0, Message::count());
        $this->upAndReplay();
        $this->assertTrue(config('producer_replay.ingress_hold'));
        $this->assertTrue(Message::where('provider_message_id', 'msg_c')->exists());
    }

    public function test_a_hold_that_lists_a_telnyx_path_keeps_the_text_out_with_and_without_maintenance(): void
    {
        config([
            'producer_replay.ingress_hold' => true,
            'producer_replay.held_paths' => [...config('producer_replay.held_paths'), 'api/webhooks/telnyx/*'],
        ]);

        $this->assertHeldResponse($this->postText('evt_held_up', 'msg_held_up'));
        $this->down();
        $this->assertHeldResponse($this->postText('evt_held_down', 'msg_held_down'));

        $this->assertDirectoryDoesNotExist($this->spool);
        $this->assertSame(0, Message::count());
    }

    private function assertProducerHeld(): void
    {
        $this->assertHeldResponse($this->call('POST', '/api/webhooks/resend', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}'));
    }

    private function assertHeldResponse(TestResponse $response): void
    {
        $response->assertStatus(503);
        $this->assertSame('producer_ingress_held', $response->json('error'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    private function down(): void
    {
        $this->app->maintenanceMode()->activate(['retry' => 60, 'status' => 503]);
        $this->assertTrue($this->app->isDownForMaintenance());
    }

    private function upAndReplay(): void
    {
        $this->app->maintenanceMode()->deactivate();
        $previousUmask = umask();
        try {
            $this->artisan('telnyx:replay-spool')
                ->expectsOutputToContain('replayed 1, duplicates 0, set aside 0, failed 0')
                ->assertSuccessful();
        } finally {
            umask($previousUmask);
        }
    }

    /** @return list<array<string, mixed>> */
    private function spoolEntries(): array
    {
        return TelnyxMaintenanceSpool::fromConfig()->entries(TelnyxMaintenanceSpool::TEXTS);
    }

    private function postText(string $eventId, string $messageId): TestResponse
    {
        $body = json_encode(['data' => ['id' => $eventId, 'event_type' => 'message.received', 'payload' => [
            'id' => $messageId, 'from' => ['phone_number' => '+12025550177'],
            'to' => [['phone_number' => '+18883426998']], 'text' => 'On my way',
        ]]]);
        $timestamp = (string) time();

        return $this->call('POST', '/api/webhooks/telnyx/messaging', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, $this->secretKey)),
            'HTTP_TELNYX_TIMESTAMP' => $timestamp,
        ], $body);
    }
}
