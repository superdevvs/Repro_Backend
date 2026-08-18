<?php

namespace Tests\Feature;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Coverage gaps around CubiCasa auto-ordering.
 *
 * Only two code paths ever dispatched CreateCubiCasaOrderJob: booking a shoot
 * with a date (CreateShootAction) and approving a client request
 * (ApproveShootAction). Every other route to a scheduled shoot — booking with
 * no date and scheduling later, a plain PATCH moving requested -> scheduled,
 * applying an alternate date, the AI-chat booking flow — silently produced no
 * order. Nobody noticed because the two wired paths were themselves failing
 * with a 400 (see CubiCasaDraftOrderPayloadTest).
 *
 * Dispatch is therefore centralised on the Shoot lifecycle rather than bolted
 * onto each action, so a future booking path cannot forget it. The job is
 * already idempotent (it no-ops when the shoot is linked, cancelled, or
 * ineligible), which makes an over-eager dispatch safe and an under-eager one
 * expensive.
 */
class CubiCasaOrderCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.owner_email', 'contact@reprophotos.com');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
    }

    private function eligibleService(): Service
    {
        return Service::factory()->create(['name' => '2D Floor Plan']);
    }

    private function shoot(array $attributes = [], bool $eligible = true): Shoot
    {
        $shoot = Shoot::factory()->create(array_merge([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'cubicasa_idempotency_key' => null,
            'address' => '22154 Del Valle St',
            'city' => 'Woodland Hills',
            'state' => 'CA',
            'zip' => '91364',
            'scheduled_at' => null,
        ], $attributes));

        $service = $eligible ? $this->eligibleService() : Service::factory()->create(['name' => 'HDR Photography']);

        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 195,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shoot->fresh();
    }

    // ---------------------------------------------------------------- dispatch

    public function test_scheduling_a_previously_unscheduled_eligible_shoot_dispatches_the_job(): void
    {
        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_REQUESTED]);

        Queue::fake();

        $shoot->scheduled_at = now()->addDays(3);
        $shoot->workflow_status = Shoot::STATUS_SCHEDULED;
        $shoot->save();

        Queue::assertPushed(
            CreateCubiCasaOrderJob::class,
            fn (CreateCubiCasaOrderJob $job) => $job->shootId === $shoot->id
        );
    }

    public function test_an_already_linked_shoot_does_not_dispatch_again(): void
    {
        $shoot = $this->shoot([
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'cubicasa_order_id' => 'existing-order-uuid',
        ]);

        Queue::fake();

        $shoot->scheduled_at = now()->addDays(3);
        $shoot->workflow_status = Shoot::STATUS_SCHEDULED;
        $shoot->save();

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    public function test_a_cancelled_shoot_does_not_dispatch(): void
    {
        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);

        Queue::fake();

        $shoot->workflow_status = Shoot::STATUS_CANCELLED;
        $shoot->save();

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    public function test_an_ineligible_shoot_does_not_dispatch(): void
    {
        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_REQUESTED], eligible: false);

        Queue::fake();

        $shoot->scheduled_at = now()->addDays(3);
        $shoot->workflow_status = Shoot::STATUS_SCHEDULED;
        $shoot->save();

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    public function test_an_unrelated_field_change_does_not_dispatch(): void
    {
        $shoot = $this->shoot([
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        Queue::fake();

        $shoot->raw_photo_count = 12;
        $shoot->save();

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    // ----------------------------------------------------------------- backfill

    public function test_resync_backfills_an_eligible_unlinked_scheduled_shoot(): void
    {
        $shoot = $this->shoot([
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(),
        ]);

        Queue::fake();

        $this->artisan('cubicasa:resync-pending')->assertExitCode(0);

        Queue::assertPushed(
            CreateCubiCasaOrderJob::class,
            fn (CreateCubiCasaOrderJob $job) => $job->shootId === $shoot->id
        );
    }

    public function test_resync_does_not_backfill_a_cancelled_shoot(): void
    {
        $this->shoot([
            'workflow_status' => Shoot::STATUS_CANCELLED,
            'scheduled_at' => now()->addDay(),
        ]);

        Queue::fake();

        $this->artisan('cubicasa:resync-pending')->assertExitCode(0);

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    // ------------------------------------------------------------ false success

    /**
     * A 2xx whose body does not carry an order id leaves the shoot unlinked.
     * Stamping cubicasa_sync_status = succeeded in that case reports a healthy
     * sync for a shoot that has no order — indistinguishable in the UI from a
     * real success, and the reason a broken integration could look fine.
     */
    public function test_sync_status_is_not_marked_succeeded_when_no_order_id_is_returned(): void
    {
        Http::fake([self::BASE_URL . '/orders/draft' => Http::response(['unexpected' => 'shape'], 200)]);

        $shoot = $this->shoot(['workflow_status' => Shoot::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);

        app(CubiCasaService::class)->createOrder($shoot);

        $fresh = $shoot->fresh();

        $this->assertNull($fresh->cubicasa_order_id, 'precondition: nothing to link');
        $this->assertNotSame(
            'succeeded',
            $fresh->cubicasa_sync_status,
            'An unlinked shoot must not report a succeeded sync.'
        );
    }
}
