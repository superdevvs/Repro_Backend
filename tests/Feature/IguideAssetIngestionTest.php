<?php

namespace Tests\Feature;

use App\Jobs\IngestIguideAssetsJob;
use App\Jobs\SyncShootFileToDropboxJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IguideAssetIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function floorplans(): array
    {
        return [
            [
                'asset_key' => 'pdf_imperial',
                'url' => 'https://youriguide.com/sample/doc/floorplan_imperial.pdf?accessToken=abc',
                'filename' => 'floorplan_imperial.pdf',
                'label' => 'Floor Plan (Imperial)',
                'type' => 'pdf',
                'units' => 'imperial',
            ],
            [
                'asset_key' => 'jpg_metric_floor_1',
                'url' => 'https://youriguide.com/sample/doc/floor_metric_1.jpg?accessToken=abc',
                'filename' => 'floor_metric_1.jpg',
                'label' => 'Main Floor (Metric)',
                'type' => 'jpg',
                'units' => 'metric',
                'floor_name' => 'Main Floor',
                'floor_id' => 1,
            ],
        ];
    }

    public function test_ingests_floorplans_as_shoot_files_and_dispatches_dropbox_sync(): void
    {
        Queue::fake();

        Http::fake([
            '*floorplan_imperial.pdf*' => Http::response('PDFDATA', 200, ['Content-Type' => 'application/pdf']),
            '*floor_metric_1.jpg*' => Http::response('JPGDATA', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        // Need an admin user so the system uploader can be resolved (uploaded_by FK).
        User::factory()->create(['role' => 'admin']);

        $shoot = Shoot::factory()->create();

        (new IngestIguideAssetsJob($shoot->id, $this->floorplans()))
            ->handle(app(\App\Services\ShootActivityLogger::class));

        $files = ShootFile::where('shoot_id', $shoot->id)->where('media_type', 'floorplan')->get();
        $this->assertCount(2, $files);

        $pdfFile = $files->first(fn (ShootFile $f) => ($f->metadata['iguide_asset_key'] ?? null) === 'pdf_imperial');
        $jpgFile = $files->first(fn (ShootFile $f) => ($f->metadata['iguide_asset_key'] ?? null) === 'jpg_metric_floor_1');

        $this->assertNotNull($pdfFile);
        $this->assertNotNull($jpgFile);
        $this->assertSame('iguide', $pdfFile->metadata['source']);
        $this->assertSame('imperial', $pdfFile->metadata['units']);
        $this->assertSame('Main Floor', $jpgFile->metadata['floor_name']);
        $this->assertSame('application/pdf', $pdfFile->mime_type);

        // Stored as public files under shoots/{id}/floorplans/.
        $relPdf = ltrim(str_replace('storage/', '', (string) $pdfFile->storage_path), '/');
        Storage::disk('public')->assertExists($relPdf);

        Queue::assertPushed(SyncShootFileToDropboxJob::class, 2);
    }

    public function test_is_idempotent_when_assets_already_exist(): void
    {
        Queue::fake();

        Http::fake([
            '*' => Http::response('PDFDATA', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'floorplan_imperial.pdf',
            'stored_filename' => 'floorplan_imperial.pdf',
            'path' => 'storage/shoots/' . $shoot->id . '/floorplans/floorplan_imperial.pdf',
            'storage_path' => 'storage/shoots/' . $shoot->id . '/floorplans/floorplan_imperial.pdf',
            'file_type' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'media_type' => 'floorplan',
            'file_size' => 7,
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'metadata' => [
                'source' => 'iguide',
                'iguide_asset_key' => 'pdf_imperial',
                'original_url' => 'https://youriguide.com/sample/doc/floorplan_imperial.pdf?accessToken=abc',
            ],
        ]);

        (new IngestIguideAssetsJob($shoot->id, [$this->floorplans()[0]]))
            ->handle(app(\App\Services\ShootActivityLogger::class));

        // No duplicate ShootFile created.
        $this->assertSame(1, ShootFile::where('shoot_id', $shoot->id)->where('media_type', 'floorplan')->count());

        Queue::assertNotPushed(SyncShootFileToDropboxJob::class);
        // No HTTP download attempt for the already-ingested asset.
        Http::assertNothingSent();
    }
}
