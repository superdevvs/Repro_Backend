<?php

namespace Tests\Feature;

use App\Models\AryeoApiInstallation;
use App\Models\AryeoConnection;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Aryeo\Api\Requirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Producer replay (P2-11): record the Aryeo agent settings, pause it for the
 * freeze, then resume exactly the recorded settings. Freeze-time Aryeo API
 * webhooks are redelivered re-signed and must take effect once.
 */
class AryeoFreezeResumeTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private AryeoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->directory = sys_get_temp_dir().'/aryeo-freeze-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $this->connection = AryeoConnection::create(['name' => 'Studio Mac', 'company_key' => 'repro', 'client_ids' => [$client->id],
            'token_hash' => hash('sha256', 'synthetic-aryeo-token'), 'enabled' => true, 'processing_enabled' => true,
            'discover_all_clients' => true, 'delivery_shoot_ids' => [$shoot->id]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function flags(): array
    {
        return $this->connection->fresh()->only(['enabled', 'processing_enabled', 'discover_all_clients', 'delivery_shoot_ids', 'client_ids']);
    }

    public function test_record_pause_and_resume_restore_the_identical_connection_flags(): void
    {
        $before = $this->flags();
        $file = $this->directory.'/aryeo-state.json';

        $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--record-state' => $file, '--pause' => true])->assertSuccessful();
        $paused = $this->flags();
        $this->assertFalse($paused['processing_enabled']);
        $this->assertFalse($paused['discover_all_clients']);
        $this->assertSame($before['delivery_shoot_ids'], $paused['delivery_shoot_ids']);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($file) & 0777);
        }
        $this->assertStringNotContainsString($this->connection->token_hash, file_get_contents($file));

        $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--resume-processing' => $file])->assertSuccessful();
        $this->assertSame($before, $this->flags());
    }

    public function test_pause_without_a_new_state_file_is_refused(): void
    {
        $file = $this->directory.'/existing.json';
        file_put_contents($file, '{}');

        $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--pause' => true])->assertFailed();
        $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--record-state' => $file, '--pause' => true])->assertFailed();

        $this->assertTrue($this->connection->fresh()->processing_enabled);
        $this->assertSame('{}', file_get_contents($file));
    }

    public function test_resume_refuses_a_state_file_for_another_connection_or_an_edited_one(): void
    {
        $file = $this->directory.'/aryeo-state.json';
        $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--record-state' => $file, '--pause' => true])->assertSuccessful();
        $paused = $this->flags();
        $state = json_decode(file_get_contents($file), true);

        $other = $this->directory.'/other.json';
        file_put_contents($other, json_encode(['name' => 'Another Mac'] + $state));
        $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--resume-processing' => $other])->assertFailed();

        $edited = $this->directory.'/edited.json';
        $state['settings']['delivery_shoot_ids'] = [999999];
        file_put_contents($edited, json_encode($state));
        $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--resume-processing' => $edited])->assertFailed();

        $this->assertSame($paused, $this->flags());
    }

    /**
     * A resume only turns back on what the pause turned off. A credential
     * revoked, or a scope narrowed, during the freeze must not come back.
     */
    public function test_resume_refuses_a_connection_changed_during_the_freeze(): void
    {
        $changes = [
            'revoked' => fn () => $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--revoke' => true])->assertSuccessful(),
            'narrowed' => fn () => $this->connection->fresh()->forceFill(['delivery_shoot_ids' => []])->save(),
        ];
        $original = $this->flags();
        foreach ($changes as $name => $change) {
            $this->connection->fresh()->forceFill($original)->save();
            $file = $this->directory.'/aryeo-'.$name.'.json';
            $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--record-state' => $file, '--pause' => true])->assertSuccessful();
            $change();
            $changed = $this->flags();

            $this->artisan('aryeo:connection', ['name' => 'Studio Mac', '--resume-processing' => $file])->assertFailed();
            $this->assertSame($changed, $this->flags(), $name.': nothing may change');
        }
    }

    public function test_api_webhook_redelivered_with_a_fresh_signature_is_recorded_once(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $installation = AryeoApiInstallation::create(['environment' => 'sandbox', 'company_id' => Str::uuid(), 'name' => 'Test',
            'status' => 'connected', 'paused' => false, 'enrolled' => true, 'allowed_client_ids' => [$client->id], 'allowed_shoot_ids' => [],
            'requirements' => Requirements::defaults(), 'capabilities' => [], 'credentials' => ['access_token' => 'test-private-token'],
            'token_expires_at' => now()->addMinutes(15)]);
        config(['aryeo_api.webhook_secrets' => [$installation->id => 'test-signing-secret'], 'aryeo_api.webhook_source' => 'https://app.aryeo.com']);
        $body = json_encode(['specversion' => '1.0', 'id' => 'event-freeze-1', 'source' => 'https://app.aryeo.com',
            'type' => 'delivery.created', 'data' => ['id' => 'legacy-id']]);
        // The controller checks freshness against the wall clock, as Aryeo signs with its own.
        $deliver = function (int $age) use ($body, $installation) {
            $timestamp = (string) (time() - $age);

            return $this->call('POST', '/api/integrations/aryeo/webhooks/'.$installation->id, [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_TIMESTAMP' => $timestamp,
                'HTTP_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'test-signing-secret'),
                'HTTP_SOURCE' => 'https://app.aryeo.com', 'HTTP_IDEMPOTENCY_KEY' => 'event-freeze-1',
            ], $body);
        };

        config(['producer_replay.ingress_hold' => true]);
        $deliver(0)->assertStatus(503)->assertHeader('Retry-After');
        config(['producer_replay.ingress_hold' => false]);
        $deliver(1800)->assertUnauthorized(); // a stale signature from the freeze is never accepted
        $deliver(120)->assertStatus(202);
        $deliver(0)->assertStatus(202); // a second, freshly signed redelivery

        $this->assertSame(1, DB::table('aryeo_api_webhooks')->where('event_id', 'event-freeze-1')->count());
        Queue::assertPushed(\App\Jobs\ReconcileAryeoWebhook::class, 1);
    }
}
