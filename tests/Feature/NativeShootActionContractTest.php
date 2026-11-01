<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Scheduling\ScheduleCommitGuard;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\MultiUnitBookingService;
use App\Services\Shoots\ShootManagementAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/** Replays actual Swift outbound fixtures; IDs/versions are bound to isolated rows. */
class NativeShootActionContractTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;
    private Service $otherService;
    private User $photographer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['availability.hybrid_travel_enabled' => false]);
        foreach ([ShootMediaStorageService::class, InvoiceService::class, GoogleCalendarSyncDispatcher::class] as $class) {
            $this->app->instance($class, Mockery::mock($class)->shouldIgnoreMissing());
        }
        $mail = Mockery::mock(MailService::class)->shouldIgnoreMissing();
        $mail->shouldReceive('captureShootSnapshot')->andReturn([]);
        $mail->shouldReceive('buildShootChangeSummary')->andReturn(['summary' => '', 'html' => '']);
        $mail->shouldReceive('buildClientRequestChangeSummary')->andReturn(['lines' => [], 'service_deltas' => []]);
        $this->app->instance(MailService::class, $mail);
        $automation = Mockery::mock(AutomationService::class)->shouldIgnoreMissing();
        $automation->shouldReceive('buildShootContext')->andReturn([]);
        $automation->shouldReceive('handleEvent')->andReturn([]);
        $automation->shouldReceive('shouldUseFallback')->andReturnFalse();
        $this->app->instance(AutomationService::class, $automation);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->service = Service::factory()->create(['name' => 'Photos', 'price' => 200, 'pricing_type' => 'fixed', 'photographer_required' => true]);
        $this->otherService = Service::factory()->create(['name' => 'Video', 'price' => 200, 'pricing_type' => 'fixed', 'photographer_required' => true]);
        $this->photographer = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        $this->photographer->setServiceCapabilities([(string) $this->service->id]);
        $this->photographer->save();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_native_batch_assignment_preserves_other_lines_and_main_photographer(bool $multi): void
    {
        $shoot = $this->shoot($multi);
        $lines = $shoot->serviceItems()->orderBy('id')->get();
        $other = $lines[1]->getRawOriginal();
        $body = $this->assignment($shoot, $multi);
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographers', $body)->assertOk();
        $this->assertSame($this->photographer->id, (int) $lines[0]->fresh()->photographer_id);
        $this->assertSame($other, $lines[1]->fresh()->getRawOriginal());
        $this->assertSame($shoot->photographer_id, $shoot->fresh()->photographer_id);
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_assignment_rejects_a_version_changed_after_native_preflight(bool $multi): void
    {
        $shoot = $this->shoot($multi);
        $before = $shoot->serviceItems()->orderBy('id')->get()->map->getRawOriginal()->all();
        $body = $this->assignment($shoot, $multi);
        $shoot->update(['company_notes' => 'Another reviewer changed this shoot']);
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographers', $body)
            ->assertConflict()->assertJsonPath('code', 'shoot_edit_conflict');
        $this->assertSame($before, $shoot->serviceItems()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_assignment_rechecks_the_version_at_the_write_boundary(bool $multi): void
    {
        $shoot = $this->shoot($multi);
        $before = $shoot->serviceItems()->orderBy('id')->get()->map->getRawOriginal()->all();
        $body = $this->assignment($shoot, $multi);
        $guard = Mockery::mock(ScheduleCommitGuard::class);
        $guard->shouldReceive('prepare')->once()->andReturnUsing(function () use ($shoot) {
            // A concurrent save after preflight/planning, before assignment commit.
            DB::table('shoots')->where('id', $shoot->id)->update(['company_notes' => 'Changed during planning']);
            return ['enabled' => false];
        });
        $guard->shouldReceive('commit')->once()->andReturnUsing(fn ($prepared, $write) => $write());
        $this->app->instance(ScheduleCommitGuard::class, $guard);
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographers', $body)
            ->assertConflict()->assertJsonPath('code', 'shoot_edit_conflict');
        $this->assertSame('Changed during planning', $shoot->fresh()->company_notes);
        $this->assertSame($before, $shoot->serviceItems()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_single_assignment_endpoint_also_checks_optional_edit_version(): void
    {
        $shoot = $this->shoot(false);
        $batch = $this->assignment($shoot, false);
        $payload = $batch['assignments'][0] + ['expected_edit_version' => $batch['expected_edit_version']];
        $shoot->update(['company_notes' => 'Another save']);
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographer', $payload)
            ->assertConflict()->assertJsonPath('code', 'shoot_edit_conflict');
        $this->assertNull($shoot->serviceItems()->orderBy('id')->first()->photographer_id);
        $payload['expected_edit_version'] = app(ShootManagementAccess::class)->editVersion($shoot->fresh());
        $this->postJson('/api/shoots/'.$shoot->id.'/assign-service-photographer', $payload)->assertOk();
    }

    public function test_native_modified_unit_approval_preserves_identity_and_uses_property_local_time(): void
    {
        $shoot = $this->shoot(true, 1, 'requested');
        $line = $shoot->serviceItems()->sole();
        $body = $this->fixture('approve-modified-unit-service')['body'];
        $body['expected_edit_version'] = app(ShootManagementAccess::class)->editVersion($shoot);
        $body['expected_units_revision'] = $shoot->units_revision;
        $body['service_lines'][0] = array_replace($body['service_lines'][0], [
            'service_id' => $line->service_id, 'shoot_service_id' => $line->id,
            'shoot_unit_id' => $line->shoot_unit_id, 'client_key' => $line->client_key,
            'unit_client_key' => $line->unit->client_key, 'photographer_id' => $this->photographer->id,
            'editor_id' => User::factory()->create(['role' => 'editor'])->id,
        ]);
        $this->postJson('/api/shoots/'.$shoot->id.'/approve', $body)->assertOk();
        $this->assertSame('scheduled', $shoot->fresh()->workflow_status);
        $this->assertSame('2026-11-12', $shoot->fresh()->scheduled_date->toDateString());
        $this->assertSame('15:00', $shoot->fresh()->time);
        $this->assertSame('2026-11-12 20:00:00', $line->fresh()->getRawOriginal('scheduled_at'));
        $this->assertSame(60, $line->fresh()->duration_minutes);
        $this->assertSame(1, $shoot->serviceItems()->count());
        Http::assertNothingSent();
    }

    public function test_native_decline_uses_the_booking_route_and_records_its_reason(): void
    {
        $shoot = $this->shoot(false, 2, 'requested');
        $fixture = $this->fixture('decline-booking');
        $this->postJson('/api/shoots/'.$shoot->id.'/decline', $fixture['body'])->assertOk();
        $this->assertSame('declined', $shoot->fresh()->workflow_status);
        $this->assertSame($fixture['body']['reason'], $shoot->fresh()->declined_reason);
        Http::assertNothingSent();
    }

    public function test_native_indexed_filter_query_keeps_comma_containing_service_names_intact(): void
    {
        $query = $this->fixture('multi-status-comma-service-filter')['query'];
        $this->service->update(['name' => $query['services[0]']]);
        $shoot = $this->shoot(false);
        $shoot->update(['is_flagged' => true]);
        $query['client_id'] = (string) $shoot->client_id;
        $query['photographer_id[0]'] = (string) $shoot->photographer_id;
        $this->getJson('/api/shoots?'.http_build_query($query))->assertOk()
            ->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $shoot->id);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/native-nine-actions/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assignment(Shoot $shoot, bool $multi): array
    {
        $line = $shoot->serviceItems()->orderBy('id')->first();
        $body = $this->fixture($multi ? 'assignment-multi-unit' : 'assignment-single-unit')['body'];
        $body['expected_edit_version'] = app(ShootManagementAccess::class)->editVersion($shoot);
        if ($multi) { $body['expected_units_revision'] = $shoot->units_revision; }
        $body['assignments'][0] = ['photographer_id' => $this->photographer->id,
            'service_id' => $line->service_id, 'shoot_service_id' => $line->id];
        return $body;
    }

    private function shoot(bool $multi, int $count = 2, string $status = 'scheduled'): Shoot
    {
        $originalPhotographer = null;
        if (! $multi) {
            $originalPhotographer = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
            $originalPhotographer->setServiceCapabilities([(string) $this->service->id, (string) $this->otherService->id]);
            $originalPhotographer->save();
        }
        $shoot = Shoot::factory()->create(['status' => $status, 'workflow_status' => $status, 'timezone' => 'America/New_York',
            'service_id' => $this->service->id, 'photographer_id' => $originalPhotographer?->id,
            'scheduled_at' => '2026-11-12 15:00:00', 'scheduled_date' => '2026-11-12', 'time' => '10:00',
            'base_quote' => 400, 'total_quote' => 400, 'is_private_listing' => false]);
        if ($multi) {
            $payload = [
                'units' => array_map(fn ($i) => ['client_key' => 'unit-'.$i, 'label' => 'Unit '.$i, 'kind' => 'unit', 'sqft' => 1000], range(1, $count)),
                'service_lines' => array_map(fn ($i) => ['client_key' => 'line-'.$i, 'unit_client_key' => 'unit-'.$i,
                    'service_id' => $this->service->id, 'quantity' => 1, 'duration_minutes' => 30,
                    // Direct service setup receives normalized storage clocks;
                    // the actual approval fixture still tests zoned HTTP input.
                    'scheduled_at' => '2026-11-12 15:00:00'], range(1, $count)),
            ];
            $service = app(MultiUnitBookingService::class);
            DB::transaction(fn () => $service->persist($shoot, $service->prepare($shoot, $payload, auth()->user())));
        } else {
            for ($i = 0; $i < $count; $i++) {
                $service = $i === 0 ? $this->service : $this->otherService;
                $shoot->services()->attach($service->id, ['price' => 200, 'quantity' => 1,
                    'scheduled_at' => '2026-11-12 15:00:00', 'duration_minutes' => 30]);
            }
        }
        return $shoot->fresh();
    }
}
