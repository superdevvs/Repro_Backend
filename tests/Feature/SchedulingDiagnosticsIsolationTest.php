<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Scheduling\GoogleRoutesBudget;
use App\Services\Scheduling\ScheduleCommitGuard;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\SchedulingBufferSettings;
use App\Services\Scheduling\TravelLocationResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SchedulingDiagnosticsIsolationTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    private User $actor;
    private User $photographer;
    private Service $service;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => '0123456789abcdef0123456789abcdef',
            'availability.hybrid_travel_enabled' => true, 'availability.scheduling_lock_store' => 'array',
            'availability.fallback_start_time' => '00:00', 'availability.fallback_end_time' => '23:59',
            'services.google_routes.key' => 'qa-route-key', 'services.google_routes.base_url' => 'https://routes.test/compute']);
        Queue::fake(); Mail::fake(); Http::preventStrayRequests();
        $this->actor = User::factory()->create(['role' => 'salesRep', 'name' => 'Michael QA', 'email_verification_required_at' => null]);
        Sanctum::actingAs($this->actor);
        $client = User::factory()->create(['role' => 'client', 'name' => 'House Account QA']);
        $this->photographer = User::factory()->photographer()->create(['name' => 'Lee QA', 'timezone' => 'America/New_York']);
        $this->service = Service::factory()->create(['name' => '25 HDR Photos', 'photographer_required' => true,
            'shoot_duration_minutes' => 45, 'price' => 185]);
        $at = now('America/New_York')->addDay()->setTime(11, 0)->utc()->toIso8601String();
        $this->payload = ['action_mode' => 'create', 'client_id' => $client->id,
            'photographer_id' => $this->photographer->id, 'scheduled_at' => $at, 'timezone' => 'America/New_York',
            'address' => '5806 Winner Avenue', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21215',
            'services' => [['id' => $this->service->id, 'quantity' => 1, 'duration_minutes' => 45]],
            'include_alternatives' => false];
        $location = Mockery::mock(TravelLocationResolver::class);
        $location->shouldReceive('reset')->andReturnNull();
        $location->shouldReceive('forPayload')->andReturnUsing(fn ($payload) => $this->location($payload['address']));
        $location->shouldReceive('forShoot')->andReturnUsing(fn ($shoot) => $this->location($shoot->address));
        $location->shouldReceive('persistedMetadata')->andReturn([]);
        $this->app->instance(TravelLocationResolver::class, $location);
    }

    private function location(string $address): array
    {
        return ['complete' => true, 'verified' => true, 'precision' => 'exact',
            'building_key' => $address, 'address_hash' => hash('sha256', $address),
            'full_address' => $address.', Baltimore, MD 21215, US'];
    }

    private function breakDiagnostics(): void
    {
        Log::partialMock();
        $broken = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $broken->shouldReceive('info', 'notice')->andThrow(new \UnexpectedValueException('Scheduling log permission denied'));
        Log::shouldReceive('channel')->with('scheduling')->andReturn($broken);
        Log::shouldReceive('error')->andThrow(new \UnexpectedValueException('Fallback log permission denied'));
    }

    private function preview(array $extra = []): array
    {
        return $this->postJson('/api/photographer/availability/feasibility', $extra + $this->payload)
            ->assertOk()->json('data');
    }

    private function booked(int $offsetMinutes): Shoot
    {
        $at = Carbon::parse($this->payload['scheduled_at'])->addMinutes($offsetMinutes);
        $shoot = Shoot::factory()->create(['photographer_id' => $this->photographer->id, 'rep_id' => $this->actor->id,
            'scheduled_at' => $at, 'scheduled_date' => $at->copy()->setTimezone('America/New_York')->toDateString(),
            'time' => $at->copy()->setTimezone('America/New_York')->format('H:i'), 'timezone' => 'America/New_York',
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'address' => '10 Prior Road']);
        $shoot->services()->attach($this->service->id, ['photographer_id' => $this->photographer->id,
            'scheduled_at' => $at, 'duration_minutes' => 45, 'price' => 185, 'quantity' => 1,
            'workflow_status' => 'scheduled']);
        return $shoot;
    }

    public function test_null_empty_and_omitted_property_details_preview_the_same_available_slot(): void
    {
        $this->breakDiagnostics();
        $omitted = $this->preview();
        foreach ([null, []] as $details) {
            $result = $this->preview(['property_details' => $details]);
            $this->assertSame('available', $result['status']);
            $this->assertSame($omitted['visits'], $result['visits']);
        }
        $this->assertSame(45, $omitted['visits'][0]['duration_minutes']);
        $this->assertDatabaseCount('shoots', 0);
        Mail::assertNothingSent();
    }

    public function test_logging_failure_does_not_hide_a_real_capture_overlap(): void
    {
        $this->booked(30);
        $this->breakDiagnostics();
        $result = $this->preview(['property_details' => null]);
        $this->assertSame('conflict', $result['status']);
        $this->assertContains('capture_overlap', $result['reason_codes']);
        $this->assertFalse($result['can_override']);
        $this->assertDatabaseCount('shoots', 1);
    }

    public function test_logger_construction_and_fallback_failure_leave_validation_and_results_intact(): void
    {
        Log::partialMock();
        Log::shouldReceive('channel')->with('scheduling')->andThrow(new \RuntimeException('Logger construction failed'));
        Log::shouldReceive('error')->andThrow(new \RuntimeException('Fallback unavailable'));
        $this->assertSame('available', $this->preview(['property_details' => null])['status']);
        $this->postJson('/api/photographer/availability/feasibility', ['property_details' => 'invalid'] + $this->payload)
            ->assertUnprocessable()->assertJsonValidationErrors('property_details');
        $this->postJson('/api/photographer/availability/feasibility', ['property_details' => ['sqft' => -1]] + $this->payload)
            ->assertUnprocessable()->assertJsonValidationErrors('property_details.sqft');
    }

    public function test_fresh_scheduling_directory_is_shared_when_permitted_and_nonfatal_otherwise(): void
    {
        $previousStorage = $this->app->storagePath();
        $previousPath = config('logging.channels.scheduling.path');
        $temporary = sys_get_temp_dir().'/qa-scheduling-'.bin2hex(random_bytes(8));
        mkdir($temporary.'/logs', 0777, true);
        try {
            // Probe the real process capability without touching the directory being tested.
            $canShareDirectory = PHP_OS_FAMILY === 'Windows' || @chgrp($temporary.'/logs', 'www-data');
            $this->app->useStoragePath($temporary);
            config(['logging.channels.scheduling.path' => $temporary.'/logs/scheduling/scheduling.log']);
            Log::partialMock();
            if ($canShareDirectory) {
                Log::shouldReceive('channel')->with('scheduling')->once()->andReturn(new \Psr\Log\NullLogger);
                Log::shouldReceive('error')->never();
            } else {
                Log::shouldReceive('channel')->with('scheduling')->never();
                Log::shouldReceive('error')->once()->with('Scheduling diagnostics could not be written.', [
                    'diagnostic_event' => 'Travel feasibility evaluated', 'exception_type' => \RuntimeException::class,
                ]);
            }
            $result = app(ScheduleFeasibilityService::class)->evaluate($this->payload, null, $this->actor);
            $this->assertSame('available', $result['status']);
            $this->assertSame(45, $result['visits'][0]['duration_minutes']);
            $directory = $temporary.'/logs/scheduling';
            $this->assertDirectoryExists($directory);
            clearstatcache(true, $directory);
            if (PHP_OS_FAMILY !== 'Windows') {
                if ($canShareDirectory) {
                    $this->assertSame(02770, fileperms($directory) & 07777);
                    $this->assertSame(filegroup($temporary.'/logs'), filegroup($directory));
                } else {
                    // Failed sharing cannot make the fresh directory accessible to other users.
                    $this->assertSame(0, fileperms($directory) & 0007);
                    $this->assertFileDoesNotExist($directory.'/scheduling.log');
                }
            }
        } finally {
            $this->app->useStoragePath($previousStorage);
            config(['logging.channels.scheduling.path' => $previousPath]);
            if (is_dir($temporary.'/logs/scheduling')) rmdir($temporary.'/logs/scheduling');
            rmdir($temporary.'/logs');
            rmdir($temporary);
        }
    }

    public function test_route_and_budget_logging_failures_preserve_a_valid_route_result(): void
    {
        $this->booked(-120);
        config(['services.google_routes.limit_elements' => 1]);
        Http::fake(['https://routes.test/compute' => Http::response([
            ['condition' => 'ROUTE_EXISTS', 'duration' => '600s', 'distanceMeters' => 1000],
        ])]);
        $this->breakDiagnostics();
        $result = $this->preview();
        $this->assertSame('available', $result['status']);
        $this->assertSame('google_routes', $result['transitions'][0]['source']);
        $this->assertSame(1, app(GoogleRoutesBudget::class)->status()['used_elements']);
        Http::assertSentCount(1);
    }

    public function test_provider_failure_still_returns_a_route_review_warning_with_broken_logs(): void
    {
        $this->booked(-120);
        DB::table('settings')->insert(['key' => SchedulingBufferSettings::KEY,
            'value' => json_encode(['mode' => 'google', 'fallback' => 'review'])]);
        Http::fake(['https://routes.test/compute' => Http::response([], 503)]);
        $this->breakDiagnostics();
        $result = $this->preview();
        $this->assertSame('review_required', $result['status']);
        $this->assertContains('travel_review_required', $result['reason_codes']);
        $this->assertTrue($result['can_override']);
        $this->assertNotEmpty($result['confirmation_version']);
        $this->assertSame('routes_http_error', $result['transitions'][0]['reason_code']);
    }

    public function test_normal_travel_override_commit_is_not_failed_by_its_after_commit_diagnostic(): void
    {
        $this->booked(-120);
        DB::table('settings')->insert(['key' => SchedulingBufferSettings::KEY,
            'value' => json_encode(['mode' => 'google', 'fallback' => 'review'])]);
        Http::fake(['https://routes.test/compute' => Http::response([], 503)]);
        $this->breakDiagnostics();
        $result = app(ScheduleFeasibilityService::class)->evaluate($this->payload, null, $this->actor);
        $payload = $this->payload + ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_reason' => 'Staff reviewed the available travel gap',
            'travel_override_confirmation_version' => $result['confirmation_version']];
        $guard = app(ScheduleCommitGuard::class);
        $prepared = $guard->prepare($payload, null, $this->actor);
        $saved = $guard->commit($prepared, function () {
            $target = $this->booked(0);
            return $target;
        });
        $this->assertInstanceOf(Shoot::class, $saved);
        $this->assertSame(1, DB::table('user_activity_logs')->where('event_type', 'schedule.travel_override')->count());
    }
}
