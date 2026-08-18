<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: images:process-existing selected on media_type IN ('image','raw'),
 * but no row in production carries media_type = 'image'. The real values are
 * edited (238), video, floorplan, drone, extra, green_grass, twilight,
 * virtual_staging and raw. The command therefore only ever matched the handful
 * of raw files and could never backfill the client-facing photos it exists to
 * fix — 309 files were left without the `grid` rendition, so the media grid
 * kept upscaling the 300px thumbnail and looked blurred.
 *
 * Videos must stay excluded: image renditions are meaningless for them.
 */
class ProcessExistingImagesFilterTest extends TestCase
{
    use RefreshDatabase;

    private Shoot $shoot;
    private User $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploader = User::factory()->create();
        $this->shoot = Shoot::factory()->create();
    }

    private function file(string $mediaType, ?string $gridPath = null): ShootFile
    {
        $shootId = $this->shoot->id;

        return ShootFile::create([
            'shoot_id' => $shootId,
            'filename' => "asset-{$mediaType}.jpg",
            'stored_filename' => "stored-asset-{$mediaType}.jpg",
            'path' => "shoots/{$shootId}/completed/asset-{$mediaType}.jpg",
            'file_type' => 'jpg',
            'file_size' => 1024,
            'uploaded_by' => $this->uploader->id,
            'media_type' => $mediaType,
            'thumbnail_path' => "shoots/{$shootId}/thumbnails/asset-{$mediaType}.jpg",
            'web_path' => "shoots/{$shootId}/webs/asset-{$mediaType}.jpg",
            'grid_path' => $gridPath,
            'processed_at' => now(),
        ]);
    }

    /**
     * IDs of the files the command queued.
     *
     * Uses Queue::pushed rather than Queue::assertPushed: the latter asserts,
     * which would fail the cases where nothing is expected to be dispatched.
     * ProcessImageJob::$shootFile is protected, hence the reflection.
     */
    private function dispatchedIds(): array
    {
        $property = new \ReflectionProperty(ProcessImageJob::class, 'shootFile');
        $property->setAccessible(true);

        return Queue::pushed(ProcessImageJob::class)
            ->map(fn (ProcessImageJob $job): int => $property->getValue($job)->id)
            ->all();
    }

    #[Test]
    public function an_edited_photo_missing_its_grid_rendition_is_selected(): void
    {
        Queue::fake();

        $edited = $this->file('edited');

        $this->artisan('images:process-existing', ['--limit' => 50])->assertExitCode(0);

        $this->assertContains(
            $edited->id,
            $this->dispatchedIds(),
            "media_type 'edited' is the client-facing photo type and must be backfilled."
        );
    }

    #[Test]
    public function other_real_image_media_types_are_selected(): void
    {
        Queue::fake();

        $ids = collect(['drone', 'twilight', 'virtual_staging', 'green_grass'])
            ->map(fn (string $type) => $this->file($type)->id)
            ->all();

        $this->artisan('images:process-existing', ['--limit' => 50])->assertExitCode(0);

        $dispatched = $this->dispatchedIds();

        foreach ($ids as $id) {
            $this->assertContains($id, $dispatched, 'All photographic media types must be backfilled.');
        }
    }

    #[Test]
    public function videos_are_never_selected_for_image_processing(): void
    {
        Queue::fake();

        $video = $this->file('video');

        $this->artisan('images:process-existing', ['--limit' => 50])->assertExitCode(0);

        $this->assertNotContains(
            $video->id,
            $this->dispatchedIds(),
            'Image renditions are meaningless for a video row.'
        );
    }

    #[Test]
    public function a_file_that_already_has_every_rendition_is_left_alone(): void
    {
        Queue::fake();

        $complete = $this->file('edited', 'shoots/1/grids/asset-edited.jpg');

        $this->artisan('images:process-existing', ['--limit' => 50])->assertExitCode(0);

        $this->assertNotContains(
            $complete->id,
            $this->dispatchedIds(),
            'Fully processed files must not be reprocessed.'
        );
    }
}
