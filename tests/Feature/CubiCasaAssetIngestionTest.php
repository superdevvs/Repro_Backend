<?php

namespace Tests\Feature;

use App\Jobs\IngestCubiCasaAssetsJob;
use App\Jobs\SyncShootFileToDropboxJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CubiCasaAssetIngestionTest extends TestCase
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
                'asset_key' => 'pdf_listing_dim_0',
                'url' => 'https://s3.example.com/521-merged-dim.pdf',
                'filename' => '521-merged-dim.pdf',
                'label' => 'Floor Plan PDF (Dimensioned)',
                'type' => 'pdf',
                'units' => 'imperial',
            ],
            [
                'asset_key' => 'jpg_listing_dim_0',
                'url' => 'https://s3.example.com/floor-1-dim.jpg',
                'filename' => 'floor-1-dim.jpg',
                'label' => 'Floor 1 (Dimensioned)',
                'type' => 'jpg',
                'units' => 'imperial',
                'floor_id' => 0,
            ],
        ];
    }

    private function attachCubicasaService(Shoot $shoot): void
    {
        $service = Service::factory()->create(['name' => '2D Floor plans']);
        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 195,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_ingests_floorplans_as_shoot_files_and_dispatches_dropbox_sync(): void
    {
        Queue::fake();
        Http::fake([
            '*521-merged-dim.pdf*' => Http::response('PDFDATA', 200, ['Content-Type' => 'application/pdf']),
            '*floor-1-dim.jpg*' => Http::response('JPGDATA', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $this->attachCubicasaService($shoot);

        (new IngestCubiCasaAssetsJob($shoot->id, $this->floorplans()))
            ->handle(app(\App\Services\ShootActivityLogger::class));

        $files = ShootFile::where('shoot_id', $shoot->id)->where('media_type', 'floorplan')->get();
        $this->assertCount(2, $files);

        $pdf = $files->first(fn (ShootFile $f) => ($f->metadata['cubicasa_asset_key'] ?? null) === 'pdf_listing_dim_0');
        $jpg = $files->first(fn (ShootFile $f) => ($f->metadata['cubicasa_asset_key'] ?? null) === 'jpg_listing_dim_0');

        $this->assertNotNull($pdf);
        $this->assertNotNull($jpg);
        $this->assertSame('cubicasa', $pdf->metadata['source']);
        $this->assertSame('application/pdf', $pdf->mime_type);
        $this->assertSame('image/jpeg', $jpg->mime_type);

        $relPdf = ltrim(str_replace('storage/', '', (string) $pdf->storage_path), '/');
        Storage::disk('public')->assertExists($relPdf);

        Queue::assertNotPushed(SyncShootFileToDropboxJob::class);
    }

    public function test_skips_ingestion_when_shoot_has_no_eligible_service(): void
    {
        Queue::fake();
        Http::fake();

        User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        // No CubiCasa-eligible service attached.

        (new IngestCubiCasaAssetsJob($shoot->id, $this->floorplans()))
            ->handle(app(\App\Services\ShootActivityLogger::class));

        $this->assertSame(0, ShootFile::where('shoot_id', $shoot->id)->count());
        Http::assertNothingSent();
        Queue::assertNotPushed(SyncShootFileToDropboxJob::class);
    }

    public function test_is_idempotent_when_assets_already_exist(): void
    {
        Queue::fake();
        Http::fake([
            '*' => Http::response('PDFDATA', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $this->attachCubicasaService($shoot);

        ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => '521-merged-dim.pdf',
            'stored_filename' => '521-merged-dim.pdf',
            'path' => 'storage/shoots/' . $shoot->id . '/floorplans/521-merged-dim.pdf',
            'storage_path' => 'storage/shoots/' . $shoot->id . '/floorplans/521-merged-dim.pdf',
            'file_type' => 'application/pdf',
            'mime_type' => 'application/pdf',
            'media_type' => 'floorplan',
            'file_size' => 7,
            'uploaded_by' => $admin->id,
            'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'metadata' => [
                'source' => 'cubicasa',
                'cubicasa_asset_key' => 'pdf_listing_dim_0',
                'original_url' => 'https://s3.example.com/521-merged-dim.pdf',
            ],
        ]);

        (new IngestCubiCasaAssetsJob($shoot->id, [$this->floorplans()[0]]))
            ->handle(app(\App\Services\ShootActivityLogger::class));

        $this->assertSame(1, ShootFile::where('shoot_id', $shoot->id)->where('media_type', 'floorplan')->count());
        Queue::assertNotPushed(SyncShootFileToDropboxJob::class);
        Http::assertNothingSent();
    }
}
