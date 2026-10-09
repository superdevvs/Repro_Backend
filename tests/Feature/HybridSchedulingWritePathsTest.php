<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootRescheduleRequest;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\Scheduling\ScheduleFeasibilityService;
use App\Services\Scheduling\WriteSchedulePlan;
use App\Exceptions\PublicApiResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class HybridSchedulingWritePathsTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    protected function setUp(): void
    {
        parent::setUp();
        config(['availability.hybrid_travel_enabled' => true, 'availability.scheduling_lock_store' => 'array']);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $availability = Mockery::mock(PhotographerAvailabilityService::class)->makePartial();
        $availability->shouldReceive('assertWithinAvailabilityBounds')->andReturnNull();
        $this->app->instance(PhotographerAvailabilityService::class, $availability);
    }

    private function fixture(): array
    {
        $admin = User::factory()->admin()->create();
        $admin->forceFill(['email_verification_required_at' => null])->saveQuietly();
        Sanctum::actingAs($admin);
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_at' => '2026-10-15 14:00:00', 'scheduled_date' => '2026-10-15', 'time' => '10:00',
            'timezone' => 'America/New_York', 'alternate_scheduled_date' => '2026-10-16',
            'alternate_time' => '11:00', 'alternate_scheduled_at' => '2026-10-16 15:00:00']);
        $service = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 15]);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'duration_minutes' => 17,
            'photographer_id' => $shoot->photographer_id, 'scheduled_at' => '2026-10-15 14:00:00',
            'workflow_status' => 'scheduled', 'is_deliverable' => true]);
        return [$admin, $shoot, $service];
    }

    public function test_confirmed_public_write_paths_check_travel_before_any_mutation(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $seen = [];
        $confirmation = ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_confirmation_version' => str_repeat('a', 64),
            'travel_override_reason' => 'Staff reviewed the scheduling warning'];
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldReceive('evaluate')->andReturnUsing(function ($payload) use (&$seen) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNotEmpty($payload['_schedule_visits']);
            $this->assertTrue($payload['travel_override_confirmed']);
            $this->assertSame(str_repeat('a', 64), $payload['travel_override_confirmation_version']);
            $seen[] = $payload['action_mode'];
            return ['enabled' => true];
        });
        $engine->shouldReceive('assertResult')->andReturnUsing(fn () => throw new PublicApiResponseException(response()->json([
            'message' => 'Travel blocked for this test.', 'errors' => ['travel_schedule' => ['Travel blocked.']],
        ], 422)));
        $this->app->instance(ScheduleFeasibilityService::class, $engine);

        $this->postJson('/api/shoots', ['client_id' => $shoot->client_id, 'address' => '500 Test Avenue',
            'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201', 'photographer_id' => $shoot->photographer_id,
            'scheduled_at' => '2026-10-16T15:00:00Z', 'services' => [['id' => $service->id, 'quantity' => 1]],
            'skip_availability_check' => true] + $confirmation)->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $this->patchJson("/api/shoots/{$shoot->id}", ['services' => [['id' => $service->id,
            'scheduled_at' => '2026-10-16T15:00:00Z', 'duration_minutes' => 17]]] + $confirmation)
            ->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $shoot->forceFill(['status' => 'requested', 'workflow_status' => 'requested'])->saveQuietly();
        $this->postJson("/api/shoots/{$shoot->id}/approve", ['scheduled_at' => '2026-10-16T15:00:00Z'] + $confirmation)
            ->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $shoot->forceFill(['status' => 'scheduled', 'workflow_status' => 'scheduled'])->saveQuietly();
        $this->postJson("/api/shoots/{$shoot->id}/schedule", ['scheduled_at' => '2026-10-16T15:00:00Z'] + $confirmation)
            ->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", ['service_id' => $service->id,
            'photographer_id' => $shoot->photographer_id] + $confirmation)->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $this->postJson("/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2026-10-16', 'requested_time' => '11:00'] + $confirmation)
            ->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $this->postJson("/api/shoots/{$shoot->id}/apply-alternate-date", ['scope' => 'all_services'] + $confirmation)
            ->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $pending = ShootRescheduleRequest::create(['shoot_id' => $shoot->id, 'requested_by' => $shoot->client_id,
            'requested_date' => '2026-10-16', 'requested_time' => '11:00', 'status' => 'pending']);
        $this->patchJson("/api/shoots/reschedule-requests/{$pending->id}", ['status' => 'approved'] + $confirmation)
            ->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $this->assertSame(['create', 'update', 'approve', 'schedule', 'assign', 'reschedule', 'alternate', 'reschedule'], $seen);
        $this->assertSame('2026-10-15 14:00:00', $shoot->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(1, Shoot::count());
    }

    public function test_additional_work_is_evaluated_together_with_the_parent_without_excluding_the_parent_twice(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldReceive('evaluatePlans')->once()->andReturnUsing(function ($plans) use ($shoot) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertCount(2, $plans);
            $this->assertSame($shoot->id, $plans[0]['shoot']->id);
            $this->assertNull($plans[1]['shoot']);
            $this->assertSame($shoot->id, $plans[1]['payload']['source_shoot_id']);
            $this->assertSame(15, $plans[1]['payload']['_schedule_visits'][0]['duration_minutes']);
            return ['enabled' => true];
        });
        $engine->shouldReceive('assertResult')->andReturnUsing(fn () => throw new PublicApiResponseException(response()->json(['message' => 'Batch travel conflict'], 422)));
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $this->patchJson("/api/shoots/{$shoot->id}", ['address' => '501 Changed Avenue', 'complimentary_service_options' => [
            'idempotency_key' => (string) Str::uuid(), 'reason_code' => 'company_error',
            'pay_photographer' => false, 'pay_sales_rep' => false, 'scheduled_at' => '2026-10-16T15:00:00Z',
            'photographer_id' => $shoot->photographer_id, 'service_items' => [[
                'source_shoot_service_id' => $shoot->serviceItems()->sole()->id, 'service_id' => $service->id,
            ]],
        ]])->assertUnprocessable()->assertJsonPath('message', 'Batch travel conflict');
        $this->assertSame(1, Shoot::count());
    }

    public function test_canonical_windows_include_secondary_assignments_and_ignore_unchanged_notes(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $secondary = User::factory()->photographer()->create();
        $other = Service::factory()->create(['photographer_required' => true]);
        $shoot->services()->attach($other->id, ['duration_minutes' => 23, 'photographer_id' => $secondary->id,
            'scheduled_at' => '2026-10-15 16:00:00', 'workflow_status' => 'scheduled']);
        $shoot = $shoot->fresh();
        $planner = app(WriteSchedulePlan::class);
        $plan = $planner->services(['company_notes' => 'Only a note', '_schedule_visits' => [['duration_minutes' => 999]]],
            $planner->storedServices($shoot), $shoot->scheduled_at, $shoot->photographer_id, $shoot->timezone, 'update');
        $this->assertSame([$shoot->photographer_id, $secondary->id], array_column($plan['_schedule_visits'], 'photographer_id'));
        $this->assertSame([17, 23], array_column($plan['_schedule_visits'], 'duration_minutes'));
        $this->assertFalse($planner->changesItinerary($plan, $shoot, $admin));
        $plan['_schedule_visits'][1]['duration_minutes'] = 24;
        $this->assertTrue($planner->changesItinerary($plan, $shoot, $admin));
    }

    public function test_unchanged_parent_remains_an_existing_neighbor_for_a_new_return_visit(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldReceive('evaluate')->once()->andReturnUsing(function ($payload, $excluded) use ($shoot) {
            $this->assertNull($excluded);
            $this->assertSame('additional_work', $payload['action_mode']);
            $this->assertSame($shoot->id, $payload['source_shoot_id']);
            return ['enabled' => true];
        });
        $engine->shouldReceive('assertResult')->andReturnUsing(fn () => throw new PublicApiResponseException(response()->json(['message' => 'Travel blocked'], 422)));
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $this->patchJson("/api/shoots/{$shoot->id}", ['company_notes' => 'Keep the original visit', 'complimentary_service_options' => [
            'idempotency_key' => (string) Str::uuid(), 'reason_code' => 'company_error',
            'pay_photographer' => false, 'pay_sales_rep' => false, 'scheduled_at' => '2026-10-16T15:00:00Z',
            'photographer_id' => $shoot->photographer_id, 'service_items' => [[
                'source_shoot_service_id' => $shoot->serviceItems()->sole()->id, 'service_id' => $service->id,
            ]],
        ]])->assertUnprocessable()->assertJsonPath('message', 'Travel blocked');
        $this->assertSame(1, Shoot::count());
    }

    public function test_client_request_intake_does_not_commit_a_confirmed_travel_slot(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldNotReceive('evaluate');
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $client = $shoot->client;
        $client->forceFill(['email_verification_required_at' => null])->saveQuietly();
        Sanctum::actingAs($client);
        $this->postJson('/api/shoots', ['address' => '500 Test Avenue', 'city' => 'Baltimore',
            'state' => 'MD', 'zip' => '21201', 'scheduled_at' => '2026-10-16T15:00:00Z',
            'photographer_id' => $shoot->photographer_id, 'services' => [['id' => $service->id, 'quantity' => 1]]])
            ->assertCreated();
        $request = Shoot::orderByDesc('id')->first();
        $this->assertSame('requested', $request->status);
        $this->assertSame('requested', $request->workflow_status);
    }

    public function test_ai_booking_checks_the_exact_local_appointment_before_writing(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldReceive('evaluate')->once()->andReturnUsing(function ($payload) {
            $this->assertSame(0, DB::transactionLevel());
            $visit = $payload['_schedule_visits'][0];
            $this->assertSame('2026-10-16T10:00:00-04:00', $visit['scheduled_at']);
            $this->assertSame('America/New_York', $visit['timezone']);
            $this->assertTrue($payload['travel_override_confirmed']);
            return ['enabled' => true];
        });
        $engine->shouldReceive('assertResult')->andReturnUsing(fn () => throw new PublicApiResponseException(response()->json(['message' => 'Travel blocked'], 422)));
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $result = app(\App\Services\ReproAi\Tools\BookingTools::class)->bookShoot([
            'address' => '500 Test Avenue', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'date' => '2026-10-16', 'time' => '10:00', 'timezone' => 'America/New_York',
            'photographer_id' => $shoot->photographer_id, 'services' => [$service->id],
            'travel_override' => true, 'travel_override_confirmed' => true, 'travel_override_reason' => 'Staff reviewed the travel warning',
        ], ['user_id' => $shoot->client_id]);
        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status_code']);
        $this->assertSame(1, Shoot::count());
    }

    public function test_real_guard_enforces_travel_despite_legacy_skip_and_commits_an_available_slot(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $location = Mockery::mock(\App\Services\Scheduling\TravelLocationResolver::class);
        $location->shouldReceive('reset')->andReturnNull();
        $location->shouldReceive('forPayload', 'forShoot')->andReturn(['verified' => true, 'complete' => true, 'building_key' => 'candidate']);
        $location->shouldReceive('persistedMetadata')->andReturn(['signature' => 'trusted-result']);
        $this->app->instance(\App\Services\Scheduling\TravelLocationResolver::class, $location);
        $travel = Mockery::mock(\App\Services\Scheduling\TravelTimeEstimator::class);
        $travel->shouldReceive('reset')->andReturnNull();
        $travel->shouldReceive('estimate')->andReturn(['source' => 'distance_band', 'required_minutes' => 30,
            'review_required' => false, 'reason_code' => null, 'drive_minutes' => null, 'distance_miles' => 5]);
        $this->app->instance(\App\Services\Scheduling\TravelTimeEstimator::class, $travel);
        $payload = ['client_id' => $shoot->client_id, 'address' => '501 Other Avenue', 'city' => 'Baltimore',
            'state' => 'MD', 'zip' => '21201', 'photographer_id' => $shoot->photographer_id,
            'timezone' => 'America/New_York', 'scheduled_at' => '2026-10-15T14:20:00Z',
            'services' => [['id' => $service->id, 'quantity' => 1]], 'skip_availability_check' => true];
        $this->postJson('/api/shoots', $payload)->assertUnprocessable()
            ->assertJsonPath('feasibility.reason_codes.0', 'insufficient_travel_time');
        $this->assertSame(1, Shoot::count());
        $unconfirmed = $payload + ['travel_override' => true, 'travel_override_reason' => 'The photographer reviewed this gap'];
        $this->postJson('/api/shoots', $unconfirmed)->assertUnprocessable()->assertJsonValidationErrors('travel_override_confirmed');
        $this->postJson('/api/shoots', array_merge($unconfirmed, ['travel_override_confirmed' => true, 'travel_override_reason' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('travel_override_reason');
        $payload['scheduled_at'] = '2026-10-15T14:50:00Z';
        $payload['services'][0]['scheduled_at'] = '2026-10-15T14:50:00Z';
        $id = $this->postJson('/api/shoots', $payload)->assertCreated()->json('data.id');
        $saved = Shoot::findOrFail($id);
        $this->assertSame('2026-10-15 14:50:00', $saved->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(15, $saved->serviceItems()->sole()->duration_minutes);
        $this->assertSame('2026-10-15 14:50:00', $saved->serviceItems()->sole()->getRawOriginal('scheduled_at'));
        $this->assertSame(['signature' => 'trusted-result'], $saved->property_details['schedule_location']);
        $freshWarning = $this->postJson('/api/shoots', $unconfirmed + ['travel_override_confirmed' => true])->assertUnprocessable()
            ->assertJsonValidationErrors('travel_override_confirmation_version')->json('feasibility');
        $preview = $this->postJson('/api/photographer/availability/feasibility', $unconfirmed)->assertOk()->json('data');
        $this->assertSame($freshWarning['confirmation_version'], $preview['confirmation_version'],
            'Normal preview input and canonical write windows must bind the same warning.');
        $exceptionId = $this->postJson('/api/shoots', $unconfirmed + ['travel_override_confirmed' => true,
            'travel_override_confirmation_version' => $preview['confirmation_version']])->assertCreated()->json('data.id');
        $audit = \App\Models\UserActivityLog::where('event_type', 'schedule.travel_override')->sole();
        $this->assertSame([(int) $exceptionId], $audit->metadata['shoot_ids']);
        $this->assertTrue($audit->metadata['confirmed']);
    }

    public function test_unchanged_service_echo_does_not_recheck_historical_working_hours(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldNotReceive('evaluate');
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $availability = Mockery::mock(PhotographerAvailabilityService::class)->makePartial();
        $availability->shouldNotReceive('assertWithinAvailabilityBounds');
        $this->app->instance(PhotographerAvailabilityService::class, $availability);
        $this->patchJson("/api/shoots/{$shoot->id}", ['company_notes' => 'An updated note',
            'services' => [['id' => $service->id, 'quantity' => 1, 'photographer_id' => $shoot->photographer_id,
                'duration_minutes' => 17, 'scheduled_at' => '2026-10-15T14:00:00Z']]])->assertOk();
        $this->assertSame('An updated note', $shoot->fresh()->company_notes);
    }

    public function test_pending_request_edit_can_change_proposed_visit_without_reserving_it(): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        $shoot->forceFill(['status' => 'requested', 'workflow_status' => 'requested'])->saveQuietly();
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldNotReceive('evaluate');
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $this->patchJson("/api/shoots/{$shoot->id}", ['address' => 'An unverified requested address',
            'services' => [['id' => $service->id, 'quantity' => 1, 'photographer_id' => $shoot->photographer_id,
                'duration_minutes' => 17, 'scheduled_at' => '2026-10-16T14:00:00Z']]])->assertOk();
        $this->assertSame('requested', $shoot->fresh()->status);
    }

    public function test_rep_saves_completed_visit_address_staging_and_discount_together_without_rechecking_travel(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-15T20:00:00Z'));
        [$admin, $shoot, $service] = $this->fixture();
        $rep = User::factory()->create(['role' => 'salesRep', 'email_verification_required_at' => null]);
        Sanctum::actingAs($rep);
        $shoot->forceFill(['rep_id' => $rep->id, 'status' => 'uploaded', 'workflow_status' => 'uploaded',
            'photos_uploaded_at' => now(), 'editor_notes' => 'Primary bedroom and two living/dining angles',
            'state' => 'MD', 'tax_region' => 'MD', 'tax_percent' => 6])->saveQuietly();
        $staging = Service::factory()->noIntake()->create(['price' => 45, 'allow_multiple' => true,
            'photographer_required' => false]);
        $this->postJson('/api/photographer/availability/feasibility', ['shoot_id' => $shoot->id,
            'action_mode' => 'update', 'address' => '1 Stonehenge Circle',
            'services' => [['id' => $service->id], ['id' => $staging->id, 'quantity' => 3]]])
            ->assertOk()->assertJsonPath('data.available', true);
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldNotReceive('evaluate');
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $availability = Mockery::mock(PhotographerAvailabilityService::class)->makePartial();
        $availability->shouldNotReceive('assertWithinAvailabilityBounds');
        $this->app->instance(PhotographerAvailabilityService::class, $availability);
        $this->patchJson("/api/shoots/{$shoot->id}", ['address' => '1 Stonehenge Circle',
            'services' => [['id' => $service->id], ['id' => $staging->id, 'quantity' => 3]],
            'discount_type' => 'percent', 'discount_value' => 10,
            'notify_client' => false, 'notify_photographer' => false])->assertOk();
        $saved = $shoot->fresh();
        $this->assertSame('1 Stonehenge Circle', $saved->address);
        $this->assertSame('2026-10-15 14:00:00', $saved->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('uploaded', $saved->workflow_status);
        $this->assertSame('Primary bedroom and two living/dining angles', $saved->editor_notes);
        $this->assertSame(3, (int) $saved->serviceItems()->where('service_id', $staging->id)->sole()->quantity);
        $this->assertSame(45.0, (float) $saved->serviceItems()->where('service_id', $staging->id)->sole()->price);
        $this->assertSame('percent', $saved->discount_type);
        $this->assertSame(10.0, (float) $saved->discount_value);
        $this->assertSame(23.5, (float) $saved->discount_amount);
        $this->assertSame(224.19, (float) $saved->total_quote);
        Mail::assertNothingSent();
    }

    public static function guardedCompletedVisitEdits(): array
    {
        return ['not uploaded' => ['scheduled', null, '2026-10-15T20:00:00Z', 'address'],
            'future capture' => ['uploaded', '2026-10-15T12:00:00Z', '2026-10-15T13:00:00Z', 'address'],
            'missing completion evidence' => ['uploaded', null, '2026-10-15T20:00:00Z', 'address'],
            'reschedule' => ['uploaded', '2026-10-15T15:00:00Z', '2026-10-15T20:00:00Z', 'reschedule'],
            'additional capture service' => ['uploaded', '2026-10-15T15:00:00Z', '2026-10-15T20:00:00Z', 'capture']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guardedCompletedVisitEdits')]
    public function test_completion_exception_does_not_bypass_real_schedule_changes(string $status, ?string $uploadedAt, string $clock, string $edit): void
    {
        $this->travelTo(\Carbon\Carbon::parse($clock));
        [$admin, $shoot, $service] = $this->fixture();
        $shoot->forceFill(['status' => $status, 'workflow_status' => $status, 'photos_uploaded_at' => $uploadedAt,
            'completed_at' => null])->saveQuietly();
        $payload = ['address' => 'Changed building'];
        if ($edit === 'reschedule') $payload['scheduled_at'] = '2026-10-16T14:00:00Z';
        if ($edit === 'capture') {
            $additional = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 45]);
            $payload['services'] = [['id' => $service->id], ['id' => $additional->id]];
        }
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldReceive('evaluate')->once()->andReturn(['enabled' => true]);
        $engine->shouldReceive('assertResult')->once()->andThrow(new PublicApiResponseException(response()->json(['message' => 'Travel still requires review'], 422)));
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $originalAddress = $shoot->address;
        $this->patchJson("/api/shoots/{$shoot->id}", $payload)->assertUnprocessable()->assertJsonPath('message', 'Travel still requires review');
        $this->assertSame($originalAddress, $shoot->fresh()->address);
        $this->assertSame(1, $shoot->serviceItems()->count());
    }

    public static function aiScheduleTypes(): array
    {
        return ['zoned single property' => [false, false], 'legacy single property' => [true, false],
            'zoned repeated unit service' => [false, true], 'legacy repeated unit service' => [true, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('aiScheduleTypes')]
    public function test_ai_update_evaluates_and_persists_inherited_local_times_with_split_visit_offsets(bool $legacy, bool $multiUnit): void
    {
        [$admin, $shoot, $service] = $this->fixture();
        User::findOrFail($shoot->photographer_id)->forceFill(['timezone' => 'America/New_York'])->saveQuietly();
        if ($legacy) {
            $shoot->forceFill(['timezone' => null, 'scheduled_at' => '2026-10-15 10:00:00'])->saveQuietly();
            $shoot->serviceItems()->sole()->update(['scheduled_at' => '2026-10-15 10:00:00']);
        }
        $unitId = null;
        if ($multiUnit) {
            $firstUnit = $shoot->units()->create(['client_key' => 'unit-1', 'label' => 'Unit 1', 'kind' => 'unit']);
            $secondUnit = $shoot->units()->create(['client_key' => 'unit-2', 'label' => 'Unit 2', 'kind' => 'unit']);
            $shoot->serviceItems()->sole()->update(['shoot_unit_id' => $firstUnit->id, 'client_key' => 'line-1']);
            $unitId = $secondUnit->id;
        }
        $other = $multiUnit ? $service : Service::factory()->create(['photographer_required' => true]);
        $shoot->services()->attach($other->id, ['photographer_id' => $shoot->photographer_id,
            'shoot_unit_id' => $unitId, 'client_key' => $multiUnit ? 'line-2' : null,
            'scheduled_at' => $legacy ? '2026-10-15 12:00:00' : '2026-10-15 16:00:00', 'duration_minutes' => 23, 'is_deliverable' => true,
            'workflow_status' => 'scheduled']);
        $engine = Mockery::mock(ScheduleFeasibilityService::class);
        $engine->shouldReceive('evaluate')->once()->andReturnUsing(function ($payload) use ($shoot) {
            $visits = $payload['_schedule_visits'];
            $this->assertTrue($payload['travel_override_confirmed']);
            $this->assertSame(['2026-10-16 14:00:00', '2026-10-16 16:00:00'],
                array_map(fn ($visit) => \Carbon\Carbon::parse($visit['scheduled_at'])->utc()->format('Y-m-d H:i:s'), $visits));
            $this->assertSame([17, 23], array_column($visits, 'duration_minutes'));
            return ['enabled' => true, 'status' => 'available', 'schedule_version' => 'version',
                'photographer_ids' => [$shoot->photographer_id], 'location' => []];
        });
        $engine->shouldReceive('assertResult')->once()->andReturnNull();
        $engine->shouldReceive('scheduleFingerprint')->twice()->andReturn('version');
        $this->app->instance(ScheduleFeasibilityService::class, $engine);
        $saved = app(\App\Services\ReproAi\ShootService::class)->updateFromAiConversation($shoot->fresh(), [
            'date' => '2026-10-16', 'time_window' => '10:00',
            'travel_override' => true, 'travel_override_confirmed' => true, 'travel_override_reason' => 'Staff reviewed the travel warning',
        ], $admin);
        $this->assertSame($legacy ? '2026-10-16 10:00:00' : '2026-10-16 14:00:00', $saved->getRawOriginal('scheduled_at'));
        $this->assertSame('10:00:00', $saved->time);
        $this->assertSame($legacy ? ['2026-10-16 10:00:00', '2026-10-16 12:00:00'] : ['2026-10-16 14:00:00', '2026-10-16 16:00:00'],
            $saved->serviceItems()->orderBy('id')->get()->map(fn ($item) => $item->getRawOriginal('scheduled_at'))->all());
        if ($multiUnit) {
            $this->assertSame(2, $saved->units()->count());
            $this->assertSame(2, $saved->serviceItems()->where('service_id', $service->id)->count());
            $this->assertSame(1, $saved->units_revision);
            try {
                app(\App\Services\ReproAi\ShootService::class)->updateFromAiConversation($saved, ['service_ids' => [$service->id]], $admin);
                $this->fail('A catalog-only payload cannot replace booked unit lines.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('services', $exception->errors());
            }
        }
    }
}
