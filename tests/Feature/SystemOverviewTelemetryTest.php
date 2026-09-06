<?php

namespace Tests\Feature;

use App\Models\SystemOverviewRequestTrace;
use App\Models\SystemOverviewRouteEvent;
use App\Models\SystemOverviewErrorEvent;
use App\Models\SystemOverviewSession;
use App\Models\User;
use App\Events\SystemOverviewActivityUpdated;
use App\Services\SystemOverviewTelemetryService;
use App\Services\RequestCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatedSecurityTestCase;

class SystemOverviewTelemetryTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_superadmin_can_fetch_system_overview_snapshot(): void
    {
        $superadmin = User::factory()->superAdmin()->create([
            'name' => 'Overview Admin',
        ]);

        Sanctum::actingAs($superadmin);

        $this->postJson('/api/system-telemetry/events', [
            'events' => [
                [
                    'type' => 'session_start',
                    'routePath' => '/settings?tab=overview',
                    'pageKey' => 'settings',
                    'actionName' => 'session started',
                    'payload' => [
                        'domain' => 'Settings',
                    ],
                ],
            ],
        ], [
            'X-System-Session-Id' => 'session-superadmin-overview',
            'X-System-Current-Route' => '/settings?tab=overview',
            'X-Trace-Id' => 'trace-superadmin-overview',
        ])->assertOk()
            ->assertJsonPath('telemetryAvailable', true);

        $response = $this->getJson('/api/admin/system-overview/snapshot');

        $response->assertOk();
        $response->assertJsonPath('telemetryAvailable', true);
        $response->assertJsonPath('data.liveUsers.0.userName', 'Overview Admin');
        $response->assertJsonPath('data.liveUsers.0.currentRoute', '/settings');
        $response->assertJsonStructure([
            'data' => [
                'generatedAt',
                'stats' => [
                    'activeSessions',
                    'requestsPerMinute',
                    'errorCount24h',
                    'slowRouteCount',
                    'integrationFailures24h',
                ],
                'domainStats',
                'liveUsers',
                'routeMetrics',
                'recentTraces',
                'recentErrors',
            ],
        ]);
    }

    public function test_non_superadmin_cannot_fetch_system_overview_snapshot(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/system-overview/snapshot')->assertForbidden();
    }

    public function test_request_traces_and_frontend_events_are_recorded_with_redacted_payloads(): void
    {
        // Exercise telemetry independently of profile credential mutation rules.
        Route::middleware(['api', 'auth:sanctum'])->put('/api/telemetry-profile-fixture', fn () => response()->json(['success' => true]));
        $superadmin = User::factory()->superAdmin()->create([
            'bio' => 'Initial bio',
        ]);

        Sanctum::actingAs($superadmin);

        $traceId = 'trace-profile-update';
        $sessionId = 'session-profile-update';

        $profileResponse = $this->putJson('/api/telemetry-profile-fixture', [
            'bio' => 'Updated from overview telemetry test',
        ], [
            'X-Trace-Id' => $traceId,
            'X-System-Session-Id' => $sessionId,
            'X-System-Current-Route' => '/settings?tab=overview',
        ])->assertOk();

        $traceId = $profileResponse->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($traceId));
        $sessionKey = hash('sha256', $superadmin->id.':'.$sessionId);

        $this->assertDatabaseHas('system_overview_request_traces', [
            'trace_id' => $traceId,
            'session_key' => $sessionKey,
            'path' => '/api/telemetry-profile-fixture',
            'current_route' => '/settings',
            'status_code' => 200,
        ]);

        $storedTrace = SystemOverviewRequestTrace::query()
            ->where('trace_id', $traceId)
            ->firstOrFail();

        $this->assertSame(['field_0'], $storedTrace->request_payload_summary['topLevelKeys'] ?? []);
        $this->assertSame('[STRING]', $storedTrace->request_payload_summary['sanitized']['field_0'] ?? null);

        $eventResponse = $this->postJson('/api/system-telemetry/events', [
            'events' => [
                [
                    'type' => 'blocker',
                    'routePath' => '/settings?tab=overview',
                    'pageKey' => 'settings',
                    'componentName' => 'SystemOverviewTab',
                    'actionName' => 'overview refresh',
                    'blockerType' => 'api-error',
                    'blockerState' => 'warning',
                    'blockerMessage' => 'Live overview request failed',
                    'message' => 'Live overview request failed',
                    'payload' => [
                        'password' => 'super-secret',
                        'note' => 'safe preview',
                    ],
                ],
            ],
        ], [
            'X-Trace-Id' => 'trace-frontend-blocker',
            'X-System-Session-Id' => $sessionId,
            'X-System-Current-Route' => '/settings?tab=overview',
        ])->assertOk()
            ->assertJsonPath('telemetryAvailable', true);

        $storedEvent = SystemOverviewRouteEvent::query()
            ->where('event_type', 'blocker')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('[REDACTED]', $storedEvent->payload_summary['sanitized']['password'] ?? null);
        $this->assertSame('[STRING]', $storedEvent->payload_summary['sanitized']['field_1'] ?? null);

        $this->assertDatabaseHas('system_overview_error_events', [
            'trace_id' => $eventResponse->headers->get('X-Request-Id'),
            'source' => 'frontend',
            'component_name' => 'SystemOverviewTab',
            'message' => 'A browser operation could not be completed.',
        ]);
    }

    public function test_server_ids_and_sessions_cannot_be_overwritten_by_another_users_client_ids(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->post('/api/trace-security-test', fn () => response()->json(['success' => true]));
        $first = User::factory()->create();
        $second = User::factory()->create();
        Sanctum::actingAs($first);
        $firstResponse = $this->postJson('/api/trace-security-test', ['unknown_field' => 'secret-canary'], [
            'X-System-Session-Id' => 'shared-client-session', 'X-Trace-Id' => 'client-correlation',
        ])->assertOk();
        $firstId = $firstResponse->headers->get('X-Request-Id');
        Sanctum::actingAs($second);
        $secondResponse = $this->postJson('/api/trace-security-test', [], [
            'X-System-Session-Id' => 'shared-client-session', 'X-Trace-Id' => $firstId,
        ])->assertOk();
        $this->assertNotSame($firstId, $secondResponse->headers->get('X-Request-Id'));
        $this->assertSame($first->id, SystemOverviewRequestTrace::where('trace_id', $firstId)->value('user_id'));
        $this->assertSame(2, SystemOverviewSession::whereIn('user_id', [$first->id, $second->id])->count());
        $this->assertStringNotContainsString('secret-canary', SystemOverviewRequestTrace::where('trace_id', $firstId)->first()->toJson());

        $request = Request::create('/api/test');
        $request->headers->set('X-Trace-Id', str_repeat('x', 200));
        $this->assertTrue(Str::isUuid(RequestCorrelation::id($request)));
        $this->assertFalse($request->attributes->has('api.client_correlation_id'));
    }

    public function test_backend_and_client_errors_do_not_store_or_broadcast_arbitrary_diagnostics(): void
    {
        $user = User::factory()->create();
        Event::fake([SystemOverviewActivityUpdated::class]);
        $request = Request::create('/api/test', 'POST', ['password' => 'secret-canary', 'custom' => ['opaque_key' => 'secret-canary']]);
        $request->setUserResolver(fn () => $user);
        $request->headers->set('X-System-Session-Id', 'test-session');
        $service = app(SystemOverviewTelemetryService::class);
        $service->recordException($request, new \RuntimeException('SQL password=secret-canary'), 500);
        $delta = $service->recordClientEvent($request, [
            'type' => 'error', 'message' => 'Bearer secret-canary', 'blockerMessage' => 'secret-canary',
            'errorClass' => 'secret-canary', 'payload' => ['unknown' => 'secret-canary'],
            'routePath' => '/settings?access_token=secret-canary',
        ]);
        $this->assertStringNotContainsString('secret-canary', json_encode($delta));
        $this->assertStringNotContainsString('secret-canary', SystemOverviewErrorEvent::all()->toJson());
        $this->assertStringNotContainsString('secret-canary', SystemOverviewRequestTrace::all()->toJson());
        $this->assertStringNotContainsString('secret-canary', SystemOverviewSession::all()->toJson());
        Event::assertDispatched(SystemOverviewActivityUpdated::class, fn ($event) => !str_contains(json_encode($event), 'secret-canary'));
        $this->assertCount(2, Event::dispatched(SystemOverviewActivityUpdated::class));
    }

    public function test_tax_document_requests_do_not_collect_any_payload_or_response_telemetry(): void
    {
        $user = User::factory()->create();
        foreach (['/api/profile/tax-document', '/api/admin/users/15/tax-document/download'] as $path) {
            $request = Request::create($path, 'POST', ['file' => 'secret-canary']);
            $request->setUserResolver(fn () => $user);
            $this->assertNull(app(SystemOverviewTelemetryService::class)->recordRequestTrace($request, response()->json(['original_name' => 'secret-canary'])));
        }
        $this->assertSame(0, SystemOverviewRequestTrace::count());
    }

    public function test_trace_and_broadcast_paths_use_route_templates_instead_of_credentials_in_url_parameters(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Event::fake([SystemOverviewActivityUpdated::class]);
        Route::middleware(['api', 'auth:sanctum'])->get('/api/signed-document/{token}', fn () => throw new \RuntimeException('private diagnostic'));
        $response = $this->getJson('/api/signed-document/secret-canary', ['X-System-Session-Id' => 'path-test'])->assertStatus(500);
        $trace = SystemOverviewRequestTrace::where('trace_id', $response->json('request_id'))->firstOrFail();
        $this->assertSame('/api/signed-document/{token}', $trace->path);
        $this->assertStringNotContainsString('secret-canary', $trace->toJson());
        $this->assertStringNotContainsString('secret-canary', SystemOverviewErrorEvent::all()->toJson());
        $this->assertStringNotContainsString('secret-canary', SystemOverviewSession::all()->toJson());
        foreach (Event::dispatched(SystemOverviewActivityUpdated::class) as $event) {
            $this->assertStringNotContainsString('secret-canary', json_encode($event));
        }
    }

    public function test_core_authenticated_endpoints_do_not_fail_when_overview_tables_are_missing(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->dropOverviewTables();

        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertOk();
        $this->getJson('/api/me/permissions')->assertOk();
    }

    public function test_telemetry_endpoints_degrade_gracefully_when_overview_tables_are_missing(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->dropOverviewTables();

        Sanctum::actingAs($user);

        $this->postJson('/api/system-telemetry/events', [
            'events' => [
                [
                    'type' => 'session_start',
                    'routePath' => '/settings?tab=overview',
                ],
            ],
        ], [
            'X-System-Session-Id' => 'session-overview-unavailable',
            'X-System-Current-Route' => '/settings?tab=overview',
            'X-Trace-Id' => 'trace-overview-unavailable',
        ])->assertStatus(202)
            ->assertJsonPath('stored', 0)
            ->assertJsonPath('telemetryAvailable', false);

        $this->getJson('/api/admin/system-overview/snapshot')
            ->assertOk()
            ->assertJsonPath('telemetryAvailable', false)
            ->assertJsonPath('data.stats.activeSessions', 0)
            ->assertJsonCount(0, 'data.liveUsers');

        $this->getJson('/api/admin/system-overview/history')
            ->assertOk()
            ->assertJsonPath('telemetryAvailable', false)
            ->assertJsonCount(0, 'data.timeline');

        $this->getJson('/api/admin/system-overview/routes')
            ->assertOk()
            ->assertJsonPath('telemetryAvailable', false)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/admin/system-overview/traces/trace-missing')
            ->assertStatus(503)
            ->assertJsonPath('code', 'system_overview_unavailable')
            ->assertJsonPath('telemetryAvailable', false);
    }

    private function dropOverviewTables(): void
    {
        Schema::dropIfExists('system_overview_error_events');
        Schema::dropIfExists('system_overview_request_traces');
        Schema::dropIfExists('system_overview_route_events');
        Schema::dropIfExists('system_overview_sessions');
    }
}
