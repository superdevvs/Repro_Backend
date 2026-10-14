<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Payment;
use App\Models\PaymentServiceAllocation;
use App\Models\Shoot;
use App\Models\User;
use App\Services\PhotographerAvailabilityService;
use App\Services\Scheduling\ScheduleCommitGuard;
use App\Services\Scheduling\TemporaryStaffEditForceSave;
use App\Services\Scheduling\TravelLocationResolver;
use App\Services\Scheduling\TravelTimeEstimator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TemporaryStaffEditForceSaveTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    private Shoot $shoot;
    private Service $service;
    private User $actor;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => '0123456789abcdef0123456789abcdef', 'availability.hybrid_travel_enabled' => true,
            'availability.temporary_staff_shoot_force_save' => true,
            'availability.scheduling_lock_store' => 'array']);
        Queue::fake(); Mail::fake(); Http::preventStrayRequests();
        $availability = Mockery::mock(PhotographerAvailabilityService::class)->makePartial();
        $availability->shouldReceive('assertWithinAvailabilityBounds')->andReturnUsing(function ($id, $at, $minutes) {
            if ($minutes > 75) throw ValidationException::withMessages(['start_time' => 'Outside photographer working hours.']);
        });
        $this->app->instance(PhotographerAvailabilityService::class, $availability);
        $location = Mockery::mock(TravelLocationResolver::class);
        $location->shouldReceive('reset')->andReturnNull();
        $location->shouldReceive('forPayload', 'forShoot')->andReturn(['verified' => true,
            'address_hash' => 'same-property', 'building_key' => 'same-property']);
        $location->shouldReceive('persistedMetadata')->andReturn([]);
        $this->app->instance(TravelLocationResolver::class, $location);
        $travel = Mockery::mock(TravelTimeEstimator::class);
        $travel->shouldReceive('reset')->andReturnNull();
        $travel->shouldReceive('estimate')->andReturn(['source' => 'unknown', 'required_minutes' => null,
            'review_required' => true, 'reason_code' => 'past_departure', 'drive_minutes' => null,
            'distance_miles' => null]);
        $this->app->instance(TravelTimeEstimator::class, $travel);
        $this->actor = User::factory()->create(['role' => 'salesRep', 'email_verification_required_at' => null]);
        Sanctum::actingAs($this->actor);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $this->service = Service::factory()->create(['name' => 'HDR Photos', 'price' => 275,
            'photographer_required' => true, 'shoot_duration_minutes' => 75]);
        $this->shoot = Shoot::factory()->create(['rep_id' => $this->actor->id, 'photographer_id' => $photographer->id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => 'America/New_York',
            'scheduled_at' => '2026-10-13 15:30:00', 'scheduled_date' => '2026-10-13', 'time' => '11:30',
            'address' => '8132 Winding Ross Way', 'city' => 'Ellicott City', 'state' => 'MD', 'zip' => '21043']);
        $this->shoot->services()->attach($this->service->id, ['duration_minutes' => 75, 'quantity' => 1,
            'price' => 275, 'photographer_id' => $photographer->id,
            'scheduled_at' => '2026-10-13 15:30:00', 'workflow_status' => 'scheduled']);
        $this->payload = ['services' => [['id' => $this->service->id, 'duration_minutes' => 90]],
            'notify_client' => false, 'notify_photographer' => false];
    }

    private function preview(array $payload = []): array
    {
        return $this->postJson('/api/photographer/availability/feasibility', $payload + $this->payload + [
            'shoot_id' => $this->shoot->id, 'action_mode' => 'update',
        ])->assertOk()->json('data');
    }

    private function flags(array $preview): array
    {
        return ['force_save' => true, 'force_save_confirmed' => true,
            'force_save_reason' => 'Reviewed existing appointment and requested package upgrade',
            'force_save_confirmation_version' => $preview['force_save_confirmation_version']];
    }

    public static function staffSavePaths(): array
    {
        return [['salesRep', 'patch'], ['admin', 'patch'], ['superadmin', 'patch']];
    }

    #[DataProvider('staffSavePaths')]
    public function test_staff_can_confirm_working_hours_warning_for_same_visit_and_audit(string $role, string $path): void
    {
        $this->actor->update(['role' => $role]);
        $warning = $this->preview();
        $this->assertSame(['outside_working_hours'], $warning['reason_codes']);
        $this->assertFalse($warning['can_override']);
        $this->assertTrue($warning['can_force_save']);
        $endpoint = '/api/shoots/'.$this->shoot->id;
        $this->patchJson($endpoint, $this->payload + $this->flags($warning))->assertOk();
        $this->assertSame(90, (int) $this->shoot->serviceItems()->sole()->duration_minutes);
        $this->assertSame('2026-10-13 15:30:00', $this->shoot->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $audit = DB::table('user_activity_logs')->where('event_type', 'schedule.staff_force_save')->sole();
        $this->assertSame($this->actor->id, (int) $audit->actor_user_id);
        $this->assertSame($this->shoot->id, (int) $audit->target_id);
        $this->assertSame(['outside_working_hours'], json_decode($audit->metadata, true)['reason_codes']);
        Mail::assertNothingSent();
    }

    public function test_normal_save_still_blocks_and_confirmation_and_reason_are_required(): void
    {
        $endpoint = '/api/shoots/'.$this->shoot->id;
        $this->patchJson($endpoint, $this->payload)->assertUnprocessable()
            ->assertJsonPath('feasibility.can_force_save', true);
        $warning = $this->preview();
        $flags = $this->flags($warning);
        $flags['force_save_confirmed'] = false;
        $this->patchJson($endpoint, $this->payload + $flags)->assertUnprocessable()->assertJsonValidationErrors('force_save_confirmed');
        $flags['force_save_confirmed'] = true; $flags['force_save_reason'] = '';
        $this->patchJson($endpoint, $this->payload + $flags)->assertUnprocessable()->assertJsonValidationErrors('force_save_reason');
        $this->assertSame(75, (int) $this->shoot->serviceItems()->sole()->duration_minutes);
    }

    public static function packagePreviewPayloads(): array
    {
        return [['salesRep', null], ['admin', null], ['salesRep', '2026-10-13T11:30:00-04:00'],
            ['admin', '2026-10-13T11:30:00-04:00'], ['salesRep', '2026-10-13T11:30:00'], ['admin', '2026-10-13T11:30:00']];
    }

    #[DataProvider('packagePreviewPayloads')]
    public function test_package_replacement_keeps_signed_warning_through_service_detach_confirmation(string $role, ?string $serviceAt): void
    {
        $this->actor->update(['role' => $role]);
        $this->shoot->update(['address' => '8132 Winding Ross Way Ellicott City',
            'property_details' => ['beds' => 0, 'bedrooms' => 0, 'baths' => 0, 'bathrooms' => 0, 'sqft' => 0, 'squareFeet' => 0]]);
        $payment = Payment::factory()->create(['shoot_id' => $this->shoot->id, 'invoice_id' => null,
            'amount' => 275, 'status' => Payment::STATUS_COMPLETED]);
        PaymentServiceAllocation::create(['payment_id' => $payment->id,
            'shoot_id' => $this->shoot->id, 'shoot_service_id' => $this->shoot->serviceItems()->sole()->id,
            'amount' => 275, 'allocation_strategy' => 'selected_services']);
        $replacement = Service::factory()->create(['name' => '55 HDR Photos', 'price' => 350,
            'photographer_required' => true, 'shoot_duration_minutes' => 90]);
        // Mirror the browser: line null means inherit the booked anchor, and
        // assignments are separate from service schedule/pricing rows.
        $payload = ['services' => [['id' => $replacement->id, 'scheduled_at' => $serviceAt, 'duration_minutes' => 90]],
            'service_items' => [['service_id' => $replacement->id, 'scheduled_at' => $serviceAt, 'duration_minutes' => 90]],
            'service_photographers' => [['service_id' => $replacement->id, 'photographer_id' => $this->shoot->photographer_id]],
            'photographer_id' => $this->shoot->photographer_id, 'client_id' => $this->shoot->client_id,
            'address' => $this->shoot->address, 'city' => $this->shoot->city, 'state' => $this->shoot->state, 'zip' => $this->shoot->zip,
            'notify_client' => false, 'notify_photographer' => false];
        $warning = $this->preview(['scheduled_at' => $serviceAt ?: '2026-10-13T15:30:00Z', 'timezone' => 'America/New_York'] + $payload);
        $payload += ['property_details' => $this->shoot->property_details, 'bedrooms' => 0, 'bathrooms' => 0, 'sqft' => 0];
        if ($role === 'admin') $payload += ['scheduled_date' => '2026-10-13', 'time' => '11:30:00'];
        $this->assertTrue($warning['can_force_save']);
        $confirmation = $this->patchJson('/api/shoots/'.$this->shoot->id, $payload + $this->flags($warning))
            ->assertStatus(409)->assertJsonPath('code', 'service_detach_confirmation_required');
        $this->assertSame($this->service->id, (int) $this->shoot->serviceItems()->sole()->service_id);
        $this->patchJson('/api/shoots/'.$this->shoot->id, $payload + $this->flags($warning) + [
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();
        $line = $this->shoot->serviceItems()->sole();
        $this->assertSame($replacement->id, (int) $line->service_id);
        $this->assertSame(350.0, (float) $line->price);
        $this->assertSame(90, (int) $line->duration_minutes);
        $this->assertSame($this->shoot->photographer_id, (int) $line->photographer_id);
        $this->assertSame('2026-10-13T15:30:00+00:00', app(\App\Services\Shoots\ShootDurationResolver::class)
            ->windowsForShoot($this->shoot->fresh())[0]['start']->copy()->utc()->toIso8601String());
        $this->assertSame('scheduled', $this->shoot->fresh()->status);
        $this->assertSame(275.0, (float) $this->shoot->fresh()->calculateCanonicalTotalPaid());
        $this->assertSame(275.0, (float) PaymentServiceAllocation::where('payment_id', $payment->id)->sum('amount'));
        $this->assertDatabaseHas('payment_service_allocations', ['payment_id' => $payment->id, 'shoot_service_id' => $line->id]);
        $this->assertSame(1, DB::table('user_activity_logs')->where('event_type', 'schedule.staff_force_save')->count());
    }

    public function test_new_overlap_after_preview_blocks_the_old_force_confirmation(): void
    {
        $warning = $this->preview();
        $neighbor = Shoot::factory()->create(['photographer_id' => $this->shoot->photographer_id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => 'America/New_York',
            'scheduled_at' => '2026-10-13 16:50:00']);
        $neighbor->services()->attach($this->service->id, ['photographer_id' => $neighbor->photographer_id,
            'duration_minutes' => 30, 'scheduled_at' => '2026-10-13 16:50:00', 'workflow_status' => 'scheduled']);
        $this->patchJson('/api/shoots/'.$this->shoot->id, $this->payload + $this->flags($warning))
            ->assertUnprocessable()->assertJsonPath('feasibility.can_force_save', false);
        $this->assertSame(75, (int) $this->shoot->serviceItems()->sole()->duration_minutes);
    }

    public function test_actual_capture_overlap_cannot_be_force_saved(): void
    {
        $neighbor = Shoot::factory()->create(['photographer_id' => $this->shoot->photographer_id,
            'status' => 'scheduled', 'workflow_status' => 'scheduled', 'timezone' => 'America/New_York',
            'scheduled_at' => '2026-10-13 16:50:00']);
        $neighbor->services()->attach($this->service->id, ['photographer_id' => $neighbor->photographer_id,
            'duration_minutes' => 30, 'scheduled_at' => '2026-10-13 16:50:00', 'workflow_status' => 'scheduled']);
        $warning = $this->preview();
        $this->assertContains('capture_overlap', $warning['reason_codes']);
        $this->assertFalse($warning['can_force_save']);
        $this->patchJson('/api/shoots/'.$this->shoot->id, $this->payload + [
            'force_save' => true, 'force_save_confirmed' => true, 'force_save_reason' => 'Attempted overlap override',
            'force_save_confirmation_version' => str_repeat('a', 64),
        ])->assertUnprocessable()->assertJsonPath('feasibility.can_force_save', false);
        $this->assertSame(75, (int) $this->shoot->serviceItems()->sole()->duration_minutes);
    }

    public function test_token_binds_edit_state_target_and_actor(): void
    {
        $warning = $this->preview();
        $flags = $this->flags($warning);
        $this->shoot->update(['shoot_notes' => 'Changed after warning preview']);
        $this->patchJson('/api/shoots/'.$this->shoot->id, $this->payload + $flags)
            ->assertUnprocessable()->assertJsonValidationErrors('force_save_confirmation_version');
        $fresh = $this->preview();
        $changed = $this->payload; $changed['services'][0]['duration_minutes'] = 95;
        $this->patchJson('/api/shoots/'.$this->shoot->id, $changed + $this->flags($fresh))
            ->assertUnprocessable()->assertJsonValidationErrors('force_save_confirmation_version');
        Sanctum::actingAs(User::factory()->admin()->create(['email_verification_required_at' => null]));
        $this->patchJson('/api/shoots/'.$this->shoot->id, $this->payload + $this->flags($fresh))
            ->assertUnprocessable()->assertJsonValidationErrors('force_save_confirmation_version');
        $this->assertSame(75, (int) $this->shoot->serviceItems()->sole()->duration_minutes);
    }

    public function test_toggle_permissions_and_changed_visit_boundaries_fail_closed(): void
    {
        $warning = $this->preview();
        config(['availability.temporary_staff_shoot_force_save' => false]);
        $this->assertFalse($this->preview()['can_force_save']);
        $this->patchJson('/api/shoots/'.$this->shoot->id, $this->payload + $this->flags($warning))->assertForbidden();
        config(['availability.temporary_staff_shoot_force_save' => true]);
        $policy = app(TemporaryStaffEditForceSave::class);
        $base = $this->payload + ['action_mode' => 'update'];
        $this->assertFalse($policy->eligible($base, $this->shoot, User::factory()->create(['role' => 'client'])));
        $this->assertTrue($policy->eligible($base, $this->shoot, User::factory()->create(['role' => 'salesRep'])));
        $this->assertFalse($policy->eligible(['scheduled_at' => '2026-10-13T16:00:00Z'] + $base, $this->shoot, $this->actor));
        $this->assertFalse($policy->eligible(['address' => 'Different property'] + $base, $this->shoot, $this->actor));
        $this->assertFalse($policy->eligible(['action_mode' => 'create'] + $base, null, $this->actor));
        $this->assertFalse($policy->eligible(['schedule_adjustments' => [['shoot_id' => 1]]] + $base, $this->shoot, $this->actor));
        $this->shoot->update(['status' => 'requested', 'workflow_status' => 'requested']);
        $this->assertFalse($policy->eligible($base, $this->shoot->fresh(), $this->actor));
    }

    public function test_commit_rechecks_current_staff_permission(): void
    {
        $warning = $this->preview();
        $payload = $this->payload + $this->flags($warning) + ['action_mode' => 'update'];
        $prepared = app(ScheduleCommitGuard::class)->prepare($payload, $this->shoot, $this->actor);
        $this->actor->update(['role' => 'client']);
        try {
            app(ScheduleCommitGuard::class)->commit($prepared, fn () => $this->fail('Must not write after permission loss.'));
            $this->fail('Permission loss must reject a prepared force save.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_audit_failure_rolls_back_the_forced_write(): void
    {
        $warning = $this->preview();
        $payload = $this->payload + $this->flags($warning) + ['action_mode' => 'update'];
        $prepared = app(ScheduleCommitGuard::class)->prepare($payload, $this->shoot, $this->actor);
        $audit = Mockery::mock(\App\Services\AuditLogService::class);
        $audit->shouldReceive('record')->withArgs(fn ($action) => $action === 'schedule.staff_force_save')
            ->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->app->instance(\App\Services\AuditLogService::class, $audit);
        try {
            app(ScheduleCommitGuard::class)->commit($prepared, function () {
                $this->shoot->serviceItems()->update(['duration_minutes' => 90]);
                return $this->shoot;
            });
            $this->fail('Failed audit must not commit the force save.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame(75, (int) $this->shoot->serviceItems()->sole()->duration_minutes);
    }
}
