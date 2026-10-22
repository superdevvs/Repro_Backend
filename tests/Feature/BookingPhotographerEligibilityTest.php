<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\AddressLookupService;
use App\Services\Scheduling\TravelLocationResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class BookingPhotographerEligibilityTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    private User $admin;

    private User $client;

    private User $photographer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('q', 32)), 'availability.hybrid_travel_enabled' => true, 'availability.radius_enforcement' => true,
            'availability.scheduling_lock_store' => 'array', 'app.timezone' => 'UTC']);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->admin()->create(['email_verification_required_at' => null]);
        $this->client = User::factory()->create(['role' => 'client', 'email_verification_required_at' => null]);
        $category = Category::firstOrCreate(['name' => 'Verified Photo Capture']);
        $this->service = Service::factory()->create(['name' => 'Verified Photo Capture', 'category_id' => $category->id,
            'photographer_required' => true, 'shoot_duration_minutes' => 30]);
        $this->photographer = User::factory()->photographer()->create(['timezone' => 'UTC',
            'address' => '1 Home Street', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'metadata' => ['specialties' => ['category:'.$category->id], 'travel_range' => 20,
                'latitude' => 39.3, 'longitude' => -76.6]]);
        Sanctum::actingAs($this->admin);
        $locations = Mockery::mock(TravelLocationResolver::class);
        $locations->shouldReceive('reset')->andReturnNull();
        $locations->shouldReceive('forPayload', 'forShoot')->andReturn([
            'verified' => true, 'complete' => true, 'precision' => 'exact', 'building_key' => 'destination',
            'address_hash' => 'destination', 'address' => '100 Property Street', 'city' => 'Baltimore',
            'state' => 'MD', 'zip' => '21201', 'latitude' => 39.31, 'longitude' => -76.61]);
        $locations->shouldReceive('persistedMetadata')->andReturn([]);
        $this->app->instance(TravelLocationResolver::class, $locations);
        $distance = Mockery::mock(AddressLookupService::class);
        $distance->shouldReceive('getDistance')->andReturn(['distance_value' => 30 * 1609.34]);
        $this->app->instance(AddressLookupService::class, $distance);
    }

    private function payload(): array
    {
        return ['client_id' => $this->client->id, 'photographer_id' => $this->photographer->id,
            'address' => '100 Property Street', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'scheduled_at' => '2031-10-09T10:00:00Z', 'timezone' => 'UTC',
            'services' => [['id' => $this->service->id, 'quantity' => 1]],
            'notify_client' => false, 'notify_photographer' => false];
    }

    private function bookingPayload(): array
    {
        return ['date' => '2031-10-09', 'time' => '10:00', 'shoot_address' => '100 Property Street',
            'shoot_city' => 'Baltimore', 'shoot_state' => 'MD', 'shoot_zip' => '21201',
            'service_ids' => [$this->service->id], 'photographer_ids' => [$this->photographer->id]];
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['superadmin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_capability_cannot_be_overridden_by_staff(string $role): void
    {
        $this->admin->update(['role' => $role]);
        $this->photographer->update(['metadata' => ['specialties' => ['category:999999'], 'travel_range' => 20]]);
        $this->postJson('/api/shoots', $this->payload() + ['skip_availability_check' => $role !== 'salesRep',
            'travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_confirmation_version' => str_repeat('a', 64), 'travel_override_reason' => 'Staff confirmed location.'])
            ->assertUnprocessable()->assertJsonValidationErrors('services.0.photographer_id');
        $this->assertDatabaseCount('shoots', 0);
        Mail::assertNothingSent();
    }

    public function test_unconfigured_profile_is_not_verified_even_for_a_client_request(): void
    {
        Sanctum::actingAs($this->client);
        $this->photographer->update(['metadata' => []]);
        $this->postJson('/api/shoots', $this->payload())->assertUnprocessable()
            ->assertJsonValidationErrors('services.0.photographer_id');
        $this->assertDatabaseCount('shoots', 0);
    }

    public function test_location_exception_only_restores_qualified_photographers(): void
    {
        $this->postJson('/api/photographer/availability/for-booking', $this->bookingPayload())->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/photographer/availability/for-booking', $this->bookingPayload() + ['include_location_exceptions' => true])
            ->assertOk()->assertJsonPath('data.0.location_exception_required', true);
        $this->photographer->update(['metadata' => []]);
        $this->postJson('/api/photographer/availability/for-booking', $this->bookingPayload() + ['include_location_exceptions' => true])
            ->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->client);
        $this->postJson('/api/photographer/availability/for-booking', $this->bookingPayload() + ['include_location_exceptions' => true])->assertForbidden();
    }

    public function test_radius_requires_signed_staff_confirmation_and_is_recorded(): void
    {
        $preview = $this->postJson('/api/photographer/availability/feasibility', $this->payload() + ['action_mode' => 'create'])
            ->assertOk()->assertJsonPath('data.can_override', true)
            ->assertJsonPath('data.radius_checks.0.distance_miles', 30)
            ->assertJsonPath('data.radius_checks.0.radius_miles', 20)->json('data');
        $this->assertContains('outside_service_radius', $preview['reason_codes']);
        $this->postJson('/api/shoots', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $this->postJson('/api/shoots', $this->payload() + ['travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_confirmation_version' => $preview['confirmation_version'],
            'travel_override_reason' => 'Photographer agreed to this longer drive.'])->assertCreated();
        $this->assertDatabaseCount('shoots', 1);
        Mail::assertNothingSent();
    }

    public function test_previous_service_visit_and_home_territory_are_independent_radius_checks(): void
    {
        $this->photographer->update(['metadata' => array_merge($this->photographer->metadata, ['travel_range' => 40])]);
        $other = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'photographer_id' => $other->id,
            'scheduled_at' => '2031-10-09 08:00:00', 'timezone' => 'UTC', 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $shoot->services()->attach($this->service->id, ['photographer_id' => $this->photographer->id,
            'scheduled_at' => '2031-10-09 09:00:00', 'duration_minutes' => 30, 'workflow_status' => 'scheduled']);
        // The target must pass home territory and the independent previous-visit check.
        $this->postJson('/api/photographer/availability/feasibility', $this->payload() + ['action_mode' => 'create'])
            ->assertOk()->assertJsonPath('data.radius_checks.0.origin', 'home')
            ->assertJsonPath('data.radius_checks.0.distance_miles', 30)
            ->assertJsonPath('data.radius_checks.1.origin', 'previous_shoot')
            ->assertJsonPath('data.radius_checks.1.distance_miles', 0)->assertJsonPath('data.available', true);
        $this->photographer->update(['metadata' => array_merge($this->photographer->metadata, ['travel_range' => 20])]);
        $this->postJson('/api/photographer/availability/feasibility', $this->payload() + ['action_mode' => 'create'])
            ->assertOk()->assertJsonPath('data.radius_checks.0.eligible', false)
            ->assertJsonPath('data.radius_checks.1.eligible', true)->assertJsonPath('data.available', false);
    }

    private function existingShoot(): Shoot
    {
        $shoot = Shoot::factory()->create(['client_id' => $this->client->id, 'photographer_id' => $this->photographer->id,
            'scheduled_at' => '2031-10-09 10:00:00', 'scheduled_date' => '2031-10-09', 'time' => '10:00',
            'timezone' => 'UTC', 'status' => 'scheduled', 'workflow_status' => 'scheduled']);
        $shoot->services()->attach($this->service->id, ['photographer_id' => $this->photographer->id,
            'scheduled_at' => '2031-10-09 10:00:00', 'duration_minutes' => 30, 'workflow_status' => 'scheduled']);

        return $shoot;
    }

    public function test_metadata_only_edit_does_not_reject_a_historical_unconfigured_assignment(): void
    {
        $shoot = $this->existingShoot();
        $this->photographer->update(['metadata' => []]);
        $this->patchJson("/api/shoots/{$shoot->id}", ['property_details' => ['bedrooms' => 3], 'company_notes' => 'Confirmed property details.'])
            ->assertOk();
        $this->patchJson("/api/shoots/{$shoot->id}", ['services' => [['id' => $this->service->id, 'quantity' => 1]],
            'company_notes' => 'Same services echoed by the edit form.'])->assertOk();
        $this->assertSame($this->photographer->id, (int) $shoot->fresh()->photographer_id);
        $this->assertSame('2031-10-09 10:00:00', $shoot->fresh()->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_approval_reschedule_update_and_assignment_reject_unsupported_categories(): void
    {
        $shoot = $this->existingShoot();
        $this->photographer->update(['metadata' => ['specialties' => ['category:999999']]]);
        $this->patchJson("/api/shoots/{$shoot->id}", ['scheduled_at' => '2031-10-09T11:00:00Z', 'skip_availability_check' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('services.0.photographer_id');
        $this->postJson("/api/shoots/{$shoot->id}/reschedule", ['requested_date' => '2031-10-09', 'requested_time' => '11:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('services.0.photographer_id');
        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", ['service_id' => $this->service->id,
            'photographer_id' => $this->photographer->id, 'override' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('services.0.photographer_id');
        $shoot->forceFill(['status' => 'requested', 'workflow_status' => 'requested'])->saveQuietly();
        $this->postJson("/api/shoots/{$shoot->id}/approve", ['scheduled_at' => '2031-10-09T11:00:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors('services.0.photographer_id');
    }

    public function test_radius_exception_cannot_override_an_actual_overlap(): void
    {
        $this->existingShoot();
        $this->postJson('/api/photographer/availability/feasibility', $this->payload() + ['action_mode' => 'create'])
            ->assertOk()->assertJsonPath('data.can_override', false)->assertJsonPath('data.available', false);
    }

    public function test_profile_edits_invalidate_a_prepared_schedule(): void
    {
        $repository = app(\App\Services\Scheduling\ScheduleVisitRepository::class);
        $before = $repository->fingerprint([$this->photographer->id]);
        $this->photographer->update(['metadata' => ['specialties' => ['category:999999']]]);
        $this->assertNotSame($before, $repository->fingerprint([$this->photographer->id]));
    }

    public function test_assignment_accepts_the_same_signed_location_exception_and_rejects_unsigned_legacy_override(): void
    {
        $shoot = $this->existingShoot();
        $assignment = ['service_id' => $this->service->id, 'photographer_id' => $this->photographer->id];
        $preview = $this->postJson('/api/photographer/availability/feasibility', ['shoot_id' => $shoot->id,
            'action_mode' => 'assign', 'service_photographers' => [$assignment]])->assertOk()->json('data');
        $this->assertTrue($preview['can_override']);
        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", $assignment + ['override' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('travel_schedule');
        $this->postJson("/api/shoots/{$shoot->id}/assign-service-photographer", $assignment + [
            'travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_confirmation_version' => $preview['confirmation_version'],
            'travel_override_reason' => 'Photographer confirmed this assignment outside their radius.',
        ])->assertOk();
        $this->assertDatabaseHas('user_activity_logs', ['event_type' => 'schedule.travel_override', 'actor_user_id' => $this->admin->id]);
    }

    public function test_picker_does_not_use_an_old_parent_anchor_before_the_actual_service_visit(): void
    {
        $shoot = $this->existingShoot();
        $shoot->update(['scheduled_at' => '2031-10-09 08:00:00', 'time' => '08:00']);
        $shoot->serviceItems()->update(['scheduled_at' => '2031-10-09 11:00:00']);
        $this->postJson('/api/photographer/availability/for-booking', $this->bookingPayload() + ['include_location_exceptions' => true])
            ->assertOk()->assertJsonPath('data.0.distance_from', 'home')->assertJsonPath('data.0.previous_shoot_id', null);
    }

    public function test_each_service_can_have_its_own_qualified_specialist(): void
    {
        $category = Category::firstOrCreate(['name' => 'Verified Video Capture']);
        $video = Service::factory()->create(['category_id' => $category->id, 'photographer_required' => true, 'shoot_duration_minutes' => 30]);
        $specialist = User::factory()->photographer()->create(['timezone' => 'UTC',
            'metadata' => ['specialties' => ['category:'.$category->id], 'travel_range' => 40,
                'address' => '2 Specialist Street', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201']]);
        $this->photographer->update(['metadata' => array_merge($this->photographer->metadata, ['travel_range' => 40])]);
        $this->postJson('/api/shoots', array_replace($this->payload(), ['services' => [
            ['id' => $this->service->id, 'photographer_id' => $this->photographer->id, 'quantity' => 1],
            ['id' => $video->id, 'photographer_id' => $specialist->id, 'quantity' => 1],
        ]]))->assertCreated();
        $this->assertDatabaseHas('shoot_service', ['service_id' => $video->id, 'photographer_id' => $specialist->id]);
        Mail::assertNothingSent();
    }

    private function qualifiedPhotographer(Service $service): User
    {
        return User::factory()->photographer()->create(['timezone' => 'UTC', 'address' => '2 Qualified Street',
            'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'metadata' => ['specialties' => ['category:'.$service->category_id], 'travel_range' => 40,
                'latitude' => 39.3, 'longitude' => -76.6]]);
    }

    private function reassignmentFixture(bool $multiUnit): array
    {
        $shoot = $this->existingShoot();
        $otherPhoto = Service::factory()->create(['category_id' => $this->service->category_id,
            'photographer_required' => true, 'shoot_duration_minutes' => 30]);
        $videoCategory = Category::firstOrCreate(['name' => 'Independent Video Capture']);
        $video = Service::factory()->create(['category_id' => $videoCategory->id,
            'photographer_required' => true, 'shoot_duration_minutes' => 30]);
        $specialist = $this->qualifiedPhotographer($video);
        $newMain = $this->qualifiedPhotographer($this->service);
        $shoot->services()->attach($otherPhoto->id, ['photographer_id' => null, 'price' => 125,
            'quantity' => 1, 'duration_minutes' => 30, 'scheduled_at' => '2031-10-09 10:00:00',
            'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);
        $shoot->services()->attach($video->id, ['photographer_id' => $specialist->id, 'price' => 225,
            'quantity' => 1, 'duration_minutes' => 30, 'scheduled_at' => '2031-10-09 10:00:00',
            'workflow_status' => 'scheduled', 'delivery_status' => 'not_started']);
        if ($multiUnit) {
            $unit = $shoot->units()->create(['client_key' => 'unit-1', 'label' => 'Unit 1', 'kind' => 'unit']);
            foreach ($shoot->serviceItems()->get() as $item) {
                $item->update(['shoot_unit_id' => $unit->id, 'client_key' => 'line-'.$item->id]);
            }
        }
        $this->photographer->update(['metadata' => []]);

        return [$shoot->fresh(), $newMain, $otherPhoto, $video, $specialist];
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false, 'update'])]
    #[\PHPUnit\Framework\Attributes\TestWith([true, 'update'])]
    #[\PHPUnit\Framework\Attributes\TestWith([false, 'schedule'])]
    #[\PHPUnit\Framework\Attributes\TestWith([true, 'schedule'])]
    public function test_header_only_reassignment_persists_the_checked_inherited_rows_and_preserves_a_specialist(bool $multiUnit, string $mode): void
    {
        [$shoot, $newMain, $otherPhoto, $video, $specialist] = $this->reassignmentFixture($multiUnit);
        $before = $shoot->serviceItems()->get()->keyBy('service_id');
        $beforeRevision = (int) $shoot->units_revision;
        $preview = $this->postJson('/api/photographer/availability/feasibility', ['shoot_id' => $shoot->id,
            'action_mode' => $mode, 'photographer_id' => $newMain->id])->assertOk()->assertJsonPath('data.available', true)->json('data');
        $this->assertEqualsCanonicalizing([$newMain->id, $specialist->id], array_column($preview['visits'], 'photographer_id'));
        $this->assertNotContains($this->photographer->id, array_column($preview['radius_checks'], 'photographer_id'));
        $payload = ['photographer_id' => $newMain->id, 'notify_client' => false, 'notify_photographer' => false];
        if ($mode === 'schedule') {
            $this->postJson("/api/shoots/{$shoot->id}/schedule", $payload + ['scheduled_at' => '2031-10-09T10:00:00Z',
                'expected_units_revision' => $beforeRevision])->assertOk();
        } else {
            $this->patchJson("/api/shoots/{$shoot->id}", $payload)->assertOk();
        }
        $saved = $shoot->fresh();
        $after = $saved->serviceItems()->get()->keyBy('service_id');
        $this->assertSame($newMain->id, (int) $saved->photographer_id);
        $this->assertSame($newMain->id, (int) $after[$this->service->id]->photographer_id);
        $this->assertSame($newMain->id, (int) $after[$otherPhoto->id]->photographer_id);
        $this->assertSame($before[$video->id]->getAttributes(), $after[$video->id]->getAttributes());
        foreach ([$this->service->id, $otherPhoto->id] as $serviceId) {
            foreach (['id', 'shoot_unit_id', 'client_key', 'price', 'quantity', 'duration_minutes', 'scheduled_at', 'workflow_status', 'delivery_status', 'editor_id'] as $field) {
                $this->assertSame($before[$serviceId]->getRawOriginal($field), $after[$serviceId]->getRawOriginal($field), $field);
            }
        }
        $this->assertSame($beforeRevision + ($multiUnit ? 1 : 0), (int) $saved->units_revision);
        $windows = app(\App\Services\Shoots\ShootDurationResolver::class)->windowsForShoot($saved);
        $this->assertEqualsCanonicalizing(array_column($preview['visits'], 'photographer_id'), array_column($windows, 'photographer_id'));
        if ($mode === 'update') {
            Mail::assertNothingSent();
        }
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['photographer'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['editor'])]
    public function test_assigned_media_roles_cannot_reassign_inherited_services_through_schedule(string $role): void
    {
        $shoot = $this->existingShoot();
        $actor = $role === 'photographer' ? $this->photographer
            : User::factory()->create(['role' => 'editor', 'email_verification_required_at' => null]);
        if ($role === 'editor') $shoot->update(['editor_id' => $actor->id]);
        $newMain = $this->qualifiedPhotographer($this->service);
        $before = $shoot->serviceItems()->firstOrFail()->getAttributes();
        Sanctum::actingAs($actor);

        $this->postJson("/api/shoots/{$shoot->id}/schedule", [
            'scheduled_at' => '2031-10-09T10:00:00Z', 'photographer_id' => $newMain->id,
        ])->assertForbidden();

        $this->assertSame($this->photographer->id, (int) $shoot->fresh()->photographer_id);
        $this->assertSame($before, $shoot->serviceItems()->firstOrFail()->getAttributes());
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['photographer', false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['photographer', true])]
    #[\PHPUnit\Framework\Attributes\TestWith(['editor', false])]
    #[\PHPUnit\Framework\Attributes\TestWith(['editor', true])]
    public function test_assigned_media_roles_can_still_schedule_without_reassignment(string $role, bool $echoPhotographer): void
    {
        $this->photographer->update(['metadata' => array_merge($this->photographer->metadata, ['travel_range' => 40])]);
        $shoot = $this->existingShoot();
        $actor = $role === 'photographer' ? $this->photographer
            : User::factory()->create(['role' => 'editor', 'email_verification_required_at' => null]);
        if ($role === 'editor') $shoot->update(['editor_id' => $actor->id]);
        Sanctum::actingAs($actor);
        $payload = ['scheduled_at' => '2031-10-09T10:00:00Z'];
        if ($echoPhotographer) $payload['photographer_id'] = $this->photographer->id;

        $this->postJson("/api/shoots/{$shoot->id}/schedule", $payload)->assertOk();

        $this->assertSame($this->photographer->id, (int) $shoot->fresh()->photographer_id);
        $this->assertSame($this->photographer->id, (int) $shoot->serviceItems()->firstOrFail()->photographer_id);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['editing_manager'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_authorized_booking_staff_can_reassign_inherited_services_through_schedule(string $role): void
    {
        $shoot = $this->existingShoot();
        $newMain = $this->qualifiedPhotographer($this->service);
        $this->admin->update(['role' => $role]);

        $this->postJson("/api/shoots/{$shoot->id}/schedule", [
            'scheduled_at' => '2031-10-09T10:00:00Z', 'photographer_id' => $newMain->id,
        ])->assertOk();

        $this->assertSame($newMain->id, (int) $shoot->fresh()->photographer_id);
        $this->assertSame($newMain->id, (int) $shoot->serviceItems()->firstOrFail()->photographer_id);
    }

    public function test_explicit_service_assignment_is_preserved_when_the_main_changes(): void
    {
        [$shoot, $newMain, $otherPhoto, $video, $specialist] = $this->reassignmentFixture(false);
        $explicitPhotographer = $this->qualifiedPhotographer($this->service);
        $payload = ['photographer_id' => $newMain->id, 'service_items' => [
            ['service_id' => $this->service->id, 'photographer_id' => $explicitPhotographer->id],
        ], 'notify_client' => false, 'notify_photographer' => false];
        $preview = $this->postJson('/api/photographer/availability/feasibility', $payload + ['shoot_id' => $shoot->id,
            'action_mode' => 'update'])->assertOk()->assertJsonPath('data.available', true)->json('data');
        $this->assertEqualsCanonicalizing([$newMain->id, $explicitPhotographer->id, $specialist->id], array_column($preview['visits'], 'photographer_id'));
        $this->patchJson("/api/shoots/{$shoot->id}", $payload)->assertOk();
        $after = $shoot->serviceItems()->get()->keyBy('service_id');
        $this->assertSame($explicitPhotographer->id, (int) $after[$this->service->id]->photographer_id);
        $this->assertSame($newMain->id, (int) $after[$otherPhoto->id]->photographer_id);
        $this->assertSame($specialist->id, (int) $after[$video->id]->photographer_id);
    }

    public function test_an_explicit_incapable_service_assignment_is_rejected_instead_of_silently_replaced(): void
    {
        [$shoot, $newMain] = $this->reassignmentFixture(false);
        $payload = ['photographer_id' => $newMain->id, 'service_photographers' => [
            ['service_id' => $this->service->id, 'photographer_id' => $this->photographer->id],
        ]];
        $this->postJson('/api/photographer/availability/feasibility', $payload + ['shoot_id' => $shoot->id,
            'action_mode' => 'update'])->assertUnprocessable()->assertJsonValidationErrors('services.0.photographer_id');
        $this->patchJson("/api/shoots/{$shoot->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors('services.0.photographer_id');
        $this->assertSame($this->photographer->id, (int) $shoot->fresh()->photographer_id);
    }

    public function test_explicit_null_service_assignment_remains_null_and_inherits_the_qualified_new_main(): void
    {
        [$shoot, $newMain, $otherPhoto] = $this->reassignmentFixture(false);
        $payload = ['photographer_id' => $newMain->id, 'service_items' => [
            ['service_id' => $this->service->id, 'photographer_id' => null],
        ], 'notify_client' => false, 'notify_photographer' => false];
        $this->postJson('/api/photographer/availability/feasibility', $payload + ['shoot_id' => $shoot->id,
            'action_mode' => 'update'])->assertOk()->assertJsonPath('data.available', true);
        $this->patchJson("/api/shoots/{$shoot->id}", $payload)->assertOk();
        $after = $shoot->serviceItems()->get()->keyBy('service_id');
        $this->assertNull($after[$this->service->id]->photographer_id);
        $this->assertSame($newMain->id, (int) $after[$otherPhoto->id]->photographer_id);
        $this->assertSame($newMain->id, (int) $shoot->fresh()->photographer_id);
    }

    public function test_non_photographer_services_are_exempt_and_legacy_service_ids_are_verified(): void
    {
        $eligibility = app(\App\Services\Photographers\PhotographerServiceEligibility::class);
        $this->photographer->setServiceCapabilities([(string) $this->service->id]);
        $this->photographer->save();
        $eligibility->assertServices([['id' => $this->service->id]], $this->photographer->id);
        $this->service->update(['photographer_required' => false]);
        $this->photographer->update(['metadata' => []]);
        $eligibility->assertServices([['id' => $this->service->id]], $this->photographer->id);
        $this->assertTrue($eligibility->canPerform($this->photographer, $this->service));
    }
}
