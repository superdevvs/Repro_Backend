<?php

namespace Tests\Feature;

use App\Models\PhotographerAvailability;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Scheduling\TravelLocationResolver;
use App\Services\Scheduling\TravelTimeEstimator;
use App\Services\ShootMediaStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class PendingApprovalWorkingHoursOverrideTest extends TestCase
{
    use \Tests\Concerns\FreshDatabaseOutsideTransaction;

    private Shoot $shoot;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'availability.hybrid_travel_enabled' => true, 'availability.scheduling_lock_store' => 'array']);
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        foreach ([ShootMediaStorageService::class, InvoiceService::class, GoogleCalendarSyncDispatcher::class] as $class) {
            $this->app->instance($class, Mockery::mock($class)->shouldIgnoreMissing());
        }
        $mail = Mockery::mock(MailService::class)->shouldIgnoreMissing();
        $mail->shouldReceive('captureShootSnapshot')->andReturn([]);
        $mail->shouldReceive('buildShootChangeSummary')->andReturn(['summary' => '', 'html' => '']);
        $mail->shouldReceive('buildClientRequestChangeSummary')->andReturn(['lines' => [], 'service_deltas' => []]);
        $this->app->instance(MailService::class, $mail);
        $automation = Mockery::mock(AutomationService::class)->shouldIgnoreMissing();
        $automation->shouldReceive('buildShootContext', 'handleEvent')->andReturn([]);
        $automation->shouldReceive('shouldUseFallback')->andReturnFalse();
        $this->app->instance(AutomationService::class, $automation);
        $location = Mockery::mock(TravelLocationResolver::class);
        $location->shouldReceive('reset')->andReturnNull();
        $location->shouldReceive('forPayload', 'forShoot')->andReturn(['verified' => true, 'building_key' => 'same']);
        $location->shouldReceive('persistedMetadata')->andReturn([]);
        $this->app->instance(TravelLocationResolver::class, $location);

        $this->actor = User::factory()->create(['role' => 'salesRep', 'email_verification_required_at' => null]);
        Sanctum::actingAs($this->actor);
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $client = User::factory()->create(['role' => 'client']);
        $service = Service::factory()->create(['photographer_required' => true, 'shoot_duration_minutes' => 75]);
        $photographer->setServiceCapabilities([(string) $service->id]);
        $photographer->save();
        $this->shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $this->actor->id,
            'photographer_id' => $photographer->id, 'status' => 'requested', 'workflow_status' => 'requested',
            'timezone' => 'America/New_York', 'scheduled_at' => '2026-10-22 18:00:00', 'scheduled_date' => '2026-10-22', 'time' => '14:00']);
        $this->shoot->services()->attach($service->id, ['duration_minutes' => 75, 'price' => 100, 'quantity' => 1,
            'photographer_id' => $photographer->id, 'scheduled_at' => '2026-10-22 18:00:00', 'workflow_status' => 'requested']);
        PhotographerAvailability::create(['photographer_id' => $photographer->id, 'date' => '2026-10-22',
            'day_of_week' => 'thursday', 'start_time' => '09:00', 'end_time' => '14:00', 'status' => 'available']);
    }

    private function body(): array
    {
        return ['scheduled_at' => '2026-10-22T18:00:00Z', 'notify_client' => false, 'notify_photographer' => false]
            + ($this->shoot->units_revision ? ['expected_units_revision' => $this->shoot->units_revision] : []);
    }

    private function preview(): array
    {
        return $this->postJson('/api/photographer/availability/feasibility',
            $this->body() + ['shoot_id' => $this->shoot->id, 'action_mode' => 'approve'])->assertOk()->json('data');
    }

    private function flags(array $preview): array
    {
        return ['working_hours_override' => true, 'working_hours_override_confirmed' => true,
            'working_hours_override_reason' => 'Photographer confirmed the 2pm appointment.',
            'working_hours_override_confirmation_version' => $preview['working_hours_confirmation_version']];
    }

    private function assertStillRequested(): void
    {
        $this->assertSame('requested', $this->shoot->fresh()->status);
        $this->assertSame(0, DB::table('user_activity_logs')->where('event_type', 'schedule.working_hours_override')->count());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['superadmin', []])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep', []])]
    #[\PHPUnit\Framework\Attributes\TestWith(['representative', []])]
    #[\PHPUnit\Framework\Attributes\TestWith(['editor', ['sales_rep']])]
    public function test_pending_approval_offers_and_saves_a_signed_hours_exception(string $role, array $secondary): void
    {
        $this->actor->update(['role' => $role, 'secondary_roles' => $secondary]);
        $preview = $this->preview();
        $this->assertTrue($preview['can_override_working_hours']);
        $this->assertContains('outside_working_hours', $preview['reason_codes']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body())
            ->assertUnprocessable()->assertJsonPath('feasibility.can_override_working_hours', true);
        $this->assertStillRequested();
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body() + $this->flags($preview))->assertOk();
        $this->assertSame('scheduled', $this->shoot->fresh()->status);
        $this->assertSame('2026-10-22 18:00:00', $this->shoot->getRawOriginal('scheduled_at'));
        $this->assertSame(75, $this->shoot->serviceItems()->sole()->duration_minutes);
        $this->assertSame(1, PhotographerAvailability::count());
        $this->assertSame('14:00', substr(PhotographerAvailability::sole()->end_time, 0, 5));
        $this->assertSame(1, DB::table('user_activity_logs')->where('event_type', 'schedule.working_hours_override')->count());
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['reason'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['confirmation'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['signature'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['changed_time'])]
    public function test_hours_exception_requires_current_schedule_reason_and_acknowledgement(string $invalid): void
    {
        $flags = $this->flags($this->preview());
        $body = $this->body();
        if ($invalid === 'reason') $flags['working_hours_override_reason'] = '';
        if ($invalid === 'confirmation') $flags['working_hours_override_confirmed'] = false;
        if ($invalid === 'signature') $flags['working_hours_override_confirmation_version'] = str_repeat('0', 64);
        if ($invalid === 'changed_time') $body['scheduled_at'] = '2026-10-22T18:30:00Z';
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $body + $flags)->assertUnprocessable();
        $this->assertStillRequested();
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function test_revoking_request_management_permission_invalidates_an_offered_exception(bool $secondary): void
    {
        if ($secondary) $this->actor->update(['role' => 'editor', 'secondary_roles' => ['sales_rep']]);
        $flags = $this->flags($this->preview());
        $this->actor->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoots-manage']]]);
        $this->assertFalse($this->preview()['can_override_working_hours']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body() + $flags)->assertForbidden();
        $this->assertStillRequested();
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['client'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['editor'])]
    public function test_unapproved_roles_cannot_forge_the_hours_exception(string $role): void
    {
        $flags = $this->flags($this->preview());
        $this->actor->update(['role' => $role]);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body() + $flags)->assertForbidden();
        $this->assertStillRequested();
    }

    public function test_an_overlapping_booking_added_after_preview_still_blocks_approval(): void
    {
        $flags = $this->flags($this->preview());
        $other = $this->shoot->replicate();
        $other->status = 'scheduled';
        $other->workflow_status = 'scheduled';
        $other->save();
        $line = $this->shoot->serviceItems()->sole();
        $other->services()->attach($line->service_id, ['duration_minutes' => 75,
            'photographer_id' => $this->shoot->photographer_id, 'scheduled_at' => '2026-10-22 18:00:00', 'workflow_status' => 'scheduled']);
        $this->assertFalse($this->preview()['can_override_working_hours']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body() + $flags)->assertUnprocessable();
        $this->assertStillRequested();
    }

    public function test_disabled_travel_checks_do_not_turn_the_hours_flag_into_a_bypass(): void
    {
        $flags = $this->flags($this->preview());
        config(['availability.hybrid_travel_enabled' => false]);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body() + $flags)->assertForbidden();
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body())->assertUnprocessable();
        $this->assertStillRequested();
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['superadmin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['salesRep'])]
    public function test_multi_unit_pending_approval_uses_the_same_hours_confirmation(string $role): void
    {
        $this->actor->update(['role' => $role]);
        $unit = $this->shoot->units()->create(['client_key' => 'unit-one', 'label' => 'One', 'kind' => 'unit', 'sort_order' => 0]);
        $this->shoot->serviceItems()->sole()->update(['shoot_unit_id' => $unit->id, 'client_key' => 'photos']);
        $this->shoot->forceFill(['units_revision' => 1])->save();
        $flags = $this->flags($this->preview());
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body() + $flags)->assertOk();
        $this->assertSame('scheduled', $this->shoot->fresh()->status);
    }

    public function test_ordinary_travel_review_can_still_be_acknowledged_during_approval(): void
    {
        PhotographerAvailability::query()->update(['end_time' => '18:00']);
        $other = $this->shoot->replicate();
        $other->status = $other->workflow_status = 'scheduled';
        $other->scheduled_at = '2026-10-22 16:00:00';
        $other->time = '12:00';
        $other->save();
        $other->services()->attach($this->shoot->serviceItems()->sole()->service_id,
            ['duration_minutes' => 75, 'photographer_id' => $this->shoot->photographer_id, 'scheduled_at' => '2026-10-22 16:00:00', 'workflow_status' => 'scheduled']);
        $travel = Mockery::mock(TravelTimeEstimator::class);
        $travel->shouldReceive('reset')->andReturnNull();
        $travel->shouldReceive('estimate')->andReturn(['source' => 'unknown', 'required_minutes' => null,
            'review_required' => true, 'reason_code' => 'location_unverified', 'drive_minutes' => null, 'distance_miles' => null]);
        $this->app->instance(TravelTimeEstimator::class, $travel);
        $preview = $this->preview();
        $this->assertTrue($preview['can_override']);
        $this->assertContains('travel_review_required', $preview['reason_codes']);
        $this->postJson('/api/shoots/'.$this->shoot->id.'/approve', $this->body() + [
            'travel_override' => true, 'travel_override_confirmed' => true,
            'travel_override_reason' => 'Reviewed travel with the photographer.',
            'travel_override_confirmation_version' => $preview['confirmation_version'],
        ])->assertOk();
        $this->assertSame('scheduled', $this->shoot->fresh()->status);
    }
}

