<?php

namespace Tests\Feature\Storage;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Media\OriginalsStorageGuard;
use App\Services\Shoots\FloorplanPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class FloorplanPdfStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! (new ExecutableFinder)->find('pdftoppm')) {
            $this->markTestSkipped('PDF integration requires the production pdftoppm dependency.');
        }
        foreach (['local', 'public', 'media_originals', 'media'] as $disk) {
            Storage::fake($disk);
        }
        Queue::fake();
        config()->set([
            'media.local_disk' => 'local', 'media.originals_disk' => 'media_originals',
            'media.remote_disk' => 'media', 'media.legacy_public_disk' => 'public',
            'media.tiered_storage_enabled' => false, 'media.dual_write' => false,
            'media.read_from_r2' => false, 'media.r2_only' => false,
        ]);
    }

    public function test_r2_only_pdf_is_downloaded_rendered_and_temporary_source_removed(): void
    {
        config()->set(['media.r2_only' => true, 'media.read_from_r2' => true]);
        $temporarySource = null;
        $media = Mockery::mock(MediaStorage::class)->makePartial();
        $media->shouldReceive('downloadToTemp')->once()->andReturnUsing(
            function (string $key, ?string $suffix) use (&$temporarySource): ?string {
                return $temporarySource = (new MediaStorage)->downloadToTemp($key, $suffix);
            }
        );
        $this->app->instance(MediaStorage::class, $media);
        $file = $this->pdfFile('media');
        $result = app(FloorplanPreviewService::class)->ensurePreview($file);
        $this->assertRendered($file, $result, 'media');
        $this->assertNotNull($temporarySource);
        $this->assertFileDoesNotExist($temporarySource);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('media_originals')->allFiles());
    }

    public function test_hdd_pdf_keeps_original_bytes_and_writes_previews_only_to_nvme(): void
    {
        config()->set('media.tiered_storage_enabled', true);
        $guard = Mockery::mock(OriginalsStorageGuard::class);
        $guard->shouldReceive('assertAvailable')->andReturnNull();
        $guard->shouldReceive('isAvailable')->andReturnTrue();
        $this->app->instance(OriginalsStorageGuard::class, $guard);
        $file = $this->pdfFile('media_originals');
        $result = app(FloorplanPreviewService::class)->ensurePreview($file);
        $this->assertRendered($file, $result, 'local');
        $this->assertSame([$file->path], Storage::disk('media_originals')->allFiles());
        Storage::disk('local')->assertMissing($file->path);
        $this->assertSame($this->pdfBytes(), Storage::disk('media_originals')->get($file->path));
    }

    private function pdfFile(string $disk): ShootFile
    {
        $owner = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $path = "shoots/{$shoot->id}/floorplans/plan.pdf";
        Storage::disk($disk)->put($path, $this->pdfBytes());

        return ShootFile::create([
            'shoot_id' => $shoot->id, 'filename' => 'plan.pdf', 'stored_filename' => 'plan.pdf',
            'path' => $path, 'storage_path' => $path, 'file_type' => 'application/pdf',
            'file_size' => strlen($this->pdfBytes()), 'media_type' => 'floorplan',
            'uploaded_by' => $owner->id, 'workflow_stage' => ShootFile::STAGE_COMPLETED,
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ]);
    }

    private function assertRendered(ShootFile $file, array $result, string $disk): void
    {
        $this->assertSame('pdf_rendered', $result['status']);
        $this->assertCount(1, $result['preview_images']);
        $file->refresh();
        $this->assertSame($result['preview_images'][0], $file->web_path);
        Storage::disk($disk)->assertExists($file->web_path);
        $image = getimagesizefromstring(Storage::disk($disk)->get($file->web_path));
        $this->assertSame('image/jpeg', $image['mime']);
        $this->assertGreaterThan(100, $image[0]);
        $this->assertGreaterThan(100, $image[1]);
        $this->assertSame('already_present', app(FloorplanPreviewService::class)->ensurePreview($file)['status']);
    }

    private function pdfBytes(): string
    {
        $stream = "0 0 1 RG 4 w 20 20 160 160 re S\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 5\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
