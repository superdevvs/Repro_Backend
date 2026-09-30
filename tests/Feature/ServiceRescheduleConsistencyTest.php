<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Schedule\ShootScheduleFromServices;
use App\Services\Schedule\ShootScheduleUpdateInput;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceRescheduleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-09-28 16:00:00');
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function reschedules(): array
    {
        return [
            'overview sends unchanged booking and changed services' => ['America/New_York', true],
            'service-only edit' => ['America/New_York', false],
            'legacy wall-clock edit' => [null, true],
        ];
    }

    #[DataProvider('reschedules')]
    public function test_service_reschedule_updates_booking_lists_dashboard_and_availability(?string $zone, bool $includeBooking): void
    {
        $shoot = $this->shoot($zone);
        $stored = $zone ? '2026-09-30 15:00:00' : '2026-09-30 11:00:00';
        $input = $zone ? '2026-09-30T11:00:00-04:00' : '2026-09-30T11:00:00';
        $items = $shoot->serviceItems->map(fn ($item) => [
            'service_id' => $item->service_id, 'id' => $item->service_id,
            'photographer_id' => $shoot->photographer_id, 'scheduled_at' => $input,
        ])->all();
        $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.upcoming_shoots.0.scheduled_instant', '2026-09-29T16:30:00+00:00');
        $payload = ['services' => $items, 'service_items' => $items, 'notify_client' => false];
        if ($includeBooking) {
            // Echo of the prior booking — services remain source of truth.
            $payload += ['scheduled_date' => '2026-09-29', 'time' => '12:30:00'];
        }
        $this->patchJson('/api/shoots/'.$shoot->id, $payload)->assertOk();
        $shoot->refresh();
        $this->assertSame($stored, $shoot->getRawOriginal('scheduled_at'));
        $this->assertSame('2026-09-30', $shoot->scheduled_date->toDateString());
        $this->assertSame('11:00:00', $shoot->time);
        $this->assertSame([$stored, $stored], $shoot->serviceItems()->orderBy('id')->get()->map(fn ($item) => $item->getRawOriginal('scheduled_at'))->all());
        $this->assertSame(['100.00', '100.00'], $shoot->serviceItems()->orderBy('id')->pluck('price')->all());
        $this->assertTrue(data_get($shoot->external_booking_payload, 'legacy_migration.notifications_suppressed'));
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()
            ->assertJsonPath('data.scheduled_instant', '2026-09-30T15:00:00+00:00');
        $this->getJson('/api/shoots?tab=scheduled&sort=today_upcoming')->assertOk()
            ->assertJsonPath('data.0.id', $shoot->id)
            ->assertJsonPath('data.0.scheduled_instant', '2026-09-30T15:00:00+00:00');
        $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.upcoming_shoots.0.scheduled_instant', '2026-09-30T15:00:00+00:00');
        $this->postJson('/api/photographer/availability/booked-slots', [
            'photographer_id' => $shoot->photographer_id, 'from_date' => '2026-09-29', 'to_date' => '2026-09-30',
        ])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-09-30');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    public function test_booking_move_aligns_services_then_shoot_derives_from_earliest(): void
    {
        $shoot = $this->shoot();
        $this->patchJson('/api/shoots/'.$shoot->id, [
            'scheduled_date' => '2026-11-16',
            'time' => '11:00:00',
            'notify_client' => false,
        ])->assertOk();
        $shoot->refresh();
        $this->assertSame('2026-11-16 16:00:00', $shoot->getRawOriginal('scheduled_at'));
        $this->assertSame('2026-11-16', $shoot->scheduled_date->toDateString());
        $this->assertSame('11:00:00', $shoot->time);
        $this->assertSame(
            ['2026-11-16 16:00:00', '2026-11-16 16:00:00'],
            $shoot->serviceItems()->orderBy('id')->get()->map(fn ($item) => $item->getRawOriginal('scheduled_at'))->all()
        );
    }

    public function test_shoot_adopts_earliest_when_service_times_differ(): void
    {
        $shoot = $this->shoot();
        $items = $shoot->serviceItems()->orderBy('id')->get();
        $payload = [
            'service_items' => [
                ['service_id' => $items[0]->service_id, 'scheduled_at' => '2026-11-16T11:00:00-05:00'],
                ['service_id' => $items[1]->service_id, 'scheduled_at' => '2026-11-17T13:00:00-05:00'],
            ],
            'notify_client' => false,
        ];
        $this->patchJson('/api/shoots/'.$shoot->id, $payload)->assertOk();
        $shoot->refresh();
        $this->assertSame('2026-11-16 16:00:00', $shoot->getRawOriginal('scheduled_at'));
        $this->assertSame('2026-11-16', $shoot->scheduled_date->toDateString());
        $this->assertSame('11:00:00', $shoot->time);
        $this->assertSame(
            ['2026-11-16 16:00:00', '2026-11-17 18:00:00'],
            $shoot->serviceItems()->orderBy('id')->get()->map(fn ($item) => $item->getRawOriginal('scheduled_at'))->all()
        );
    }

    public function test_normalize_derives_shoot_fields_from_earliest_service(): void
    {
        $shoot = $this->shoot();
        $items = $shoot->serviceItems()->orderBy('id')->get();
        $payload = ['service_items' => [
            ['service_id' => $items[0]->service_id, 'scheduled_at' => '2026-11-16T11:00:00-05:00'],
            ['service_id' => $items[1]->service_id, 'scheduled_at' => '2026-11-17T13:00:00-05:00'],
        ]];
        $normalized = app(ShootScheduleUpdateInput::class)->normalize($shoot, $payload);
        $this->assertSame('2026-11-16T16:00:00+00:00', $normalized['scheduled_at']);
        $this->assertSame('2026-11-16', $normalized['scheduled_date']);
        $this->assertSame('11:00:00', $normalized['time']);
    }

    public function test_non_deliverable_null_fee_lines_are_not_forced_onto_a_schedule(): void
    {
        $shoot = $this->shoot();
        $fee = Service::factory()->create(['price' => 60, 'photographer_required' => false]);
        $shoot->services()->attach($fee->id, [
            'price' => 60, 'quantity' => 1, 'scheduled_at' => null,
            'workflow_status' => 'pending', 'is_deliverable' => false,
        ]);
        $shoot->unsetRelation('serviceItems');
        app(ShootScheduleFromServices::class)->alignBookingDefiningServices(
            $shoot,
            Carbon::parse('2026-11-16 16:00:00', 'UTC')
        );
        $feeLine = $shoot->serviceItems()->where('service_id', $fee->id)->first();
        $this->assertNull($feeLine->scheduled_at);
        $this->assertSame(
            ['2026-11-16 16:00:00', '2026-11-16 16:00:00'],
            $shoot->serviceItems()->where('is_deliverable', true)->orderBy('id')->get()
                ->map(fn ($item) => $item->getRawOriginal('scheduled_at'))->all()
        );
    }

    public function test_unscheduled_and_delivered_shoots_do_not_rewrite_booking_from_services(): void
    {
        $shoot = $this->shoot();
        $items = $shoot->serviceItems->map(fn ($item) => [
            'service_id' => $item->service_id, 'scheduled_at' => '2026-09-30T11:00:00-04:00',
        ])->all();
        $cleared = $items;
        $cleared[1]['scheduled_at'] = null;
        // One null line still yields an earliest from the remaining scheduled line.
        $normalized = app(ShootScheduleUpdateInput::class)->normalize($shoot, ['services' => $cleared]);
        $this->assertSame('2026-09-30T15:00:00+00:00', $normalized['scheduled_at']);

        $shoot->workflow_status = 'delivered';
        $this->assertArrayNotHasKey('scheduled_at', app(ShootScheduleUpdateInput::class)->normalize($shoot, ['service_items' => $items]));
    }

    private function shoot(?string $zone = 'America/New_York'): Shoot
    {
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $shoot = Shoot::factory()->create([
            'shoot_type' => Shoot::SHOOT_TYPE_STANDARD, 'photographer_id' => $photographer->id,
            'scheduled_at' => $zone ? '2026-09-29 16:30:00' : '2026-09-29 12:30:00',
            'scheduled_date' => '2026-09-29', 'time' => '12:30:00', 'timezone' => $zone,
            'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'external_booking_payload' => ['legacy_migration' => ['notifications_suppressed' => true]],
        ]);
        foreach (range(1, 2) as $unused) {
            $service = Service::factory()->create(['price' => 100, 'photographer_required' => true]);
            $shoot->services()->attach($service->id, [
                'price' => 100, 'quantity' => 1, 'photographer_id' => $photographer->id,
                'scheduled_at' => $shoot->getRawOriginal('scheduled_at'), 'workflow_status' => 'scheduled',
                'is_deliverable' => true,
            ]);
        }

        return $shoot->fresh(['serviceItems']);
    }
}
