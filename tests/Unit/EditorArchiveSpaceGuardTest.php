<?php

namespace Tests\Unit;

use App\Models\ShootFile;
use App\Services\Media\ArchiveFilesystemSpace;
use App\Services\Media\EditorArchiveSpaceGuard;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class EditorArchiveSpaceGuardTest extends TestCase
{
    private string $key;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['media.local_disk' => 'local', 'media.r2_only' => false, 'media.dual_write' => false,
            'media.editor_archive_reserve_bytes' => 100]);
        $this->key = 'editor-downloads/42/'.str_repeat('a', 64).'.zip';
        $this->source = tempnam(sys_get_temp_dir(), 'capacity-test-');
        file_put_contents($this->source, str_repeat('x', 1000));
    }

    protected function tearDown(): void
    {
        @unlink($this->source);
        parent::tearDown();
    }

    private function probe(int $tempFree, int $destinationFree, bool $separate = false): ArchiveFilesystemSpace
    {
        $probe = Mockery::mock(ArchiveFilesystemSpace::class);
        $probe->shouldReceive('snapshot')->andReturnUsing(fn ($path) => $path === sys_get_temp_dir()
            ? ['device' => '1', 'free' => $tempFree, 'path' => '/temp']
            : ['device' => $separate ? '2' : '1', 'free' => $destinationFree, 'path' => '/destination']);

        return $probe;
    }

    private function guard(ArchiveFilesystemSpace $probe, ?MediaStorage $media = null, bool $local = true): EditorArchiveSpaceGuard
    {
        $access = Mockery::mock(ShootFileAccessService::class);
        $access->shouldReceive('findLocalFilePath')->andReturn($local ? $this->source : null);

        return new EditorArchiveSpaceGuard($media ?? app(MediaStorage::class), $access, $probe);
    }

    private function files(): \Illuminate\Support\Collection
    {
        // Stale DB metadata must never undercount the real source's 1000 bytes.
        return collect([new ShootFile(['file_size' => 1, 'path' => 'shoots/42/raw/source.cr3'])]);
    }

    public function test_same_device_counts_two_workspace_buffers_copy_and_reserve_once(): void
    {
        $zipBound = 1050 + 65536 + 1048576;
        $needed = 3 * $zipBound + 100;
        $guard = $this->guard($this->probe($needed, $needed));
        $guard->assertCanGenerate($this->files(), $this->key);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->expectExceptionMessage('preparation deferred');
        $this->guard($this->probe($needed - 1, $needed - 1))->assertCanGenerate($this->files(), $this->key);
    }

    public function test_separate_destination_device_needs_its_own_reserve(): void
    {
        $zipBound = 1050 + 65536 + 1048576;
        $this->guard($this->probe(2 * $zipBound + 100, $zipBound + 100, true))->assertCanGenerate($this->files(), $this->key);
        $this->expectExceptionMessage('on /destination');
        $this->guard($this->probe(PHP_INT_MAX, $zipBound + 99, true))->assertCanGenerate($this->files(), $this->key);
    }

    public function test_remote_sources_are_measured_and_all_temporary_copies_budgeted(): void
    {
        $media = Mockery::mock(MediaStorage::class)->makePartial();
        $media->shouldReceive('r2Only')->andReturnTrue();
        $media->shouldReceive('readFromR2Enabled')->andReturnTrue();
        $media->shouldReceive('remoteSize')->with('shoots/42/raw/source.cr3')->andReturn(1000);
        $media->shouldNotReceive('localDisk');
        $zipBound = 1050 + 65536 + 1048576;
        $needed = 2 * $zipBound + 1000 + 100;
        $this->guard($this->probe($needed, 0), $media, false)->assertCanGenerate($this->files(), $this->key);
        $this->expectExceptionMessage('preparation deferred');
        $this->guard($this->probe($needed - 1, 0), $media, false)->assertCanGenerate($this->files(), $this->key);
    }

    public function test_missing_source_size_fails_before_generation_without_trusting_database_size(): void
    {
        $media = Mockery::mock(MediaStorage::class)->makePartial();
        $media->shouldReceive('readFromR2Enabled')->andReturnTrue();
        $media->shouldReceive('remoteSize')->andReturnNull();
        $probe = Mockery::mock(ArchiveFilesystemSpace::class);
        $probe->shouldNotReceive('snapshot');
        $this->expectExceptionMessage('no ZIP was started');
        $this->guard($probe, $media, false)->assertCanGenerate($this->files(), $this->key);
    }

    public function test_publish_checks_actual_copy_size_and_preserves_complete_source_on_failure(): void
    {
        $guard = $this->guard($this->probe(0, 1100));
        $guard->assertCanPublish($this->key, $this->source);
        try {
            $this->guard($this->probe(0, 1099))->assertCanPublish($this->key, $this->source);
            $this->fail('Expected insufficient space');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Retry after storage is freed', $exception->getMessage());
        }
        $this->assertSame(1000, filesize($this->source));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_unconfigured_default_reserves_twenty_gib_without_environment_bypass(): void
    {
        $mediaConfig = config('media');
        unset($mediaConfig['editor_archive_reserve_bytes']);
        config()->set('media', $mediaConfig);
        $this->expectExceptionMessage('20.00 GiB reserve');
        $this->guard($this->probe(0, 20 * 1024 ** 3))->assertCanPublish($this->key, $this->source);
    }

    public function test_guard_rejects_other_archive_families(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guard($this->probe(PHP_INT_MAX, PHP_INT_MAX))->assertCanGenerate($this->files(), 'media-archives/42/raw.zip');
    }

    public function test_real_probe_reads_nearest_existing_parent_without_creating_destination(): void
    {
        $missing = Storage::disk('local')->path('not-created/nested');
        $probe = (new ArchiveFilesystemSpace)->snapshot($missing);
        $this->assertSame((string) stat(Storage::disk('local')->path(''))['dev'], $probe['device']);
        $this->assertGreaterThanOrEqual(0, $probe['free']);
        $this->assertDirectoryDoesNotExist($missing);
    }
}
