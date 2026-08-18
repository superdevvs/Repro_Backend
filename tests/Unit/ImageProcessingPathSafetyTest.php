<?php

namespace Tests\Unit;

use App\Models\ShootFile;
use App\Services\ImageProcessingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: processImage() must never destroy a rendition it failed to
 * regenerate, and must not report success when it produced nothing.
 *
 * The original implementation wrote every column unconditionally:
 *
 *     'thumbnail_path' => $generatedPaths['thumbnail'] ?? null,
 *     'grid_path'      => $generatedPaths['grid']      ?? null,
 *     ...
 *     return true;
 *
 * so a run in which generateSize() failed for every size overwrote four
 * working paths with null AND returned true. Observed on production
 * 2026-08-18: a backfill attempt blanked thumbnail/web/grid/placeholder for a
 * file whose derivative images were still present on disk, and reported
 * success while doing it. The underlying write failure was a permissions
 * issue, but any transient failure would corrupt data the same way.
 */
class ImageProcessingPathSafetyTest extends TestCase
{
    /** A ShootFile that records update() payloads instead of touching the DB. */
    private function fileWithExistingRenditions(): ShootFile
    {
        return new class extends ShootFile {
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];

            public function update(array $attributes = [], array $options = []): bool
            {
                $this->updates[] = $attributes;
                $this->forceFill($attributes);

                return true;
            }

            public function save(array $options = []): bool
            {
                return true;
            }
        };
    }

    private function seededFile(): ShootFile
    {
        $file = $this->fileWithExistingRenditions();

        $file->id = 1503;
        $file->shoot_id = 74;
        $file->filename = 'listing-photo.jpg';
        $file->scan_status = ShootFile::SCAN_STATUS_CLEAN;
        $file->path = 'shoots/74/completed/listing-photo.jpg';
        $file->processed_at = now();
        $file->thumbnail_path = 'shoots/74/thumbnails/listing-photo_thumbnail.jpg';
        $file->web_path = 'shoots/74/webs/listing-photo_web.jpg';
        $file->placeholder_path = 'shoots/74/placeholders/listing-photo_placeholder.jpg';
        $file->grid_path = null;

        return $file;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * A real decodable JPEG so extractImagePreview() succeeds and execution
     * reaches the size loop. Written directly rather than via UploadedFile::fake(),
     * whose temp file is removed when the object is garbage collected — that made
     * these tests pass vacuously by bailing out before the update under test.
     */
    private function realSourcePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img_path_safety_') . '.jpg';

        $image = imagecreatetruecolor(1600, 900);
        imagefilledrectangle($image, 0, 0, 1599, 899, imagecolorallocate($image, 120, 130, 140));
        imagejpeg($image, $path, 85);
        imagedestroy($image);

        $this->tempFiles[] = $path;

        return $path;
    }

    /** Service whose size generation always fails, as an unwritable disk would cause. */
    private function serviceThatCannotGenerate(): ImageProcessingService
    {
        return new class extends ImageProcessingService {
            protected function generateSize($image, int $shootId, string $fileName, string $sizeName, array $config): ?string
            {
                return null;
            }
        };
    }

    /** Service that can only produce the grid rendition. */
    private function serviceThatOnlyGeneratesGrid(): ImageProcessingService
    {
        return new class extends ImageProcessingService {
            protected function generateSize($image, int $shootId, string $fileName, string $sizeName, array $config): ?string
            {
                return $sizeName === 'grid'
                    ? "shoots/{$shootId}/grids/listing-photo_grid.jpg"
                    : null;
            }
        };
    }

    #[Test]
    public function a_run_that_generates_nothing_leaves_existing_paths_intact(): void
    {
        Storage::fake('public');

        $file = $this->seededFile();

        $this->serviceThatCannotGenerate()->processImage($file, $this->realSourcePath());

        $this->assertSame(
            'shoots/74/thumbnails/listing-photo_thumbnail.jpg',
            $file->thumbnail_path,
            'A failed regeneration must not blank the existing thumbnail.'
        );
        $this->assertSame('shoots/74/webs/listing-photo_web.jpg', $file->web_path);
        $this->assertSame('shoots/74/placeholders/listing-photo_placeholder.jpg', $file->placeholder_path);
    }

    #[Test]
    public function a_run_that_generates_nothing_reports_failure(): void
    {
        Storage::fake('public');

        $result = $this->serviceThatCannotGenerate()->processImage($this->seededFile(), $this->realSourcePath());

        $this->assertFalse($result, 'Producing no renditions is not success.');
    }

    #[Test]
    public function a_run_that_generates_nothing_records_the_failure(): void
    {
        Storage::fake('public');

        $file = $this->seededFile();

        $this->serviceThatCannotGenerate()->processImage($file, $this->realSourcePath());

        $this->assertNotNull(
            $file->processing_failed_at,
            'A run that produced nothing must be recorded as failed so it is visible.'
        );
    }

    #[Test]
    public function a_partial_run_writes_what_it_generated_and_keeps_the_rest(): void
    {
        Storage::fake('public');

        $file = $this->seededFile();

        $result = $this->serviceThatOnlyGeneratesGrid()->processImage($file, $this->realSourcePath());

        $this->assertTrue($result, 'Generating at least one rendition is success.');
        $this->assertSame('shoots/74/grids/listing-photo_grid.jpg', $file->grid_path, 'The new grid must be stored.');
        $this->assertSame(
            'shoots/74/thumbnails/listing-photo_thumbnail.jpg',
            $file->thumbnail_path,
            'Sizes that were not regenerated must keep their existing paths.'
        );
        $this->assertSame('shoots/74/webs/listing-photo_web.jpg', $file->web_path);
    }

    #[Test]
    public function no_update_payload_ever_carries_a_null_rendition_path(): void
    {
        Storage::fake('public');

        $file = $this->seededFile();

        $this->serviceThatCannotGenerate()->processImage($file, $this->realSourcePath());

        $this->assertNotEmpty($file->updates, 'The failure must still be persisted.');

        $renditionColumns = ['thumbnail_path', 'grid_path', 'web_path', 'placeholder_path'];

        foreach ($file->updates as $payload) {
            foreach ($renditionColumns as $column) {
                // array_key_exists, not ??: null coalescing would treat a written
                // null as absent and the assertion could never fail.
                $this->assertFalse(
                    array_key_exists($column, $payload) && $payload[$column] === null,
                    "processImage() must never write a null {$column}; omit the column instead."
                );
            }
        }

        $this->assertSame(
            ['processing_failed_at', 'processing_error'],
            array_keys($file->updates[0]),
            'A run that generated nothing should record only the failure, touching no rendition column.'
        );
    }
}
