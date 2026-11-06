<?php

namespace Tests\Feature;

use App\Jobs\ProcessProviderAutoDelivery;
use App\Console\Commands\QueueProviderAutoDelivery;
use App\Models\Service;
use App\Models\Shoot;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProviderAutoDeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(12, 0));
        Http::preventStrayRequests();
        Queue::fake(); Mail::fake(); Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function pending(array $attributes = [], array $lineAttributes = []): Shoot
    {
        return Model::withoutEvents(function () use ($attributes, $lineAttributes) {
            $shoot = Shoot::factory()->create(array_merge([
                'status' => 'scheduled', 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started',
                'admin_verified_at' => null, 'completed_at' => null, 'auto_delivery_enabled' => true,
                'scheduled_at' => now()->subDay(), 'scheduled_date' => now()->subDay()->toDateString(),
                'cubicasa_order_id' => 'recovery-order',
            ], $attributes));
            $service = Service::factory()->noIntake()->create(['name' => '2D Floor plans', 'is_migration_only' => false]);
            $shoot->serviceItems()->create(array_merge([
                'service_id' => $service->id, 'price' => 125, 'quantity' => 1, 'is_deliverable' => true,
                'workflow_status' => 'scheduled', 'delivery_status' => 'not_started', 'scheduled_at' => now()->subDay(),
                'delivery_requirements' => [['key' => 'floorplan', 'kind' => 'floorplan', 'label' => 'Floorplans', 'provider' => 'cubicasa']],
            ], $lineAttributes));
            return $shoot;
        });
    }

    public function test_recovery_queues_eligible_work_but_respects_off_hold_future_and_missing_provider_identity(): void
    {
        $eligible = $this->pending();
        $this->pending(['auto_delivery_enabled' => false]);
        $this->pending(['status' => 'on_hold', 'workflow_status' => 'on_hold']);
        $this->pending([], ['scheduled_at' => now()->addDay()]);
        $this->pending(['cubicasa_order_id' => null]);

        $this->artisan('provider:auto-deliver')->assertSuccessful();

        Queue::assertPushed(ProcessProviderAutoDelivery::class, 1);
        Queue::assertPushed(ProcessProviderAutoDelivery::class, fn ($job) => $job->shootId === $eligible->id);
        Http::assertNothingSent();
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public function test_targeted_dry_run_is_read_only_and_mixed_photo_work_does_not_hide_provider_recovery(): void
    {
        $shoot = $this->pending();
        Model::withoutEvents(function () use ($shoot) {
            $photo = Service::factory()->unbracketedPhoto(35)->create();
            $shoot->serviceItems()->create(['service_id' => $photo->id, 'price' => 199, 'quantity' => 1,
                'is_deliverable' => true, 'workflow_status' => 'scheduled', 'delivery_status' => 'not_started',
                'scheduled_at' => now()->subDay(),
                'delivery_requirements' => [['key' => 'photo', 'kind' => 'photo', 'label' => 'Photos']]]);
        });
        $before = $shoot->fresh()->getAttributes();

        $this->artisan('provider:auto-deliver', ['--shoot' => $shoot->id, '--dry-run' => true])
            ->expectsOutput('Eligible provider auto delivery shoot IDs: '.$shoot->id.'.')->assertSuccessful();

        Queue::assertNothingPushed(); Http::assertNothingSent();
        $this->assertSame($before, $shoot->fresh()->getAttributes());
        $this->assertDatabaseCount('shoot_delivery_receipts', 0);
        $this->assertDatabaseCount('shoot_delivery_notification_outbox', 0);
    }

    public function test_provider_and_notification_recovery_are_scheduled_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events());
        $provider = $events->first(fn ($event) => str_contains((string) $event->command, 'provider:auto-deliver'));
        $notifications = $events->first(fn ($event) => str_contains((string) $event->command, 'delivery-notifications:recover'));
        $this->assertNotNull($provider); $this->assertNotNull($notifications);
        $this->assertSame('*/5 * * * *', $provider->expression);
        $this->assertSame('* * * * *', $notifications->expression);
        $this->assertTrue($provider->withoutOverlapping);
        $this->assertTrue($notifications->withoutOverlapping);
    }

    public function test_bounded_recovery_rotates_past_old_unready_orders_and_dry_run_does_not_advance_it(): void
    {
        $shoots = collect();
        for ($index = 0; $index < 101; $index++) {
            $shoots->push($this->pending(['cubicasa_order_id' => 'unready-order-'.$index]));
        }
        $last = $shoots->last();

        $this->artisan('provider:auto-deliver', ['--limit' => 100])->assertSuccessful();
        Queue::assertPushed(ProcessProviderAutoDelivery::class, 100);
        Queue::assertNotPushed(ProcessProviderAutoDelivery::class, fn ($job) => $job->shootId === $last->id);
        $cursor = Cache::get(QueueProviderAutoDelivery::CURSOR_KEY);
        $this->assertSame($shoots[99]->id, $cursor);

        Queue::fake();
        $this->artisan('provider:auto-deliver', ['--limit' => 1, '--dry-run' => true])->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertSame($cursor, Cache::get(QueueProviderAutoDelivery::CURSOR_KEY));

        $this->artisan('provider:auto-deliver', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(ProcessProviderAutoDelivery::class, 1);
        Queue::assertPushed(ProcessProviderAutoDelivery::class, fn ($job) => $job->shootId === $last->id);
        $this->assertSame($last->id, Cache::get(QueueProviderAutoDelivery::CURSOR_KEY));
        Http::assertNothingSent();
    }
}
