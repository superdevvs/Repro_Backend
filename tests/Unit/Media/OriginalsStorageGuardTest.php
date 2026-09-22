<?php

namespace Tests\Unit\Media;

use App\Services\Media\MediaStorage;
use App\Services\Media\OriginalsStorageGuard;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class OriginalsStorageGuardTest extends TestCase
{
    private FixtureOriginalsGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'media.originals_disk' => 'media_originals',
            'media.originals_mount' => '/mnt/16tb',
            'media.originals_uuid' => '1234-5678',
            'filesystems.disks.media_originals.root' => '/mnt/16tb/repro/media-originals',
        ]);
        $this->guard = new FixtureOriginalsGuard();
    }

    public function test_matching_mount_and_uuid_are_accepted(): void
    {
        $this->guard->assertAvailable();
        $this->assertTrue($this->guard->isAvailable());
    }

    public function test_existing_empty_mountpoint_does_not_count_as_mounted(): void
    {
        $this->guard->mountInfo = '1 0 0:1 / / rw - ext4 /dev/nvme0n1p2 rw';
        $this->assertFalse($this->guard->isAvailable());
        $this->expectException(RuntimeException::class);
        $this->guard->assertAvailable();
    }

    public function test_wrong_device_and_missing_uuid_fail_closed(): void
    {
        $this->guard->paths['/dev/disk/by-uuid/1234-5678'] = '/dev/sdb';
        $this->assertFalse($this->guard->isAvailable());
        $this->guard->paths['/dev/disk/by-uuid/1234-5678'] = false;
        $this->assertFalse($this->guard->isAvailable());
        config()->set('media.originals_uuid', '');
        $this->assertFalse($this->guard->isAvailable());
    }

    public function test_read_only_mount_allows_reads_but_refuses_writes(): void
    {
        $this->guard->mountInfo = '42 1 8:0 / /mnt/16tb ro,relatime - ext4 /dev/sda ro';
        $this->assertTrue($this->guard->isAvailable());
        $this->expectException(RuntimeException::class);
        $this->guard->assertAvailable();
    }

    public function test_symlink_redirect_to_os_filesystem_is_rejected(): void
    {
        $this->guard->paths['/mnt/16tb/repro/media-originals'] = '/var/tmp/media-originals';
        $this->assertFalse($this->guard->isAvailable());
    }

    public function test_mount_path_boundary_prevents_sibling_root(): void
    {
        config()->set('filesystems.disks.media_originals.root', '/mnt/16tb-other/media');
        $this->assertFalse($this->guard->isAvailable());
    }

    public function test_nested_mount_on_another_filesystem_is_rejected(): void
    {
        $this->guard->rootDevice = 2;
        $this->assertFalse($this->guard->isAvailable());
    }

    public function test_unwritable_root_refuses_write(): void
    {
        $this->guard->writable = false;
        $this->assertTrue($this->guard->isAvailable());
        $this->expectException(RuntimeException::class);
        $this->guard->assertAvailable();
    }

    public function test_guard_runs_before_adapter_can_create_a_missing_storage_root(): void
    {
        $root = sys_get_temp_dir().'/repro-missing-drive-'.bin2hex(random_bytes(8));
        config()->set([
            'media.tiered_storage_enabled' => true,
            'filesystems.disks.media_originals.root' => $root.'/media',
            'media.originals_mount' => $root,
        ]);
        Storage::forgetDisk('media_originals');
        try {
            (new MediaStorage())->put('shoots/1/todo/a.jpg', 'must-not-fall-through');
            $this->fail('The missing mount must reject originals writes.');
        } catch (RuntimeException) {
            $this->assertDirectoryDoesNotExist($root);
        }
    }
}

class FixtureOriginalsGuard extends OriginalsStorageGuard
{
    public string $mountInfo = '42 1 8:0 / /mnt/16tb rw,relatime - ext4 /dev/sda rw';
    public bool $writable = true;
    public int $rootDevice = 1;
    public array $paths = [
        '/mnt/16tb' => '/mnt/16tb',
        '/mnt/16tb/repro/media-originals' => '/mnt/16tb/repro/media-originals',
        '/dev/disk/by-uuid/1234-5678' => '/dev/sda',
        '/dev/sda' => '/dev/sda',
    ];

    protected function readMountInfo(): string
    {
        return $this->mountInfo;
    }

    protected function resolvePath(string $path): string|false
    {
        return $this->paths[$path] ?? false;
    }

    protected function rootIsWritable(string $root): bool
    {
        return $this->writable;
    }

    protected function filesystemDevice(string $path): ?int
    {
        return $path === '/mnt/16tb' ? 1 : $this->rootDevice;
    }
}
