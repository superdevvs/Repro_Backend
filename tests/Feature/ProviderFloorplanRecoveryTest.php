<?php

namespace Tests\Feature;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Jobs\IngestIguideAssetsJob;
use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\ProviderFloorplanRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProviderFloorplanRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function shoot(string $provider): Shoot
    {
        return Shoot::withoutEvents(fn () => Shoot::factory()->create([
            'shoot_type' => 'standard', 'status' => 'delivered',
            $provider.'_floorplans' => [['asset_key' => 'existing', 'url' => 'https://assets.test/a.pdf'], ['asset_key' => 'missing', 'url' => 'https://assets.test/b.pdf']],
        ]));
    }

    private function file(Shoot $shoot, string $provider, string $status, string $key = 'existing'): ShootFile
    {
        return ShootFile::withoutEvents(fn () => ShootFile::create([
            'shoot_id' => $shoot->id, 'shoot_service_id' => null, 'media_type' => 'floorplan',
            'filename' => $key.'.pdf', 'stored_filename' => $key.'.pdf', 'path' => $key.'.pdf', 'file_type' => 'application/pdf', 'file_size' => 100, 'uploaded_by' => \App\Models\User::factory()->create()->id,
            'scan_status' => $status, 'metadata' => ['source' => $provider, $provider.'_asset_key' => $key],
            'web_path' => null,
        ]));
    }

    public function test_both_providers_recover_present_quarantine_and_missing_assets_without_duplicates(): void
    {
        Queue::fake(); Cache::flush();
        foreach (['cubicasa' => IngestCubiCasaAssetsJob::class, 'iguide' => IngestIguideAssetsJob::class] as $provider => $job) {
            $shoot = $this->shoot($provider); $file = $this->file($shoot, $provider, 'quarantined');
            $result = app(ProviderFloorplanRecovery::class)->recover($provider);
            $this->assertSame(1, $result['scans']); $this->assertSame(1, $result['imports']);
            Queue::assertPushed($job, fn ($j) => $j->shootId === $shoot->id && count($j->floorplans) === 1 && $j->floorplans[0]['asset_key'] === 'missing');
            Queue::assertPushed(ScanShootFileJob::class, fn ($j) => $j->shootFileId === $file->id);
            $again = app(ProviderFloorplanRecovery::class)->recover($provider);
            $this->assertSame(0, $again['scans']); $this->assertSame(0, $again['imports']);
            $this->assertSame('quarantined', $file->fresh()->scan_status);
            $this->assertSame('delivered', $shoot->fresh()->status);
        }
    }

    public function test_dry_run_has_no_queue_or_verdict_side_effect_and_does_not_suppress_real_recovery(): void
    {
        Queue::fake(); Cache::flush(); $shoot = $this->shoot('cubicasa'); $file = $this->file($shoot, 'cubicasa', 'failed');
        $this->artisan('floorplans:recover-provider-assets', ['--provider' => 'cubicasa', '--dry-run' => true])->assertSuccessful();
        Queue::assertNothingPushed(); $this->assertSame('failed', $file->fresh()->scan_status);
        $this->assertSame(1, app(ProviderFloorplanRecovery::class)->recover('cubicasa')['scans']);
    }

    public function test_clean_and_infected_verdicts_are_never_rescanned_and_only_clean_missing_preview_is_processed(): void
    {
        Queue::fake(); Cache::flush(); $shoot = $this->shoot('iguide');
        $clean = $this->file($shoot, 'iguide', 'clean'); $infected = $this->file($shoot, 'iguide', 'infected', 'missing');
        $result = app(ProviderFloorplanRecovery::class)->recover('iguide');
        $this->assertSame(0, $result['scans']); $this->assertSame(0, $result['imports']); $this->assertSame(1, $result['previews']);
        Queue::assertNotPushed(ScanShootFileJob::class); Queue::assertPushed(ProcessImageJob::class, 1);
        $this->assertSame('infected', $infected->fresh()->scan_status); $this->assertSame('clean', $clean->fresh()->scan_status);
    }

    public function test_internal_test_shoots_and_unrelated_manual_files_are_not_recovered(): void
    {
        Queue::fake(); Cache::flush(); $shoot = $this->shoot('cubicasa');
        $shoot->update(['shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST]); $this->file($shoot, 'cubicasa', 'quarantined');
        $this->assertSame(0, app(ProviderFloorplanRecovery::class)->recover('cubicasa')['shoots']); Queue::assertNothingPushed();
    }

    public function test_complete_shoots_do_not_consume_the_work_limit_or_starve_old_pending_files(): void
    {
        Queue::fake(); Cache::flush(); $complete = $this->shoot('cubicasa');
        foreach (['existing', 'missing'] as $key) $this->file($complete, 'cubicasa', 'clean', $key)->update(['web_path' => 'ready.jpg']);
        $pending = $this->shoot('cubicasa'); $file = $this->file($pending, 'cubicasa', 'quarantined');
        $this->assertSame(1, app(ProviderFloorplanRecovery::class)->recover('cubicasa', 1)['scans']);
        Queue::assertPushed(ScanShootFileJob::class, fn ($j) => $j->shootFileId === $file->id);
    }

    public function test_ready_cubicasa_order_with_no_floorplans_is_refetched(): void
    {
        Queue::fake(); Cache::flush();
        $shoot = $this->shoot('cubicasa');
        $shoot->update(['cubicasa_floorplans' => [], 'cubicasa_status' => 'Ready', 'cubicasa_order_id' => 'ready-order']);
        $this->mock(\App\Services\CubiCasaService::class, function ($mock) use ($shoot) {
            $mock->shouldReceive('hasCredentials')->once()->andReturn(true);
            $mock->shouldReceive('syncShoot')->once()->withArgs(fn ($candidate) => $candidate->id === $shoot->id)->andReturn(null);
        });
        $this->artisan('cubicasa:resync-pending')->assertSuccessful();
    }

    public function test_iguide_with_existing_tour_but_no_plans_is_resynced_even_when_old(): void
    {
        Queue::fake(); Cache::flush();
        $shoot = $this->shoot('iguide');
        $service = \App\Models\Service::factory()->create(['name' => 'iGUIDE Floor Plan']);
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1]);
        $shoot->update(['iguide_floorplans' => [], 'iguide_tour_url' => 'https://youriguide.com/old/', 'scheduled_date' => '2020-01-01']);
        \Illuminate\Support\Facades\DB::table('shoots')->where('id', $shoot->id)->update(['updated_at' => '2020-01-01']);
        $this->artisan('iguide:resync-pending')->assertSuccessful();
        Queue::assertPushed(\App\Jobs\SyncShootIguideJob::class, fn ($job) => $job->shootId === $shoot->id);
    }

    public function test_unit_scopes_are_recovered_independently_without_sibling_file_deduplication(): void
    {
        Queue::fake(); Cache::flush(); $shoot = $this->shoot('cubicasa');
        $service = \App\Models\Service::factory()->create(['name' => 'Floor Plan']);
        foreach (['One', 'Two'] as $label) {
            $unit = $shoot->units()->create(['label' => $label, 'client_key' => (string) \Illuminate\Support\Str::uuid()]);
            $line = \App\Models\ShootService::create(['shoot_id' => $shoot->id, 'shoot_unit_id' => $unit->id, 'service_id' => $service->id, 'quantity' => 1, 'price' => 100]);
            $unit->update(['provider_data' => ['lines' => [$line->id => ['cubicasa_floorplans' => [['asset_key' => 'same-key', 'url' => 'https://assets.test/a.pdf']]]]]]);
        }
        $this->assertSame(2, app(ProviderFloorplanRecovery::class)->recover('cubicasa')['imports']);
        Queue::assertPushed(IngestCubiCasaAssetsJob::class, 2);
    }

    public function test_existing_provider_file_is_recovered_even_if_the_provider_asset_list_is_lost(): void
    {
        Queue::fake(); Cache::flush(); $shoot = $this->shoot('cubicasa');
        $shoot->update(['cubicasa_floorplans' => null]); $file = $this->file($shoot, 'cubicasa', 'quarantined');
        $this->assertSame(1, app(ProviderFloorplanRecovery::class)->recover('cubicasa')['scans']);
        Queue::assertPushed(ScanShootFileJob::class, fn ($j) => $j->shootFileId === $file->id);
    }
}
