<?php

namespace Tests\Feature;

use App\Services\ListingStudio\ListingStudioPaidStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ListingStudioPaidStorageTest extends TestCase
{
    private ?string $root = null;

    private const TRIAL = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';

    private const WORKSPACE = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';

    protected function tearDown(): void
    {
        if ($this->root) {
            Storage::disk(ListingStudioPaidStorage::DISK)->deleteDirectory('');
        }
        parent::tearDown();
    }

    private function fixture(): void
    {
        $this->root = sys_get_temp_dir().'/listing-studio-paid-permissions-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
        chmod($this->root, 02770);
        Storage::set(ListingStudioPaidStorage::DISK, Storage::build(array_replace(
            config('filesystems.disks.listing_studio_orders'), ['root' => $this->root]
        )));
    }

    public function test_all_trial_and_worker_directories_restore_group_write_after_restrictive_umask(): void
    {
        $this->fixture();
        $previous = umask(0077);
        try {
            $storage = app(ListingStudioPaidStorage::class);
            $storage->prepareUploadDirectory(self::TRIAL);
            $storage->prepareWorkspaceDirectory(self::WORKSPACE, true);
            $storage->prepareItemDirectory(self::TRIAL);
            $storage->prepareDeliveryDirectory(self::TRIAL);
            $disk = Storage::disk(ListingStudioPaidStorage::DISK);
            $disk->put('uploads/'.self::TRIAL.'/source.jpg', 'source fixture');
            foreach (['items', 'items/'.self::TRIAL, 'revision-deliveries', 'revision-deliveries/'.self::TRIAL, 'uploads', 'uploads/'.self::TRIAL, 'studio', 'studio/workspaces', 'studio/workspaces/'.self::WORKSPACE,
                'studio/workspaces/'.self::WORKSPACE.'/provider-results'] as $directory) {
                clearstatcache(true, $disk->path($directory));
                $this->assertSame(02770, fileperms($disk->path($directory)) & 07777);
                $this->assertSame(filegroup($this->root), filegroup($disk->path($directory)));
            }
            $this->assertSame(0660, fileperms($disk->path('uploads/'.self::TRIAL.'/source.jpg')) & 07777);
            $storage->prepareUploadDirectory(self::TRIAL);
            $this->assertSame('source fixture', $disk->get('uploads/'.self::TRIAL.'/source.jpg'));
        } finally {
            umask($previous);
        }
    }

    public function test_existing_unsafe_directory_is_rejected_without_changing_its_permissions(): void
    {
        $this->fixture();
        mkdir($this->root.'/uploads', 02770);
        chmod($this->root.'/uploads', 02770);
        mkdir($this->root.'/uploads/'.self::TRIAL, 02750);
        chmod($this->root.'/uploads/'.self::TRIAL, 02750);
        try {
            app(ListingStudioPaidStorage::class)->prepareUploadDirectory(self::TRIAL);
            $this->fail('An existing FPM-owned unsafe directory needs targeted operator review.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('permissions need operator review', $error->getMessage());
            $this->assertSame(02750, fileperms($this->root.'/uploads/'.self::TRIAL) & 07777);
        }
    }

    public function test_symlink_escape_and_unprovisioned_root_are_rejected(): void
    {
        $this->fixture();
        symlink(sys_get_temp_dir(), $this->root.'/uploads');
        try {
            app(ListingStudioPaidStorage::class)->prepareUploadDirectory(self::TRIAL);
            $this->fail('Symlink namespace must not be followed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('outside its storage root', $error->getMessage());
        } finally {
            unlink($this->root.'/uploads');
        }
        chmod($this->root, 0700);
        $this->expectException(\RuntimeException::class);
        app(ListingStudioPaidStorage::class)->prepareUploadDirectory(self::TRIAL);
    }

    public function test_fpm_upload_and_separate_worker_can_share_private_files(): void
    {
        $this->fixture();
        $canSwitchUsers = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $group = $canSwitchUsers ? 33 : filegroup($this->root);
        $fpm = $canSwitchUsers ? 33 : fileowner($this->root);
        $worker = $canSwitchUsers ? 65534 : $fpm;
        chgrp($this->root, $group);
        $script = <<<'PHP'
require $argv[1];
$config = json_decode($argv[3], true);
$container = new Illuminate\Container\Container;
$container->instance('config', new Illuminate\Config\Repository(['filesystems' => ['default' => 'listing_studio_orders', 'disks' => ['listing_studio_orders' => $config]]]));
$container->instance('filesystem', new Illuminate\Filesystem\FilesystemManager($container));
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    if (!posix_setgid((int) $argv[5]) || !posix_setuid((int) $argv[4])) { exit(2); }
}
umask(0022);
$storage = new App\Services\ListingStudio\ListingStudioPaidStorage;
$disk = Illuminate\Support\Facades\Storage::disk('listing_studio_orders');
$trial = 'uploads/aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
if ($argv[2] === 'upload') {
    $storage->prepareUploadDirectory('aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
    $disk->put($trial.'/source.jpg', 'private source');
    $disk->put($trial.'/original-preview.jpg', 'private original preview');
} elseif ($argv[2] === 'worker') {
    if ($disk->get($trial.'/source.jpg') !== 'private source') { exit(3); }
    $storage->prepareUploadDirectory('aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
    $disk->put($trial.'/preview.jpg', 'private edited preview');
    $storage->prepareWorkspaceDirectory('bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb', true);
    $disk->put('studio/workspaces/bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb/edited.jpg', 'private edited output');
} else {
    if ($disk->get($trial.'/preview.jpg') !== 'private edited preview' || $disk->get('studio/workspaces/bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb/edited.jpg') !== 'private edited output') { exit(4); }
}
echo 'ok';
PHP;
        $config = array_replace(config('filesystems.disks.listing_studio_orders'), ['root' => $this->root]);
        foreach ([['upload', $fpm], ['worker', $worker], ['read', $fpm]] as [$step, $user]) {
            $process = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $step, json_encode($config), (string) $user, (string) $group]);
            $process->mustRun();
            $this->assertSame('ok', $process->getOutput());
        }
        clearstatcache();
        $this->assertSame($fpm, fileowner($this->root.'/uploads/'.self::TRIAL));
        $this->assertSame($worker, fileowner($this->root.'/uploads/'.self::TRIAL.'/preview.jpg'));
        $this->assertSame(02770, fileperms($this->root.'/uploads/'.self::TRIAL) & 07777);
        $this->assertSame(0660, fileperms($this->root.'/uploads/'.self::TRIAL.'/preview.jpg') & 07777);
    }
}
