<?php

namespace Tests\Unit;

use App\Services\Media\OriginalsStorageGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MediaStorageHealthTest extends TestCase
{
    private array $roots = [];

    protected function setUp(): void
    {
        parent::setUp();
        $root = storage_path('framework/testing/health-'.bin2hex(random_bytes(8)));
        $this->roots[] = $root;
        File::makeDirectory($root, 0770, true);
        config()->set('filesystems.disks.health_local', ['driver' => 'local', 'root' => $root, 'throw' => true]);
        config()->set('media.local_disk', 'health_local');
        config()->set('media.tiered_storage_enabled', false);
        config()->set('media.r2_only', false);
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('health_local');
        Storage::forgetDisk('health_originals');
        foreach ($this->roots as $root) {
            File::deleteDirectory($root);
        }
        parent::tearDown();
    }

    public function test_disabled_tiering_can_probe_local_media_without_constructing_an_hdd_disk(): void
    {
        config()->set('filesystems.disks.media_originals.root', '/unavailable-originals-root');
        $this->artisan('media:storage-health', ['--probe' => true])
            ->expectsOutputToContain('"healthy":true')
            ->assertSuccessful();
        $this->assertSame([], Storage::disk('health_local')->allFiles());
    }

    public function test_missing_drive_fails_health_and_leaves_existing_nvme_media_untouched(): void
    {
        config()->set('media.tiered_storage_enabled', true);
        Storage::disk('health_local')->put('shoots/1/todo/original.jpg', 'keep');
        $guard = Mockery::mock(OriginalsStorageGuard::class);
        $guard->shouldReceive('assertAvailable')->once()->andThrow(new RuntimeException('Drive unavailable'));
        $this->app->instance(OriginalsStorageGuard::class, $guard);
        $this->artisan('media:storage-health', ['--probe' => true])
            ->expectsOutputToContain('"healthy":false')
            ->assertFailed();
        $this->assertSame(['shoots/1/todo/original.jpg'], Storage::disk('health_local')->allFiles());
    }

    public function test_enabled_tiering_probes_both_real_filesystems_and_removes_test_files(): void
    {
        if (! is_dir('/dev/shm') || ! is_writable('/dev/shm')) {
            $this->markTestSkipped('Requires Linux shared memory for a separate fixture filesystem.');
        }
        $originalRoot = '/dev/shm/repro-health-'.bin2hex(random_bytes(8));
        $this->roots[] = $originalRoot;
        File::makeDirectory($originalRoot, 0770);
        config()->set('filesystems.disks.health_originals', ['driver' => 'local', 'root' => $originalRoot, 'throw' => true]);
        config()->set('media.originals_disk', 'health_originals');
        config()->set('media.tiered_storage_enabled', true);
        $guard = Mockery::mock(OriginalsStorageGuard::class);
        $guard->shouldReceive('assertAvailable')->andReturnNull();
        $guard->shouldReceive('isAvailable')->andReturnTrue();
        $this->app->instance(OriginalsStorageGuard::class, $guard);
        $this->artisan('media:storage-health', ['--probe' => true])
            ->expectsOutputToContain('"preview":"passed","original":"passed"')
            ->assertSuccessful();
        $this->assertSame([], Storage::disk('health_local')->allFiles());
        $this->assertSame([], Storage::disk('health_originals')->allFiles());
    }
}
