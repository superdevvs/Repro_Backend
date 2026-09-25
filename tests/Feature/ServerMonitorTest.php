<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\ServerMonitorServiceProvider;
use App\Services\ServerMonitor\Telemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\IsolatedSecurityTestCase;

class ServerMonitorTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;
    private string $keyFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keyFile = tempnam(sys_get_temp_dir(), 'monitor-key-');
        file_put_contents($this->keyFile, bin2hex(random_bytes(32)));
        config(['server-monitor.enabled' => true, 'server-monitor.key_file' => $this->keyFile]);
    }

    protected function tearDown(): void
    {
        @unlink($this->keyFile);
        parent::tearDown();
    }

    private function monitorSession(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('monitor-test');
        $response = $this->withToken($token->plainTextToken)->postJson('/api/admin/system-overview/server/session')->assertOk();
        return [$response->json('token'), $token];
    }

    public function test_primary_and_secondary_superadmins_can_get_short_lived_tickets(): void
    {
        foreach ([User::factory()->superAdmin()->create(), User::factory()->admin()->create(['secondary_roles' => ['super_admin']])] as $user) {
            [$ticket] = $this->monitorSession($user);
            $this->withToken($ticket)->postJson('/api/admin/system-overview/server/validate')->assertOk()->assertJsonPath('id', (string) $user->id);
        }
    }

    public function test_ordinary_admin_and_impersonation_are_denied(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('test');
        $this->withToken($token->plainTextToken)->postJson('/api/admin/system-overview/server/session')->assertForbidden();
        $super = User::factory()->superAdmin()->create();
        $superToken = $super->createToken('test');
        $this->withToken($superToken->plainTextToken)->withHeader('X-Impersonate-User-Id', (string) $admin->id)->postJson('/api/admin/system-overview/server/session')->assertForbidden();
    }

    public function test_revoking_original_access_token_revokes_monitor_ticket(): void
    {
        [$ticket, $token] = $this->monitorSession(User::factory()->superAdmin()->create());
        $token->accessToken->delete();
        $this->withToken($ticket)->postJson('/api/admin/system-overview/server/validate')->assertUnauthorized();
    }

    public function test_role_change_and_expiry_revoke_monitor_access(): void
    {
        $user = User::factory()->superAdmin()->create();
        [$ticket] = $this->monitorSession($user);
        $user->update(['role' => 'admin', 'secondary_roles' => []]);
        $this->withToken($ticket)->postJson('/api/admin/system-overview/server/validate')->assertForbidden();
        $user->update(['role' => 'superadmin']);
        $this->travel(61)->seconds();
        $this->withToken($ticket)->postJson('/api/admin/system-overview/server/validate')->assertUnauthorized();
    }

    public function test_tampering_and_unconfigured_monitor_fail_closed(): void
    {
        [$ticket] = $this->monitorSession(User::factory()->superAdmin()->create());
        $this->withToken($ticket.'x')->postJson('/api/admin/system-overview/server/validate')->assertUnauthorized();
        config(['server-monitor.enabled' => false]);
        $this->withToken($ticket)->postJson('/api/admin/system-overview/server/validate')->assertStatus(503);
    }

    public function test_telemetry_transport_failure_does_not_fail_request(): void
    {
        $this->app->instance(Telemetry::class, new class extends Telemetry {
            public function emit(string $kind, array $fields = []): void { throw new \RuntimeException('collector stopped'); }
        });
        (new ServerMonitorServiceProvider($this->app))->boot();
        Route::get('/monitor-fail-open-test', fn () => response()->json(['ok' => true]));
        $this->getJson('/monitor-fail-open-test')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_request_instrumentation_uses_route_template_not_customer_payload(): void
    {
        $captured = new class extends Telemetry {
            public array $events = [];
            public function emit(string $kind, array $fields = []): void { $this->events[] = [$kind, $fields]; }
        };
        $this->app->instance(Telemetry::class, $captured);
        (new ServerMonitorServiceProvider($this->app))->boot();
        Route::post('/monitor-fixture/{id}', fn () => response()->json(['ok' => true]));
        $this->postJson('/monitor-fixture/secret-customer?token=private', ['password' => 'canary-secret', 'email' => 'private@example.test'])->assertOk();
        $json = json_encode($captured->events, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('monitor-fixture/{id}', $json);
        $requestEvents = array_values(array_filter($captured->events, fn ($event) => $event[0] === 'request'));
        $this->assertTrue(\Illuminate\Support\Str::isUuid($requestEvents[0][1]['traceId']));
        foreach (['secret-customer', 'canary-secret', 'private@example.test', 'token=private'] as $secret) $this->assertStringNotContainsString($secret, $json);
    }
    public function test_background_schedule_start_is_not_mistaken_for_completion(): void
    {
        $captured = new class extends Telemetry {
            public array $events = [];
            public function emit(string $kind, array $fields = []): void { $this->events[] = [$kind, $fields]; }
        };
        $this->app->instance(Telemetry::class, $captured);
        (new ServerMonitorServiceProvider($this->app))->boot();
        $task = app(\Illuminate\Console\Scheduling\Schedule::class)->command('inspire')->runInBackground();
        event(new \Illuminate\Console\Events\ScheduledTaskStarting($task));
        event(new \Illuminate\Console\Events\ScheduledTaskFinished($task, 0.01));
        $this->assertCount(1, $captured->events);
        $this->assertSame('started', $captured->events[0][1]['outcome']);
        $task->exitCode = 1;
        event(new \Illuminate\Console\Events\ScheduledBackgroundTaskFinished($task));
        $this->assertSame('failed', $captured->events[1][1]['outcome']);
        $this->assertNull($captured->events[1][1]['durationMs']);
    }

}
