<?php

namespace Tests\Feature;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Jobs\IngestCubiCasaAssetsJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CubiCasaReconciliationCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://cubicasa.test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.owner_email', 'orders@example.test');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_small_batches_rotate_past_historical_drafts_to_older_shoot_ids(): void
    {
        $older = $this->shoot(['cubicasa_order_id' => 'older', 'cubicasa_status' => 'Draft']);
        $newer = $this->shoot(['cubicasa_order_id' => 'newer', 'cubicasa_status' => 'Draft']);
        $this->fakeOrders('Draft');

        $this->artisan('cubicasa:resync-pending', ['--limit' => 1])->assertSuccessful();
        $this->assertNotNull($older->fresh()->cubicasa_last_synced_at);
        $this->assertNull($newer->fresh()->cubicasa_last_synced_at);

        $this->artisan('cubicasa:resync-pending', ['--limit' => 1])->assertSuccessful();
        $this->assertNotNull($newer->fresh()->cubicasa_last_synced_at);
    }

    public function test_recent_failed_attempt_does_not_starve_an_unchecked_order(): void
    {
        $failed = $this->shoot(['cubicasa_order_id' => 'failed', 'cubicasa_status' => 'Draft', 'cubicasa_last_synced_at' => now()->subDays(5)]);
        $untouched = $this->shoot(['cubicasa_order_id' => 'untouched', 'cubicasa_status' => 'Draft', 'cubicasa_last_synced_at' => now()->subDays(4)]);
        Http::fake([
            self::BASE_URL.'/orders/failed' => Http::response([], 503),
            self::BASE_URL.'/orders/untouched' => Http::response(['id' => 'untouched', 'info' => ['status' => 'Draft']], 200),
            self::BASE_URL.'/orders?*' => Http::response(['items' => [], 'pagination' => ['has_more' => false]], 200),
        ]);

        $this->artisan('cubicasa:resync-pending', ['--limit' => 1])->assertFailed();
        $this->assertNotNull($failed->fresh()->cubicasa_sync_started_at);
        $this->artisan('cubicasa:resync-pending', ['--limit' => 1])->assertSuccessful();
        $this->assertTrue($untouched->fresh()->cubicasa_last_synced_at->isToday());
    }

    public function test_active_queued_syncs_do_not_consume_the_batch_limit(): void
    {
        $this->shoot(['cubicasa_order_id' => 'running', 'cubicasa_status' => 'Draft', 'cubicasa_sync_status' => 'running', 'cubicasa_sync_started_at' => now()]);
        $pending = $this->shoot(['cubicasa_order_id' => 'pending', 'cubicasa_status' => 'Draft']);
        $this->fakeOrders('Draft');

        $this->artisan('cubicasa:resync-pending', ['--limit' => 1])->assertSuccessful();
        Http::assertNotSent(fn ($request) => $request->url() === self::BASE_URL.'/orders/running');
        $this->assertNotNull($pending->fresh()->cubicasa_last_synced_at);
    }

    public function test_ready_orders_with_missing_or_partial_assets_are_retried_but_complete_orders_are_not(): void
    {
        $plans = [['asset_key' => 'pdf_listing_dim_0', 'url' => 'https://assets.test/one.pdf'], ['asset_key' => 'pdf_listing_dim_1', 'url' => 'https://assets.test/two.pdf']];
        $missing = $this->shoot(['cubicasa_order_id' => 'missing', 'cubicasa_status' => 'Ready', 'cubicasa_floorplans' => $plans]);
        $partial = $this->shoot(['cubicasa_order_id' => 'partial', 'cubicasa_status' => 'Ready', 'cubicasa_floorplans' => $plans]);
        $complete = $this->shoot(['cubicasa_order_id' => 'complete', 'cubicasa_status' => 'Ready', 'cubicasa_floorplans' => $plans]);
        $this->file($partial, 'pdf_listing_dim_0');
        $this->file($complete, 'pdf_listing_dim_0');
        $this->file($complete, 'pdf_listing_dim_1');
        $this->fakeOrders('Ready');

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();

        Http::assertSentCount(2);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, fn ($job) => $job->shootId === $missing->id);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, fn ($job) => $job->shootId === $partial->id);
        $this->assertNull($complete->fresh()->cubicasa_last_synced_at);
    }

    public function test_ready_order_with_failed_sync_is_retried(): void
    {
        $shoot = $this->shoot(['cubicasa_order_id' => 'failed-ready', 'cubicasa_status' => 'Ready', 'cubicasa_sync_status' => 'failed']);
        $this->fakeOrders('Ready');

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
        $this->assertSame('succeeded', $shoot->fresh()->cubicasa_sync_status);
    }

    public function test_external_id_only_order_is_reconciled(): void
    {
        $shoot = $this->shoot(['cubicasa_external_id' => 'external-only', 'cubicasa_status' => 'Draft']);
        Http::fake([
            self::BASE_URL.'/orders?*' => Http::response(['items' => [['id' => 'found-order', 'info' => ['external_id' => 'external-only', 'status' => 'Draft']]], 'pagination' => ['has_more' => false]], 200),
            self::BASE_URL.'/orders/found-order' => Http::response(['id' => 'found-order', 'info' => ['external_id' => 'external-only', 'status' => 'Draft']], 200),
        ]);

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
        $this->assertSame('found-order', $shoot->fresh()->cubicasa_order_id);
        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    public function test_ineligible_newer_bookings_do_not_hide_an_eligible_missing_order(): void
    {
        $eligible = $this->shoot(['workflow_status' => '']);
        $this->shoot([], false);
        $this->shoot([], false);

        $this->artisan('cubicasa:resync-pending', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(CreateCubiCasaOrderJob::class, fn ($job) => $job->shootId === $eligible->id);
        Queue::assertPushed(CreateCubiCasaOrderJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_backfill_never_creates_orders_for_unapproved_or_finished_shoots(): void
    {
        foreach (['requested', 'on_hold', 'hold_on', 'cancelled', 'declined', 'ready', 'delivered', 'completed'] as $status) {
            $this->shoot(['status' => $status]);
            $this->shoot(['workflow_status' => $status]);
        }
        $this->shoot(['scheduled_at' => null]);

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
        Http::assertNothingSent();
    }

    public function test_queued_jobs_recheck_approval_completion_and_schedule_before_ordering(): void
    {
        $service = \Mockery::mock(CubiCasaService::class);
        $service->shouldNotReceive('hasCredentials');
        $service->shouldNotReceive('createOrder');
        foreach (['requested', 'on_hold', 'cancelled', 'declined', 'ready', 'delivered', 'completed'] as $status) {
            foreach (['status', 'workflow_status'] as $field) {
                $shoot = $this->shoot([$field => $status]);
                (new CreateCubiCasaOrderJob($shoot->id))->handle($service);
            }
        }
        $shoot = $this->shoot(['scheduled_at' => null]);
        (new CreateCubiCasaOrderJob($shoot->id))->handle($service);
        Http::assertNothingSent();
    }

    public function test_an_explicit_manual_order_remains_available_for_a_finished_shoot(): void
    {
        $shoot = $this->shoot(['status' => 'delivered', 'workflow_status' => 'delivered']);
        Http::fake([self::BASE_URL.'/orders/draft' => Http::response(['id' => 'manual-order', 'info' => ['status' => 'Draft']], 200)]);

        $this->assertNotNull(app(CubiCasaService::class)->createOrder($shoot));
        $this->assertSame('manual-order', $shoot->fresh()->cubicasa_order_id);
    }

    private function shoot(array $attributes = [], bool $eligible = true): Shoot
    {
        $service = Service::factory()->create(['name' => $eligible ? '2D Floor Plan' : 'HDR Photography',
            'category_id' => \App\Models\Category::firstOrCreate(['name' => $eligible ? 'Floor Plans' : 'Photos'])->id]);
        return Shoot::factory()->create(array_merge([
            'service_id' => $service->id,
            'status' => 'scheduled',
            'workflow_status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
        ], $attributes));
    }

    private function fakeOrders(string $status): void
    {
        Http::fake([
            self::BASE_URL.'/orders?*' => Http::response(['items' => [], 'pagination' => ['has_more' => false]], 200),
            self::BASE_URL.'/orders/*' => fn ($request) => Http::response([
                'id' => basename($request->url()),
                'info' => ['status' => $status],
                'delivery_assets' => $status === 'Ready' ? ['listing_floorplans' => ['pdf_urls_dim' => ['https://assets.test/one.pdf', 'https://assets.test/two.pdf']]] : [],
            ], 200),
        ]);
    }

    private function file(Shoot $shoot, string $key): void
    {
        ShootFile::query()->create([
            'shoot_id' => $shoot->id,
            'filename' => $key.'.pdf',
            'stored_filename' => $key.'.pdf',
            'path' => 'floorplans/'.$key.'.pdf',
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'media_type' => 'floorplan',
            'file_size' => 100,
            'uploaded_by' => $shoot->client_id,
            'metadata' => ['source' => 'cubicasa', 'cubicasa_asset_key' => $key],
        ]);
    }
}
