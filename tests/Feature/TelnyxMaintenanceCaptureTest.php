<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureTelnyxDuringMaintenance;
use App\Models\Message;
use App\Support\TelnyxMaintenanceSpool;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TelnyxMaintenanceCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_UUID = '0B6F6A8E-6F0D-4C1E-9C43-0F6B8D1F2A11';

    private string $spool;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();
        $keys = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keys);
        $this->spool = sys_get_temp_dir().'/telnyx-capture-test-'.bin2hex(random_bytes(6)).'/spool';
        config([
            'services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keys)),
            'services.telnyx.webhook_tolerance_seconds' => 300,
            'services.telnyx.maintenance_capture.enabled' => true,
            'services.telnyx.maintenance_capture.spool_path' => $this->spool,
            'services.vapi.server_secret' => 'vapi-fixture-secret',
        ]);
    }

    protected function tearDown(): void
    {
        $this->app->maintenanceMode()->deactivate();
        File::deleteDirectory(dirname($this->spool));
        parent::tearDown();
    }

    public function test_signed_texts_on_every_text_route_are_saved_and_acknowledged_while_down(): void
    {
        $this->down();
        $routes = [
            '/api/webhooks/telnyx/messaging' => ['messaging', null],
            '/api/webhooks/telnyx/status' => ['status', null],
            '/api/webhooks/telnyx/shared-mms/'.self::SHARED_UUID => ['shared-mms', self::SHARED_UUID],
        ];
        $bodies = [];
        $before = time();
        foreach ($routes as $path => [$route]) {
            $bodies[$route] = $this->textBody('evt_'.$route);
            $this->postSigned($path, $bodies[$route])
                ->assertOk()
                ->assertExactJson(['status' => 'captured']);
        }

        $saved = $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS);
        $this->assertSame(['messaging', 'status', 'shared-mms'], array_column($saved, 'route'));
        $keys = array_column($routes, 1, 0);
        foreach ($saved as $entry) {
            $this->assertSame($keys[$entry['route']], $entry['route_key']);
            $this->assertSame($bodies[$entry['route']], $entry['body']);
            $this->assertSame(['telnyx-signature-ed25519', 'telnyx-timestamp'], array_keys($entry['headers']));
            $this->assertGreaterThanOrEqual($before, $entry['arrived_at']->getTimestamp());
            $this->assertLessThanOrEqual(time(), $entry['arrived_at']->getTimestamp());
        }
        $this->assertSame(0, Message::count());
    }

    public function test_capture_answers_before_any_database_work(): void
    {
        $this->down();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->postSigned('/api/webhooks/telnyx/messaging', $this->textBody('evt_no_db'))->assertOk();

        $this->assertSame(0, $queries);
    }

    public function test_capture_middleware_runs_before_maintenance_and_api_middleware(): void
    {
        $global = $this->app->make(HttpKernel::class)->getGlobalMiddleware();

        $this->assertSame(CaptureTelnyxDuringMaintenance::class, $global[0]);
    }

    public function test_nothing_changes_while_the_switch_is_off(): void
    {
        config(['services.telnyx.maintenance_capture.enabled' => false]);
        $this->down();

        $this->postSigned('/api/webhooks/telnyx/messaging', $this->textBody('evt_switch_off'))->assertStatus(503);

        $this->assertDirectoryDoesNotExist($this->spool);
    }

    public function test_nothing_is_captured_while_the_app_is_up(): void
    {
        $this->postSigned('/api/webhooks/telnyx/messaging', $this->textBody('evt_app_up', 'msg_app_up'))
            ->assertOk()
            ->assertJson(['status' => 'received']);

        $this->assertTrue(Message::where('provider_message_id', 'msg_app_up')->exists());
        $this->assertDirectoryDoesNotExist($this->spool);
    }

    public function test_unverified_requests_get_the_normal_maintenance_response_and_are_not_saved(): void
    {
        $this->down();
        $path = '/api/webhooks/telnyx/messaging';
        $body = $this->textBody('evt_rejected');
        $timestamp = (string) time();
        $signature = $this->sign($timestamp, $body);
        $cases = [
            'forged signature' => [$body, ['HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode(str_repeat('x', 64)), 'HTTP_TELNYX_TIMESTAMP' => $timestamp]],
            'stale timestamp' => [$body, ['HTTP_TELNYX_SIGNATURE_ED25519' => $this->sign((string) (time() - 400), $body), 'HTTP_TELNYX_TIMESTAMP' => (string) (time() - 400)]],
            'missing signature' => [$body, ['HTTP_TELNYX_TIMESTAMP' => $timestamp]],
            'missing timestamp' => [$body, ['HTTP_TELNYX_SIGNATURE_ED25519' => $signature]],
            'signed body without an event id' => ['{"data":{"event_type":"message.received"}}', null],
            'signed body that is not JSON' => ['not json', null],
        ];
        foreach ($cases as $case => [$caseBody, $headers]) {
            $headers ??= ['HTTP_TELNYX_SIGNATURE_ED25519' => $this->sign($timestamp, $caseBody), 'HTTP_TELNYX_TIMESTAMP' => $timestamp];
            $response = $this->call('POST', $path, [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], $caseBody);
            $this->assertSame(503, $response->getStatusCode(), $case);
        }
        $this->call('GET', $path)->assertStatus(503);

        $this->assertSame([], $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS));
    }

    public function test_oversized_texts_are_not_saved(): void
    {
        config(['services.telnyx.maintenance_capture.max_body_bytes' => 64]);
        $this->down();

        $this->postSigned('/api/webhooks/telnyx/messaging', $this->textBody('evt_oversized', text: str_repeat('a', 100)))
            ->assertStatus(503);

        $this->assertSame([], $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS));
    }

    public function test_other_paths_are_never_captured(): void
    {
        $this->down();
        foreach ([
            '/api/telnyx-ai/tools/lookup_client',
            '/api/webhooks/stripe',
            '/api/webhooks/telnyx/messaging/extra',
            '/api/webhooks/telnyx/shared-mms/not-a-uuid',
            '/webhooks/telnyx/messaging',
        ] as $path) {
            $this->assertSame(503, $this->postSigned($path, $this->textBody('evt_other'))->getStatusCode(), $path);
        }

        $this->assertSame([], $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS));
    }

    public function test_voice_and_vapi_events_are_saved_for_the_missed_call_list_but_still_get_503(): void
    {
        $this->down();
        $voice = json_encode(['data' => ['id' => 'evt_voice_1', 'event_type' => 'call.initiated',
            'payload' => ['call_session_id' => 'session-1', 'from' => '+12025550181', 'to' => '+18883426998']]]);
        $vapi = json_encode(['message' => ['type' => 'status-update', 'call' => ['id' => 'vapi-call-1',
            'customer' => ['number' => '+12025550182']], 'phoneNumber' => ['number' => '+18883426999']]]);

        $this->postSigned('/api/webhooks/telnyx/voice', $voice)->assertStatus(503);
        $this->call('POST', '/api/webhooks/vapi', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_VAPI_SECRET' => 'vapi-fixture-secret',
        ], $vapi)->assertStatus(503);
        $this->call('POST', '/api/webhooks/vapi', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_VAPI_SECRET' => 'wrong-secret',
        ], $vapi)->assertStatus(503);

        $calls = $this->spool()->entries(TelnyxMaintenanceSpool::CALLS);
        $this->assertSame(['voice', 'vapi'], array_column($calls, 'route'));
        $this->assertSame([$voice, $vapi], array_column($calls, 'body'));
        // The Vapi shared secret is never written to disk.
        $this->assertSame([], $calls[1]['headers']);
        $this->assertSame([], $this->spool()->entries(TelnyxMaintenanceSpool::TEXTS));
    }

    public function test_vapi_events_are_not_saved_when_no_secret_is_configured(): void
    {
        config(['services.vapi.server_secret' => '']);
        $this->down();

        $this->call('POST', '/api/webhooks/vapi', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            '{"message":{"type":"status-update"}}')->assertStatus(503);

        $this->assertSame([], $this->spool()->entries(TelnyxMaintenanceSpool::CALLS));
    }

    public function test_a_storage_failure_falls_back_to_the_normal_maintenance_response(): void
    {
        File::ensureDirectoryExists(dirname($this->spool));
        file_put_contents($this->spool, 'a file where the spool directory should be');
        $this->down();

        $this->postSigned('/api/webhooks/telnyx/messaging', $this->textBody('evt_storage_failure'))->assertStatus(503);
    }

    public function test_spool_files_are_encrypted_and_private_to_the_web_group(): void
    {
        $this->down();

        $this->postSigned('/api/webhooks/telnyx/messaging', $this->textBody('evt_private', text: 'Gate code is 4321'))->assertOk();

        foreach ([$this->spool, ...glob($this->spool.'/*', GLOB_ONLYDIR)] as $directory) {
            $this->assertSame(02770, fileperms($directory) & 07777, $directory);
        }
        $this->assertSame(['calls', 'rejected', 'replayed', 'texts'], array_map('basename', glob($this->spool.'/*', GLOB_ONLYDIR)));
        $files = glob($this->spool.'/texts/*');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/\A\d{8}T\d{12}Z-[a-f0-9]{16}\.spool\z/', basename($files[0]));
        $this->assertSame(0640, fileperms($files[0]) & 0777);
        $this->assertStringNotContainsString('Gate code', file_get_contents($files[0]));
        $this->assertStringNotContainsString('evt_private', file_get_contents($files[0]));
    }

    private function down(): void
    {
        $this->app->maintenanceMode()->activate(['retry' => 60, 'status' => 503]);
        $this->assertTrue($this->app->isDownForMaintenance());
    }

    private function spool(): TelnyxMaintenanceSpool
    {
        return TelnyxMaintenanceSpool::fromConfig();
    }

    private function textBody(string $eventId, string $messageId = 'msg_capture', string $text = 'On my way'): string
    {
        return json_encode(['data' => ['id' => $eventId, 'event_type' => 'message.received', 'payload' => [
            'id' => $messageId, 'from' => ['phone_number' => '+12025550177'],
            'to' => [['phone_number' => '+18883426998']], 'text' => $text,
        ]]]);
    }

    private function sign(string $timestamp, string $body): string
    {
        return base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, $this->secretKey));
    }

    private function postSigned(string $path, string $body)
    {
        $timestamp = (string) time();

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_TELNYX_SIGNATURE_ED25519' => $this->sign($timestamp, $body),
            'HTTP_TELNYX_TIMESTAMP' => $timestamp,
        ], $body);
    }
}
