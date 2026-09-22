<?php

namespace Tests\Unit\Media;

use App\Services\Media\MediaStorage;
use App\Services\Media\OriginalsStorageGuard;
use App\Services\Media\OriginalsStoragePolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MediaTieredStorageTest extends TestCase
{
    private TestOriginalsGuard $guard;
    private MediaStorage $media;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['local', 'public', 'media'] as $name) {
            Storage::fake($name);
        }
        Storage::fake('media_originals', config('filesystems.disks.media_originals'));
        config()->set([
            'media.local_disk' => 'local',
            'media.legacy_public_disk' => 'public',
            'media.tiered_storage_enabled' => true,
            'media.remote_disk' => 'media',
            'media.dual_write' => false,
            'media.read_from_r2' => false,
            'media.r2_only' => false,
        ]);
        $this->guard = new TestOriginalsGuard();
        $this->media = new MediaStorage($this->guard);
    }

    public function test_only_known_originals_and_master_archives_route_to_hdd(): void
    {
        foreach (OriginalsStoragePolicy::ORIGINAL_DIRECTORIES as $directory) {
            $this->assertSame('media_originals', $this->media->localDiskName("shoots/12/{$directory}/master.jpg"));
        }
        foreach (['shoots/12/archives/photos.zip', 'share-links/12/master.zip', '/storage/shoots/12/todo/raw.nef'] as $key) {
            $this->assertSame('media_originals', $this->media->localDiskName($key), $key);
        }
        foreach ([
            'shoots/12/thumbnails/a.jpg', 'shoots/12/grids/a.jpg', 'shoots/12/webs/a.jpg',
            'shoots/12/placeholders/a.jpg', 'shoots/12/watermarked/a.jpg',
            'shoots/12/watermarked/webs/a.jpg', 'shoots/12/floorplans/previews/a.jpg',
            'shoots/12/final/thumbnails/a.jpg', 'shoots/12/archives/photos.json',
            'shoots/12/unknown/a.jpg', 'shoots/12/archives/nested/a.zip', 'share-links/12/preview.jpg',
            'studio/hdr/work.jpg', 'temp/a.nef', 'database.sqlite', 'branding/logo.jpg',
            'shoots/12/todo/../webs/a.jpg',
        ] as $key) {
            $this->assertSame('local', $this->media->localDiskName($key), $key);
        }
        $this->assertSame('local', $this->media->localDiskName());
        $this->assertTrue($this->media->usesPrivateLocalDisk());
    }

    public function test_disabled_tiering_preserves_local_writes_without_checking_drive(): void
    {
        config()->set('media.tiered_storage_enabled', false);
        $this->guard->available = false;
        $this->assertTrue($this->media->put('shoots/1/todo/a.jpg', 'original'));
        Storage::disk('local')->assertExists('shoots/1/todo/a.jpg');
        Storage::disk('media_originals')->assertMissing('shoots/1/todo/a.jpg');
        $this->assertSame(0, $this->guard->writeChecks);
    }

    public function test_streamed_upload_and_derivative_have_distinct_disks(): void
    {
        $key = 'shoots/1/todo/raw.nef';
        $upload = UploadedFile::fake()->createWithContent('raw.nef', 'raw-original-bytes');
        $this->assertSame($key, $upload->storeAs(dirname($key), basename($key), $this->media->writeDiskName($key)));
        $this->assertTrue($this->media->put('shoots/1/thumbnails/raw.jpg', 'small-preview'));
        $this->assertSame('raw-original-bytes', Storage::disk('media_originals')->get($key));
        Storage::disk('local')->assertMissing($key);
        Storage::disk('local')->assertExists('shoots/1/thumbnails/raw.jpg');
        Storage::disk('media_originals')->assertMissing('shoots/1/thumbnails/raw.jpg');
    }

    public function test_atomic_stream_put_is_readable_and_mirrored_to_r2(): void
    {
        config()->set('media.dual_write', true);
        $key = 'shoots/2/archives/delivery.zip';
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, str_repeat('zip-bytes-', 1024));
        rewind($stream);
        try {
            $this->assertTrue($this->media->put($key, $stream));
        } finally {
            fclose($stream);
        }
        $expected = str_repeat('zip-bytes-', 1024);
        $this->assertSame($expected, $this->media->get($key));
        $this->assertSame($expected, Storage::disk('media')->get($key));
        $this->assertTrue($this->media->exists($key));
        $this->assertSame(strlen($expected), $this->media->localSize($key));
        $this->assertSame(Storage::disk('media_originals'), $this->media->diskFor($key));
        $this->assertSame(Storage::disk('media_originals')->path($key), $this->media->absolutePath($key));
        $this->assertSame([$key], Storage::disk('media_originals')->allFiles());
        $this->assertSame('skipped', $this->media->mirrorToR2($key));
    }

    public function test_read_falls_back_to_old_nvme_then_public_during_copy(): void
    {
        $key = 'shoots/3/final/a.jpg';
        Storage::disk('local')->put($key, 'private-copy');
        Storage::disk('public')->put($key, 'legacy-copy');
        $this->assertSame('private-copy', $this->media->get($key));
        $this->assertSame(Storage::disk('local')->path($key), $this->media->absolutePath($key));
        $this->assertSame('copied', $this->media->mirrorToR2($key));
        $this->assertSame('private-copy', Storage::disk('media')->get($key));
        Storage::disk('local')->delete($key);
        $this->assertSame('legacy-copy', $this->media->get($key));
        $this->assertSame(Storage::disk('public'), $this->media->diskFor($key));
        $this->assertTrue($this->media->put($key, 'new-hdd-copy'));
        $this->assertSame('new-hdd-copy', $this->media->get($key));
    }

    public function test_delete_removes_hdd_and_all_retained_copies(): void
    {
        $key = 'shoots/4/completed/a.jpg';
        foreach (['local', 'public', 'media_originals'] as $disk) {
            Storage::disk($disk)->put($key, $disk);
        }
        $this->assertTrue($this->media->delete($key));
        foreach (['local', 'public', 'media_originals'] as $disk) {
            Storage::disk($disk)->assertMissing($key);
        }
        $this->assertFalse($this->media->exists($key));
    }

    public function test_missing_drive_keeps_fallback_readable_but_rejects_write_and_delete(): void
    {
        $key = 'shoots/5/todo/a.jpg';
        Storage::disk('local')->put($key, 'retained');
        $this->guard->available = false;
        $this->assertSame('retained', $this->media->get($key));
        $this->assertSame(['local', 'public'], array_keys($this->media->localReadDisks($key)));
        foreach (['put', 'delete'] as $operation) {
            try {
                $operation === 'put' ? $this->media->put($key, 'replacement') : $this->media->delete($key);
                $this->fail('Unavailable originals drive must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Test drive unavailable', $exception->getMessage());
            }
        }
        $this->assertSame('retained', Storage::disk('local')->get($key));
        Storage::disk('media_originals')->assertMissing($key);
        $this->assertTrue($this->media->put('shoots/5/webs/a.jpg', 'preview'));
    }

    public function test_r2_only_does_not_require_originals_drive(): void
    {
        config()->set('media.r2_only', true);
        $this->guard->available = false;
        $key = 'shoots/6/todo/a.jpg';
        $this->assertTrue($this->media->put($key, 'r2-original'));
        $this->assertSame('r2-original', $this->media->get($key));
        $this->assertSame(Storage::disk('media'), $this->media->diskFor($key));
        $this->assertTrue($this->media->delete($key));
        $this->assertSame(0, $this->guard->writeChecks);
        Storage::disk('local')->assertMissing($key);
        Storage::disk('media_originals')->assertMissing($key);
    }

    public function test_remote_reads_still_take_precedence(): void
    {
        config()->set('media.read_from_r2', true);
        $key = 'shoots/7/todo/a.jpg';
        $this->media->put($key, 'hdd-original');
        Storage::disk('media')->put($key, 'remote-original');
        $this->assertSame('remote-original', $this->media->get($key));
        $this->assertSame(Storage::disk('media'), $this->media->diskFor($key));
    }

    public function test_backfill_scans_all_tiers_and_deduplicates_retained_keys(): void
    {
        $this->app->instance(MediaStorage::class, $this->media);
        Storage::disk('media_originals')->put('shoots/9/todo/a.jpg', 'hdd-original');
        Storage::disk('local')->put('shoots/9/todo/a.jpg', 'retained-original');
        Storage::disk('local')->put('shoots/9/thumbnails/a.jpg', 'small');
        Storage::disk('public')->put('shoots/9/final/legacy.jpg', 'legacy');
        Storage::disk('media_originals')->put('shoots/9/todo/.pending.jpg.upload-'.str_repeat('a', 24), 'incomplete');

        $this->artisan('media:backfill-r2', ['--shoot' => '9'])
            ->expectsOutputToContain('Done. copied=3 skipped=0 failed=0 missing=0')
            ->assertSuccessful();
        $this->assertSame('hdd-original', Storage::disk('media')->get('shoots/9/todo/a.jpg'));
        $this->assertCount(3, Storage::disk('media')->allFiles());
    }
}

class TestOriginalsGuard extends OriginalsStorageGuard
{
    public bool $available = true;
    public int $writeChecks = 0;

    public function assertAvailable(bool $forWrite = true): void
    {
        $this->writeChecks += $forWrite ? 1 : 0;
        if (! $this->available) {
            throw new RuntimeException('Test drive unavailable');
        }
    }
}
