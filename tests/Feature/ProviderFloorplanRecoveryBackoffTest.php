<?php

namespace Tests\Feature;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Jobs\IngestIguideAssetsJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\CubiCasaService;
use App\Services\Shoots\ProviderAssetRecoveryThrottle;
use App\Services\Shoots\ProviderFloorplanRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Production 2026-11: about 100 delivered iGUIDE shoots whose assets the provider never serves were
 * re-imported every 30 minutes forever (≈4,800 failed jobs a day) and, because the sweep is
 * oldest-first with a work limit, they kept newer shoots from ever being recovered. A cancelled
 * CubiCasa shoot looped the same way through cubicasa:resync-pending.
 */
class ProviderFloorplanRecoveryBackoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
    }

    private function shoot(string $provider, string $status = 'delivered'): Shoot
    {
        $shoot = Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'shoot_type' => 'standard', 'status' => 'delivered',
            $provider.'_floorplans' => [['asset_key' => 'existing', 'url' => 'https://assets.test/a.pdf'], ['asset_key' => 'missing', 'url' => 'https://assets.test/b.pdf']],
        ]));
        // Raw update: the model's saving hooks must not rewrite the status under test.
        DB::table('shoots')->where('id', $shoot->id)->update(['status' => $status, 'workflow_status' => $status]);
        $this->file($shoot, $provider, 'existing');

        return $shoot->fresh();
    }

    private function file(Shoot $shoot, string $provider, string $key): ShootFile
    {
        return ShootFile::withoutEvents(fn () => ShootFile::create([
            'shoot_id' => $shoot->id, 'shoot_service_id' => null, 'media_type' => 'floorplan',
            'filename' => $key.'.pdf', 'stored_filename' => $key.'.pdf', 'path' => $key.'.pdf', 'file_type' => 'application/pdf', 'file_size' => 100,
            'uploaded_by' => \App\Models\User::factory()->create()->id, 'scan_status' => 'clean', 'web_path' => $key.'.jpg',
            'metadata' => ['source' => $provider, $provider.'_asset_key' => $key],
        ]));
    }

    private function imports(string $provider = 'iguide', int $limit = 100): int
    {
        return app(ProviderFloorplanRecovery::class)->recover($provider, $limit)['imports'];
    }

    public function test_an_asset_the_provider_never_serves_is_retried_with_growing_delays_then_given_up(): void
    {
        $this->shoot('iguide');
        $at = now()->startOfMinute();
        $this->travelTo($at);
        $this->assertSame(1, $this->imports(), 'A newly missing asset is imported at once.');

        // The old 20-minute coalescing let the same asset be re-queued every 30 minutes forever.
        $this->travelTo($at->copy()->addMinutes(30));
        $this->assertSame(0, $this->imports(), 'The second attempt waits for the first delay.');

        $delays = [60, 120, 240, 480, 960, 1440, 1440, 1440, 1440];
        foreach ($delays as $attempt => $minutes) {
            $this->travelTo($at->copy()->addMinutes($minutes - 1));
            $this->assertSame(0, $this->imports(), 'Attempt '.($attempt + 2).' is not due yet.');
            $at = $at->copy()->addMinutes($minutes);
            $this->travelTo($at);
            $this->assertSame(1, $this->imports(), 'Attempt '.($attempt + 2).' is due.');
        }

        $this->travelTo($at->copy()->addDays(30));
        $result = app(ProviderFloorplanRecovery::class)->recover('iguide');
        $this->assertSame(0, $result['imports'], 'After the last attempt the asset set is given up.');
        $this->assertSame(1, $result['abandoned']);
        Queue::assertPushed(IngestIguideAssetsJob::class, ProviderAssetRecoveryThrottle::MAX_ATTEMPTS);
        $this->assertNotNull(DB::table('provider_asset_recovery_attempts')->value('gave_up_at'));
    }

    public function test_shoots_waiting_to_retry_do_not_use_the_work_limit_so_newer_shoots_are_recovered(): void
    {
        $stuck = $this->shoot('iguide');
        $this->assertSame(1, $this->imports('iguide', 1));
        $newer = $this->shoot('iguide');

        $this->travel(30)->minutes();
        $result = app(ProviderFloorplanRecovery::class)->recover('iguide', 1);

        $this->assertSame(1, $result['imports']);
        $this->assertSame(1, $result['deferred']);
        Queue::assertPushed(IngestIguideAssetsJob::class, fn ($job) => $job->shootId === $newer->id);
        $this->assertSame(1, Queue::pushed(IngestIguideAssetsJob::class, fn ($job) => $job->shootId === $stuck->id)->count());
    }

    public function test_new_provider_links_are_imported_immediately_even_while_waiting_to_retry(): void
    {
        $shoot = $this->shoot('iguide');
        $this->assertSame(1, $this->imports());

        $this->travel(5)->minutes();
        $shoot->update(['iguide_floorplans' => [['asset_key' => 'existing', 'url' => 'https://assets.test/a.pdf'], ['asset_key' => 'missing', 'url' => 'https://assets.test/b-v2.pdf']]]);

        $this->assertSame(1, $this->imports());
        Queue::assertPushed(IngestIguideAssetsJob::class, fn ($job) => $job->floorplans[0]['url'] === 'https://assets.test/b-v2.pdf');
    }

    public function test_a_successful_import_clears_the_retry_schedule(): void
    {
        $shoot = $this->shoot('iguide');
        $this->assertSame(1, $this->imports());
        $this->assertDatabaseCount('provider_asset_recovery_attempts', 1);

        $this->file($shoot, 'iguide', 'missing');
        $this->assertSame(0, $this->imports());
        $this->assertDatabaseCount('provider_asset_recovery_attempts', 0);
    }

    public function test_cancelled_and_declined_shoots_are_not_recovered(): void
    {
        foreach (['cancelled', 'canceled', 'declined'] as $status) {
            foreach (['iguide', 'cubicasa'] as $provider) $this->shoot($provider, $status);
        }

        foreach (['iguide', 'cubicasa'] as $provider) {
            $result = app(ProviderFloorplanRecovery::class)->recover($provider);
            $this->assertSame(0, $result['shoots']);
            $this->assertSame(0, $result['imports']);
        }
        Queue::assertNothingPushed();
    }

    public function test_dry_run_neither_queues_nor_records_attempts(): void
    {
        $this->shoot('iguide');
        $this->artisan('floorplans:recover-provider-assets', ['--provider' => 'iguide', '--dry-run' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('provider_asset_recovery_attempts', 0);
        $this->assertSame(1, $this->imports());
    }

    private function cubicasaReadyShoot(string $status = 'delivered'): Shoot
    {
        $shoot = $this->shoot('cubicasa', $status);
        $service = \App\Models\Service::factory()->create(['name' => 'Floor Plan']);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1]);
        DB::table('shoots')->where('id', $shoot->id)->update(['cubicasa_order_id' => 'ready-order', 'cubicasa_status' => 'Ready']);

        return $shoot->fresh();
    }

    private function mockCubiCasaSync(int $times): void
    {
        $this->mock(CubiCasaService::class, function ($mock) use ($times) {
            $mock->shouldReceive('hasCredentials')->andReturn(true);
            $mock->shouldReceive('syncShoot')->times($times)->andReturn(['floorplans' => [
                ['asset_key' => 'existing', 'url' => 'https://assets.test/a.pdf'], ['asset_key' => 'missing', 'url' => 'https://assets.test/b.pdf'],
            ]]);
        });
    }

    private function refreshImports(): int
    {
        return Queue::pushed(IngestCubiCasaAssetsJob::class, fn ($job) => $job->refreshExisting)->count();
    }

    public function test_cubicasa_resync_retries_the_same_ready_assets_with_growing_delays(): void
    {
        $this->cubicasaReadyShoot();
        $this->mockCubiCasaSync(3);

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
        $this->assertSame(1, $this->refreshImports());

        $this->travel(30)->minutes();
        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
        $this->assertSame(1, $this->refreshImports(), 'The same Ready assets are not re-imported every 30 minutes.');

        $this->travel(31)->minutes();
        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
        $this->assertSame(2, $this->refreshImports());
    }

    public function test_cubicasa_resync_does_not_reimport_assets_for_cancelled_shoots(): void
    {
        $this->cubicasaReadyShoot('cancelled');
        $this->mock(CubiCasaService::class, function ($mock) {
            $mock->shouldReceive('hasCredentials')->andReturn(true);
            $mock->shouldNotReceive('syncShoot');
        });

        $this->artisan('cubicasa:resync-pending')->assertSuccessful();

        Queue::assertNotPushed(IngestCubiCasaAssetsJob::class);
    }
}
