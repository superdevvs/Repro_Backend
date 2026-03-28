<?php

namespace Tests\Feature;

use App\Models\SystemOverviewRequestTrace;
use App\Models\SystemOverviewRouteEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemOverviewTelemetryTest extends TestCase
{
    use RefreshDatabase;

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
        $response->assertJsonPath('data.liveUsers.0.currentRoute', '/settings?tab=overview');
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
        $superadmin = User::factory()->superAdmin()->create([
            'bio' => 'Initial bio',
        ]);

        Sanctum::actingAs($superadmin);

        $traceId = 'trace-profile-update';
        $sessionId = 'session-profile-update';

        $this->putJson('/api/profile', [
            'bio' => 'Updated from overview telemetry test',
        ], [
            'X-Trace-Id' => $traceId,
            'X-System-Session-Id' => $sessionId,
            'X-System-Current-Route' => '/settings?tab=overview',
        ])->assertOk();

        $this->assertDatabaseHas('system_overview_request_traces', [
            'trace_id' => $traceId,
            'session_key' => $sessionId,
            'path' => '/api/profile',
            'current_route' => '/settings?tab=overview',
            'status_code' => 200,
        ]);

        $storedTrace = SystemOverviewRequestTrace::query()
            ->where('trace_id', $traceId)
            ->firstOrFail();

        $this->assertSame(['bio'], $storedTrace->request_payload_summary['topLevelKeys'] ?? []);
        $this->assertSame('Updated from overview telemetry test', $storedTrace->request_payload_summary['sanitized']['bio'] ?? null);

        $this->postJson('/api/system-telemetry/events', [
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
        $this->assertSame('safe preview', $storedEvent->payload_summary['sanitized']['note'] ?? null);

        $this->assertDatabaseHas('system_overview_error_events', [
            'trace_id' => 'trace-frontend-blocker',
            'source' => 'frontend',
            'component_name' => 'SystemOverviewTab',
            'message' => 'Live overview request failed',
        ]);
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
