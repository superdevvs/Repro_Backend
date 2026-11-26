<?php

namespace Tests\Feature;

use App\Events\SystemOverviewActivityUpdated;
use App\Models\SystemOverviewRequestTrace;
use App\Models\SystemOverviewSession;
use App\Models\User;
use App\Services\SystemOverviewTelemetryService;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatedSecurityTestCase;

/**
 * Request telemetry is written after the response is sent (the middleware's terminate step), so
 * its database writes are no longer part of the time a signed-in user waits for an answer.
 */
class SystemOverviewTelemetryAfterResponseTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;

    /** @var array{traces:int,sessions:int,telemetryWrites:int}|null */
    private ?array $atResponse = null;

    private int $telemetryWrites = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Event::fake([SystemOverviewActivityUpdated::class]);

        DB::listen(function ($query): void {
            $sql = strtolower($query->sql);
            if (preg_match('/^(insert|update|delete)\b/', $sql) && str_contains($sql, 'system_overview_')) {
                $this->telemetryWrites++;
            }
        });

        // RequestHandled fires once the response exists and before the kernel's terminate step.
        $this->app['events']->listen(RequestHandled::class, function (): void {
            $this->atResponse = [
                'traces' => DB::table('system_overview_request_traces')->count(),
                'sessions' => DB::table('system_overview_sessions')->count(),
                'telemetryWrites' => $this->telemetryWrites,
            ];
        });
    }

    public function test_request_trace_is_written_after_the_response(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/user', ['X-System-Session-Id' => 'after-response-trace'])->assertOk();

        $this->assertSame(['traces' => 0, 'sessions' => 0, 'telemetryWrites' => 0], $this->atResponse,
            'No system_overview_* write may happen before the response is handed back.');
        $this->assertSame(1, SystemOverviewRequestTrace::query()->count());
        $this->assertSame(1, SystemOverviewSession::query()->count());
        $this->assertSame(200, SystemOverviewRequestTrace::query()->value('status_code'));
    }

    public function test_setting_can_restore_recording_inside_the_request(): void
    {
        config(['system_overview.record_after_response' => false]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/user', ['X-System-Session-Id' => 'in-request-trace'])->assertOk();

        $this->assertSame(1, $this->atResponse['traces']);
        $this->assertSame(1, SystemOverviewRequestTrace::query()->count());
    }

    public function test_duration_stops_when_the_response_is_ready_not_when_it_is_recorded(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/api/user');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('system_overview.started_at', 1000.0);
        $request->attributes->set('system_overview.finished_at', 1000.25);

        $trace = app(SystemOverviewTelemetryService::class)->recordRequestTrace($request, response()->json([]));

        $this->assertSame(250, $trace->duration_ms);
    }
}
