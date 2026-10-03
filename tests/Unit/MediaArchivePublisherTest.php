<?php

namespace Tests\Unit;

use App\Services\Media\MediaArchivePublisher;
use App\Services\Media\MediaStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MediaArchivePublisherTest extends TestCase
{
    public function test_local_publish_uses_sibling_temporary_file_and_preserves_old_archive_until_move(): void
    {
        Storage::fake('local');
        $real = Storage::disk('local');
        $key = 'editor-downloads/42/archive.zip';
        $real->put($key, 'old-complete');
        $source = tempnam(sys_get_temp_dir(), 'archive-test-');
        file_put_contents($source, 'new-complete');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->withArgs(function ($temporary, $stream) use ($real, $key) {
            $this->assertSame('old-complete', $real->get($key));
            $this->assertSame(dirname($key), dirname($temporary));
            $this->assertStringContainsString('.archive-', $temporary);
            return $real->put($temporary, $stream);
        })->andReturnTrue();
        $disk->shouldReceive('move')->once()->withArgs(function ($temporary, $destination) use ($real, $key) {
            $this->assertSame('old-complete', $real->get($key));
            $this->assertSame('new-complete', $real->get($temporary));
            return $real->move($temporary, $destination);
        })->andReturnTrue();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        $media = Mockery::mock(MediaStorage::class)->makePartial();
        $media->shouldReceive('r2Only')->andReturnFalse();
        $media->shouldReceive('dualWriteEnabled')->andReturnFalse();
        $media->shouldReceive('localDisk')->with($key)->andReturn($disk);
        $media->shouldReceive('writeDiskName')->with($key)->once()->andReturn('local');
        try {
            (new MediaArchivePublisher($media))->publish($key, $source);
            $this->assertSame('new-complete', $real->get($key));
        } finally {
            unlink($source);
        }
    }

    public function test_failed_copy_never_replaces_existing_archive(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'archive-test-');
        file_put_contents($source, 'source');
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldNotReceive('move');
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        $media = Mockery::mock(MediaStorage::class)->makePartial();
        $media->shouldReceive('r2Only')->andReturnFalse();
        $media->shouldReceive('localDisk')->andReturn($disk);
        try {
            $this->expectException(\RuntimeException::class);
            (new MediaArchivePublisher($media))->publish('editor-downloads/42/archive.zip', $source);
        } finally {
            unlink($source);
        }
    }
    public function test_local_publish_reuses_completed_bytes_and_survives_source_cleanup(): void
    {
        Storage::fake('local');
        config()->set('media.local_disk', 'local');
        config()->set('media.r2_only', false);
        config()->set('media.dual_write', false);
        $disk = Storage::disk('local');
        $source = $disk->path('completed-source.zip');
        $disk->put('completed-source.zip', 'complete archive bytes');
        $disk->put('media-archives/42/final.zip', 'old complete bytes');
        app(MediaArchivePublisher::class)->publish('media-archives/42/final.zip', $source);
        $this->assertSame('complete archive bytes', $disk->get('media-archives/42/final.zip'));
        $this->assertSame(fileinode($source), fileinode($disk->path('media-archives/42/final.zip')));
        $this->assertSame('private', $disk->getVisibility('media-archives/42/final.zip'));
        unlink($source);
        $this->assertSame('complete archive bytes', $disk->get('media-archives/42/final.zip'));
        $this->assertSame(['media-archives/42/final.zip'], $disk->allFiles());
    }

}
