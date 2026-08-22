<?php

namespace Tests\Feature\Storage;

use App\Jobs\SyncShootFileToR2Job;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DualWriteToR2Test extends TestCase
{
    use RefreshDatabase;

    private function makeShootFileWithLocalAssets(): ShootFile
    {
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
        ]);

        $original = "shoots/{$shoot->id}/todo/orig.jpg";
        $thumb = "shoots/{$shoot->id}/thumbnails/orig.jpg";
        $grid = "shoots/{$shoot->id}/grids/orig.jpg";
        $web = "shoots/{$shoot->id}/web/orig.jpg";

        Storage::disk('public')->put($original, 'ORIGINAL-BYTES');
        Storage::disk('public')->put($thumb, 'THUMB');
        Storage::disk('public')->put($grid, 'GRID-600PX');
        Storage::disk('public')->put($web, 'WEBBYTES');

        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'orig.jpg',
            'stored_filename' => 'orig.jpg',
            'path' => $original,
            'storage_path' => $original,
            'thumbnail_path' => $thumb,
            'grid_path' => $grid,
            'web_path' => $web,
            'file_type' => 'image/jpeg',
            'file_size' => strlen('ORIGINAL-BYTES'),
            'media_type' => 'raw',
            'uploaded_by' => $photographer->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]);
    }

    public function test_dual_write_mirrors_all_keys_to_r2_with_identical_keys_and_sizes(): void
    {
        Storage::fake('public');
        Storage::fake('media');
        config()->set('media.dual_write', true);

        $file = $this->makeShootFileWithLocalAssets();

        (new SyncShootFileToR2Job($file->id))->handle(app(MediaStorage::class));

        foreach ([$file->path, $file->thumbnail_path, $file->grid_path, $file->web_path] as $key) {
            Storage::disk('media')->assertExists($key);
            $this->assertSame(
                Storage::disk('public')->get($key),
                Storage::disk('media')->get($key),
                "Mismatch for {$key}"
            );
            $this->assertSame(
                Storage::disk('public')->size($key),
                Storage::disk('media')->size($key),
                "Size mismatch for {$key}"
            );
        }
    }

    /**
     * The 600px grid rendition must be in the mirror set.
     *
     * Every card and tile in the product resolves `grid_url`, and once reads are
     * flipped to R2 that URL is built against the CDN. A rendition the mirror
     * never copies is therefore a 404 on every grid surface — and it fails
     * silently, because the local file still exists and looks fine in dev.
     * `SyncShootFileToR2Job::KEY_ATTRIBUTES` originally omitted `grid_path`.
     */
    public function test_grid_rendition_is_mirrored_to_r2(): void
    {
        Storage::fake('public');
        Storage::fake('media');
        config()->set('media.dual_write', true);

        $file = $this->makeShootFileWithLocalAssets();

        $this->assertContains(
            'grid_path',
            SyncShootFileToR2Job::KEY_ATTRIBUTES,
            'grid_path must be one of the mirrored media-bearing columns.'
        );

        (new SyncShootFileToR2Job($file->id))->handle(app(MediaStorage::class));

        Storage::disk('media')->assertExists($file->grid_path);
        $this->assertSame('GRID-600PX', Storage::disk('media')->get($file->grid_path));
    }

    public function test_job_is_noop_when_flags_off(): void
    {
        Storage::fake('public');
        Storage::fake('media');
        config()->set('media.dual_write', false);
        config()->set('media.r2_only', false);

        $file = $this->makeShootFileWithLocalAssets();

        (new SyncShootFileToR2Job($file->id))->handle(app(MediaStorage::class));

        Storage::disk('media')->assertMissing($file->path);
    }

    public function test_mirror_is_idempotent_and_skips_matching_objects(): void
    {
        Storage::fake('public');
        Storage::fake('media');
        config()->set('media.dual_write', true);

        $file = $this->makeShootFileWithLocalAssets();
        $media = app(MediaStorage::class);

        $this->assertSame('copied', $media->mirrorToR2($file->path));
        $this->assertSame('skipped', $media->mirrorToR2($file->path));
    }
}
