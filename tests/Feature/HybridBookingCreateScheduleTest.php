<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Scheduling\GoogleRoutesProvider;
use App\Services\Scheduling\TravelLocationResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/** Exercise Book Shoot preview and the real create/availability/guard stack together. */
class HybridBookingCreateScheduleTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    private User $admin;
    private User $client;
    private User $photographer;
    private Service $service;
    private int $driveSeconds = 600;
    private bool $verified = true;
    private array $routeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'availability.hybrid_travel_enabled' => true,
            'availability.scheduling_lock_store' => 'array', 'availability.buffer_time_minutes' => 15,
            'availability.fallback_start_time' => '00:00', 'availability.fallback_end_time' => '23:59']);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->admin()->create(['email_verification_required_at' => null]);
        $this->client = User::factory()->create(['role' => 'client', 'email_verification_required_at' => null]);
        $this->photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $this->service = Service::factory()->create(['name' => '10 Exterior HDR Photos', 'price' => 100,
            'pricing_type' => 'fixed', 'photographer_required' => true, 'shoot_duration_minutes' => 15]);
        Sanctum::actingAs($this->admin);
        $locations = Mockery::mock(TravelLocationResolver::class);
        $locations->shouldReceive('reset')->andReturnNull();
        $locations->shouldReceive('forPayload')->andReturnUsing(fn ($payload) => $this->location($payload['address']));
        $locations->shouldReceive('forShoot')->andReturnUsing(fn ($shoot) => $this->location($shoot->address));
        $locations->shouldReceive('persistedMetadata')->andReturnUsing(fn ($location) => [
            'building_key' => $location['building_key'], 'signature' => 'trusted-fixture',
        ]);
        $this->app->instance(TravelLocationResolver::class, $locations);
        $routes = Mockery::mock(GoogleRoutesProvider::class);
        $routes->shouldReceive('reset')->andReturnNull();
        $routes->shouldReceive('route')->andReturnUsing(function ($origin, $destination, $departure) {
            $this->assertSame(0, DB::transactionLevel(), 'Route lookup must finish before the booking transaction.');
            $this->routeCalls[] = [$origin['building_key'], $destination['building_key'], $departure->toIso8601String()];
            return ['status' => 'ok', 'duration_seconds' => $this->driveSeconds, 'distance_meters' => 1609,
                'reason_code' => 'traffic_aware_route'];
        });
        $this->app->instance(GoogleRoutesProvider::class, $routes);
    }

    private function location(string $address): array
    {
        return ['verified' => $this->verified, 'complete' => true, 'precision' => 'exact',
            'building_key' => preg_replace('/ Unit .+$/', '', $address), 'address_hash' => hash('sha256', $address),
            'full_address' => $address.', Baltimore, MD', 'latitude' => 39.2, 'longitude' => -76.6];
    }

    private function payload(string $at = '2026-10-02T09:00:00-04:00', string $address = '5806 Winner Avenue'): array
    {
        return ['client_id' => $this->client->id, 'photographer_id' => $this->photographer->id,
            'address' => $address, 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21205',
            'scheduled_at' => $at, 'timezone' => 'America/New_York',
            'services' => [['id' => $this->service->id, 'quantity' => 1]]];
    }

    private function booked(string $time, int $minutes = 15, string $address = '615 North Highland Avenue', ?User $photographer = null): Shoot
    {
        $photographer ??= $this->photographer;
        $at = Carbon::parse('2026-10-02 '.$time, 'America/New_York')->utc();
        $shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'photographer_id' => $photographer->id,
            'scheduled_at' => $at, 'scheduled_date' => '2026-10-02', 'time' => $time,
            'timezone' => 'America/New_York', 'address' => $address,
            'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $shoot->services()->attach($this->service->id, ['duration_minutes' => $minutes, 'price' => 100, 'quantity' => 1,
            'photographer_id' => $photographer->id, 'scheduled_at' => $at, 'workflow_status' => 'scheduled']);
        return $shoot;
    }

    private function preview(array $payload): array
    {
        return $this->postJson('/api/photographer/availability/feasibility', $payload + ['action_mode' => 'create'])
            ->assertOk()->json('data');
    }

    private function save(array $payload): Shoot
    {
        $id = $this->postJson('/api/shoots', $payload)->assertCreated()->json('data.id');
        return Shoot::findOrFail($id);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['client'])]
    public function test_create_derives_shoot_time_from_explicit_service_time(string $role): void
    {
        Sanctum::actingAs($role === 'client' ? $this->client : $this->admin);
        $payload = $this->payload('2026-10-02T11:15:00-04:00');
        $payload['services'][0]['scheduled_at'] = '2026-10-02T11:30:00-04:00';
        $shoot = $this->save($payload);
        $this->assertSame('2026-10-02 15:30:00', $shoot->getRawOriginal('scheduled_at'));
        $this->assertSame('2026-10-02', $shoot->scheduled_date->toDateString());
        $this->assertSame('11:30', substr($shoot->time, 0, 5));
        $this->assertSame('2026-10-02 15:30:00', $shoot->serviceItems()->sole()->getRawOriginal('scheduled_at'));
    }

    public function test_michael_short_exteriors_keep_existing_duration_and_use_one_fifteen_minute_gap(): void
    {
        $original = $this->booked('08:00', 30, '613 North Ellwood Avenue');
        $payload = $this->payload('2026-10-02T08:45:00-04:00', '615 North Highland Avenue');
        $preview = $this->preview($payload);
        $this->assertSame('available', $preview['status']);
        $this->assertSame(15, $preview['visits'][0]['duration_minutes']);
        $this->assertEquals(15, $preview['transitions'][0]['available_minutes']);
        $first = $this->save($payload);
        $this->assertSame('2026-10-02 12:45:00', $first->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(15, $first->serviceItems()->sole()->duration_minutes);
        $secondPayload = $this->payload('2026-10-02T09:15:00-04:00');
        $this->assertSame('available', $this->preview($secondPayload)['status']);
        $second = $this->save($secondPayload);
        $this->assertSame('2026-10-02 13:15:00', $second->serviceItems()->sole()->getRawOriginal('scheduled_at'));
        $this->assertSame(30, $original->serviceItems()->sole()->duration_minutes);
        $this->assertSame('2026-10-02 12:00:00', $original->fresh()->getRawOriginal('scheduled_at'));
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_both_travel_edges_require_a_current_deliberate_override(string $role): void
    {
        $actor = User::factory()->create(['role' => $role, 'email_verification_required_at' => null]);
        Sanctum::actingAs($actor);
        $this->booked('08:00', 30);
        $this->booked('09:30', 15, '900 Next Avenue');
        $this->driveSeconds = 1200; // 20 minutes driving + five minutes preparation.
        $payload = $this->payload('2026-10-02T08:50:00-04:00');
        $payload['services'][0]['duration_minutes'] = 30;
        $preview = $this->preview($payload);
        $this->assertSame('conflict', $preview['status']);
        $this->assertTrue($preview['can_override']);
        $this->assertSame(['incoming', 'outgoing'], array_column($preview['transitions'], 'direction'));
        $this->assertEquals([20, 10], array_column($preview['transitions'], 'available_minutes'));
        $this->postJson('/api/shoots', $payload + ['skip_availability_check' => true])
            ->assertUnprocessable()->assertJsonPath('feasibility.status', 'conflict');
        $override = ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_reason' => 'Photographer reviewed both neighboring appointments',
            'travel_override_confirmation_version' => $preview['confirmation_version']];
        $withoutSwipe = $override;
        unset($withoutSwipe['travel_override_confirmed']);
        $this->postJson('/api/shoots', $payload + $withoutSwipe)->assertUnprocessable()
            ->assertJsonValidationErrors('travel_override_confirmed');
        $saved = $this->save($payload + $override);
        $this->assertSame('2026-10-02 12:50:00', $saved->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(30, $saved->serviceItems()->sole()->duration_minutes);
        $audit = UserActivityLog::where('event_type', 'schedule.travel_override')->sole();
        $this->assertSame($actor->id, $audit->actor_user_id);
        $this->assertTrue($audit->metadata['confirmed']);
        $this->assertSame([(int) $saved->id], $audit->metadata['shoot_ids']);
    }

    public function test_verified_separate_units_have_zero_travel_but_still_cannot_overlap(): void
    {
        $this->booked('19:00', 15, '12800 Middlebrook Road Unit 333');
        $payload = $this->payload('2026-10-02T19:15:00-04:00', '12800 Middlebrook Road Unit 206');
        $preview = $this->preview($payload);
        $this->assertSame('available', $preview['status']);
        $this->assertSame('same_building', $preview['transitions'][0]['source']);
        $this->assertSame(0, $preview['transitions'][0]['required_minutes']);
        $saved = $this->save($payload);
        $this->assertSame('2026-10-02 23:15:00', $saved->serviceItems()->sole()->getRawOriginal('scheduled_at'));
        $this->assertSame([], $this->routeCalls);
        $payload['scheduled_at'] = '2026-10-02T19:05:00-04:00';
        $conflict = $this->preview($payload);
        $this->assertContains('capture_overlap', $conflict['reason_codes']);
        $this->assertFalse($conflict['can_override']);
        $this->postJson('/api/shoots', $payload + ['skip_availability_check' => true, 'travel_override' => true,
            'travel_override_confirmed' => true, 'travel_override_reason' => 'Cannot bypass actual capture overlap'])
            ->assertUnprocessable();
        $this->assertSame(2, Shoot::count());
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function test_two_unit_capture_lines_share_one_visit_and_keep_utc_snapshots(bool $staggered): void
    {
        $this->booked('18:30', 15, 'Prior Building');
        $this->booked('19:45', 15, 'Next Building');
        $payload = $this->payload('2026-10-02T19:00:00-04:00', '12800 Middlebrook Road');
        unset($payload['services']);
        $payload['units'] = [['client_key' => 'u333', 'label' => 'Unit 333', 'kind' => 'unit'],
            ['client_key' => 'u206', 'label' => 'Unit 206', 'kind' => 'unit']];
        $payload['service_lines'] = array_map(fn ($key, $index) => ['client_key' => 'line-'.$key,
            'unit_client_key' => $key, 'service_id' => $this->service->id, 'quantity' => 1,
            'duration_minutes' => 15, 'photographer_id' => $this->photographer->id,
            'scheduled_at' => $staggered && $index === 1 ? '2026-10-02T19:15:00-04:00' : $payload['scheduled_at']],
            ['u333', 'u206'], [0, 1]);
        $preview = $this->preview($payload);
        $this->assertSame('available', $preview['status']);
        $this->assertSame(30, array_sum(array_column($preview['visits'], 'duration_minutes')));
        $saved = $this->save($payload);
        $this->assertSame(2, $saved->units()->count());
        $this->assertSame([15, 15], $saved->serviceItems()->orderBy('id')->pluck('duration_minutes')->all());
        $this->assertSame(['2026-10-02 23:00:00', $staggered ? '2026-10-02 23:15:00' : '2026-10-02 23:00:00'],
            $saved->serviceItems()->orderBy('id')->get()->map(fn ($item) => $item->getRawOriginal('scheduled_at'))->all());
    }

    public function test_secondary_service_assignment_is_checked_without_reserving_an_unassigned_primary(): void
    {
        $secondary = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $this->booked('09:00', 30);
        $this->booked('10:00', 30, 'Secondary Appointment', $secondary);
        $payload = $this->payload();
        $payload['service_items'] = [['service_id' => $this->service->id, 'photographer_id' => $secondary->id,
            'scheduled_at' => '2026-10-02T10:00:00-04:00', 'duration_minutes' => 17]];
        $blocked = $this->preview($payload);
        $this->assertContains('capture_overlap', $blocked['reason_codes']);
        $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        $payload['service_items'][0]['scheduled_at'] = $payload['scheduled_at'];
        $available = $this->preview($payload);
        $this->assertSame('available', $available['status']);
        $this->assertSame([$secondary->id], array_column($available['visits'], 'photographer_id'));
        $saved = $this->save($payload);
        $this->assertSame($secondary->id, $saved->serviceItems()->sole()->photographer_id);
        $this->assertSame(17, $saved->serviceItems()->sole()->duration_minutes);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['2026-10-02T09:00:00-04:00', 'America/New_York', '2026-10-02 13:00:00'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2026-10-02T09:00:00', 'America/New_York', '2026-10-02 13:00:00'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2026-11-01T01:30:00-05:00', 'America/New_York', '2026-11-01 06:30:00'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2026-11-01T01:30:00-05:00', 'America/New_York', '2026-11-01 06:30:00', 'service_items'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2026-11-01T01:30:00-05:00', 'America/New_York', '2026-11-01 06:30:00', 'inherited'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2026-10-02T09:00:00', null, '2026-10-02 09:00:00'])]
    public function test_preview_and_saved_service_instants_match_for_custom_durations(string $at, ?string $zone, string $stored, string $field = 'services'): void
    {
        $payload = $this->payload($at);
        $payload['timezone'] = $zone;
        if ($field === 'services') {
            $payload['services'][0]['scheduled_at'] = $at;
        } elseif ($field === 'service_items') {
            $payload['service_items'] = [['service_id' => $this->service->id, 'scheduled_at' => $at]];
        }
        $payload['services'][0]['duration_minutes'] = 17;
        $preview = $this->preview($payload);
        $this->assertSame('available', $preview['status']);
        $this->assertSame(17, $preview['visits'][0]['duration_minutes']);
        $saved = $this->save($payload);
        $this->assertSame($stored, $saved->getRawOriginal('scheduled_at'));
        $this->assertSame($stored, $saved->serviceItems()->sole()->getRawOriginal('scheduled_at'));
        $expectedInstant = Carbon::parse($stored, $zone ? 'UTC' : 'America/New_York')->utc()->toIso8601String();
        $this->assertSame($expectedInstant, $preview['visits'][0]['start']);
    }

    public function test_unverified_same_address_requires_review_and_editing_manager_cannot_override(): void
    {
        $this->booked('09:00', 15, '12800 Middlebrook Road Unit 333');
        $this->verified = false;
        $payload = $this->payload('2026-10-02T09:15:00-04:00', '12800 Middlebrook Road Unit 206');
        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager', 'email_verification_required_at' => null]));
        $preview = $this->preview($payload);
        $this->assertSame('review_required', $preview['status']);
        $this->assertFalse($preview['can_override']);
        $this->postJson('/api/shoots', $payload + ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_reason' => 'A role without override permission cannot confirm this'])
            ->assertForbidden();
        $this->assertSame(1, Shoot::count());
        $this->assertSame([], $this->routeCalls);
    }

    public function test_feature_off_preserves_one_fixed_fifteen_minute_buffer_and_skips_provider(): void
    {
        config(['availability.hybrid_travel_enabled' => false]);
        $this->booked('08:30', 15);
        $payload = $this->payload('2026-10-02T08:59:00-04:00');
        $this->assertFalse($this->preview($payload)['enabled']);
        $this->postJson('/api/shoots', $payload)->assertUnprocessable();
        $payload['scheduled_at'] = '2026-10-02T09:00:00-04:00';
        $saved = $this->save($payload);
        $this->assertSame('2026-10-02 13:00:00', $saved->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->routeCalls);
    }

    public function test_a_dated_shorter_workday_restricts_preview_and_actual_create(): void
    {
        foreach ([['date' => null, 'day_of_week' => 'friday', 'end_time' => '18:00'],
            ['date' => '2026-10-02', 'day_of_week' => 'friday', 'end_time' => '10:00']] as $row) {
            PhotographerAvailability::create($row + ['photographer_id' => $this->photographer->id,
                'start_time' => '09:00', 'status' => 'available']);
        }
        $payload = $this->payload('2026-10-02T15:00:00-04:00');
        $preview = $this->preview($payload);
        $this->assertContains('outside_working_hours', $preview['reason_codes']);
        $this->assertFalse($preview['can_override']);
        $this->postJson('/api/shoots', $payload + ['skip_availability_check' => true])->assertUnprocessable();
        $this->assertSame(0, Shoot::count());
    }

    public function test_independent_capture_rows_sum_without_counting_digital_work_or_a_second_buffer(): void
    {
        $this->booked('09:45', 15, 'Next Property');
        $capture = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 13]);
        $digital = Service::factory()->create(['photographer_required' => false, 'shoot_duration_minutes' => 0]);
        $payload = $this->payload();
        $payload['services'] = [['id' => $this->service->id, 'duration_minutes' => 17],
            ['id' => $capture->id, 'duration_minutes' => 13], ['id' => $digital->id, 'duration_minutes' => 0]];
        $preview = $this->preview($payload);
        $this->assertSame('available', $preview['status']);
        $this->assertCount(1, $preview['visits']);
        $this->assertSame(30, $preview['visits'][0]['duration_minutes']);
        $this->assertEquals(15, $preview['transitions'][0]['available_minutes']);
        $saved = $this->save($payload);
        $this->assertSame([17, 13, 0], $saved->serviceItems()->orderBy('id')->pluck('duration_minutes')->all());
    }

    public function test_exact_closing_is_allowed_but_capture_overrun_is_not_overridable(): void
    {
        config(['availability.fallback_start_time' => '08:00', 'availability.fallback_end_time' => '18:00']);
        $payload = $this->payload('2026-10-02T17:45:00-04:00');
        $payload['services'][0]['duration_minutes'] = 16;
        $conflict = $this->preview($payload);
        $this->assertContains('outside_working_hours', $conflict['reason_codes']);
        $this->assertFalse($conflict['can_override']);
        $this->postJson('/api/shoots', $payload + ['skip_availability_check' => true])->assertUnprocessable();
        $payload['services'][0]['duration_minutes'] = 15;
        $this->assertSame('available', $this->preview($payload)['status']);
        $this->assertSame(15, $this->save($payload)->serviceItems()->sole()->duration_minutes);
    }

    public function test_browser_aliases_work_in_preview_and_create_without_changing_explicit_service_instants(): void
    {
        foreach ([false, true] as $enabled) {
            config(['availability.hybrid_travel_enabled' => $enabled]);
            foreach (['Asia/Calcutta' => 'Asia/Kolkata', 'Asia/Katmandu' => 'Asia/Kathmandu',
                'Europe/Kiev' => 'Europe/Kyiv', 'US/Eastern' => 'America/New_York'] as $alias => $canonical) {
                $photographer = User::factory()->photographer()->create(['timezone' => $alias]);
                // Exact shape of the live browser failure, using local fixture identities.
                $payload = $this->payload('2026-10-03T04:30:00.000Z');
                $payload['photographer_id'] = $photographer->id;
                $payload['timezone'] = $alias;
                $payload['service_items'] = [['service_id' => $this->service->id,
                    'scheduled_at' => '2026-10-03T12:45:00.000Z', 'duration_minutes' => 60]];
                $preview = $this->preview($payload);
                $this->assertSame($enabled, $preview['enabled']);
                if ($enabled) {
                    $this->assertSame('2026-10-03T12:45:00+00:00', $preview['visits'][0]['start']);
                    $this->assertSame($canonical, $preview['visits'][0]['timezone']);
                }
                $saved = $this->save($payload);
                $this->assertSame($canonical, $saved->timezone);
                $this->assertSame('2026-10-03 12:45:00', $saved->getRawOriginal('scheduled_at'));
                $this->assertSame('2026-10-03 12:45:00', $saved->serviceItems()->sole()->getRawOriginal('scheduled_at'));
                $this->assertSame(60, $saved->serviceItems()->sole()->duration_minutes);
            }
        }
    }

    public function test_timezone_alias_support_still_rejects_unknown_and_malformed_names(): void
    {
        foreach ([false, true] as $enabled) {
            config(['availability.hybrid_travel_enabled' => $enabled]);
            foreach (['Invalid/Zone', 'asia/calcutta', '+05:30', ['Asia/Calcutta']] as $invalid) {
                $payload = $this->payload();
                $payload['timezone'] = $invalid;
                $this->postJson('/api/photographer/availability/feasibility', $payload)
                    ->assertUnprocessable()->assertJsonValidationErrors('timezone');
                $this->postJson('/api/shoots', $payload)->assertUnprocessable()->assertJsonValidationErrors('timezone');
            }
        }
        $this->assertSame(0, Shoot::count());
    }
}
