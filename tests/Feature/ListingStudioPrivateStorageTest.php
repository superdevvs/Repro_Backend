<?php

namespace Tests\Feature;

use App\Services\ListingStudio\ListingStudioGuestTrialService;
use App\Services\ListingStudio\ListingStudioPrivateStorage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ListingStudioPrivateStorageTest extends TestCase
{
    private ?string $root = null;

    private const TRIAL = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';

    private const WORKSPACE = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';

    protected function tearDown(): void
    {
        if ($this->root) {
            Storage::disk(ListingStudioGuestTrialService::DISK)->deleteDirectory('');
        }
        parent::tearDown();
    }

    private function fixture(): void
    {
        $this->root = sys_get_temp_dir().'/listing-studio-permissions-'.bin2hex(random_bytes(12));
        mkdir($this->root, 0700);
        chmod($this->root, 02770);
        Storage::set(ListingStudioGuestTrialService::DISK, Storage::build(array_replace(
            config('filesystems.disks.listing_studio_trials'), ['root' => $this->root]
        )));
    }

    public function test_all_trial_and_worker_directories_restore_group_write_after_restrictive_umask(): void
    {
        $this->fixture();
        $previous = umask(0077);
        try {
            $storage = app(ListingStudioPrivateStorage::class);
            $storage->prepareTrialDirectory(self::TRIAL);
            $storage->prepareWorkspaceDirectory(self::WORKSPACE, true);
            $disk = Storage::disk(ListingStudioGuestTrialService::DISK);
            $disk->put('trials/'.self::TRIAL.'/source.jpg', 'source fixture');
            foreach (['trials', 'trials/'.self::TRIAL, 'studio', 'studio/workspaces', 'studio/workspaces/'.self::WORKSPACE,
                'studio/workspaces/'.self::WORKSPACE.'/provider-results'] as $directory) {
                clearstatcache(true, $disk->path($directory));
                $this->assertSame(02770, fileperms($disk->path($directory)) & 07777);
                $this->assertSame(filegroup($this->root), filegroup($disk->path($directory)));
            }
            $this->assertSame(0660, fileperms($disk->path('trials/'.self::TRIAL.'/source.jpg')) & 07777);
            $storage->prepareTrialDirectory(self::TRIAL);
            $this->assertSame('source fixture', $disk->get('trials/'.self::TRIAL.'/source.jpg'));
        } finally {
            umask($previous);
        }
    }

    public function test_existing_unsafe_directory_is_rejected_without_changing_its_permissions(): void
    {
        $this->fixture();
        mkdir($this->root.'/trials', 02770);
        chmod($this->root.'/trials', 02770);
        mkdir($this->root.'/trials/'.self::TRIAL, 02750);
        chmod($this->root.'/trials/'.self::TRIAL, 02750);
        try {
            app(ListingStudioPrivateStorage::class)->prepareTrialDirectory(self::TRIAL);
            $this->fail('An existing FPM-owned unsafe directory needs targeted operator review.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('permissions need operator review', $error->getMessage());
            $this->assertSame(02750, fileperms($this->root.'/trials/'.self::TRIAL) & 07777);
        }
    }

    public function test_symlink_escape_and_unprovisioned_root_are_rejected(): void
    {
        $this->fixture();
        symlink(sys_get_temp_dir(), $this->root.'/trials');
        try {
            app(ListingStudioPrivateStorage::class)->prepareTrialDirectory(self::TRIAL);
            $this->fail('Symlink namespace must not be followed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('outside its storage root', $error->getMessage());
        } finally {
            unlink($this->root.'/trials');
        }
        chmod($this->root, 0700);
        $this->expectException(\RuntimeException::class);
        app(ListingStudioPrivateStorage::class)->prepareTrialDirectory(self::TRIAL);
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
$container->instance('config', new Illuminate\Config\Repository(['filesystems' => ['default' => 'listing_studio_trials', 'disks' => ['listing_studio_trials' => $config]]]));
$container->instance('filesystem', new Illuminate\Filesystem\FilesystemManager($container));
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    if (!posix_setgid((int) $argv[5]) || !posix_setuid((int) $argv[4])) { exit(2); }
}
umask(0022);
$storage = new App\Services\ListingStudio\ListingStudioPrivateStorage;
$disk = Illuminate\Support\Facades\Storage::disk('listing_studio_trials');
$trial = 'trials/aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
if ($argv[2] === 'upload') {
    $storage->prepareTrialDirectory('aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
    $disk->put($trial.'/source.jpg', 'private source');
    $disk->put($trial.'/original-preview.jpg', 'private original preview');
} elseif ($argv[2] === 'worker') {
    if ($disk->get($trial.'/source.jpg') !== 'private source') { exit(3); }
    $storage->prepareTrialDirectory('aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
    $disk->put($trial.'/preview.jpg', 'private edited preview');
    $storage->prepareWorkspaceDirectory('bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb', true);
    $disk->put('studio/workspaces/bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb/edited.jpg', 'private edited output');
} else {
    if ($disk->get($trial.'/preview.jpg') !== 'private edited preview' || $disk->get('studio/workspaces/bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb/edited.jpg') !== 'private edited output') { exit(4); }
}
echo 'ok';
PHP;
        $config = array_replace(config('filesystems.disks.listing_studio_trials'), ['root' => $this->root]);
        foreach ([['upload', $fpm], ['worker', $worker], ['read', $fpm]] as [$step, $user]) {
            $process = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $step, json_encode($config), (string) $user, (string) $group]);
            $process->mustRun();
            $this->assertSame('ok', $process->getOutput());
        }
        clearstatcache();
        $this->assertSame($fpm, fileowner($this->root.'/trials/'.self::TRIAL));
        $this->assertSame($worker, fileowner($this->root.'/trials/'.self::TRIAL.'/preview.jpg'));
        $this->assertSame(02770, fileperms($this->root.'/trials/'.self::TRIAL) & 07777);
        $this->assertSame(0660, fileperms($this->root.'/trials/'.self::TRIAL.'/preview.jpg') & 07777);
    }
}
