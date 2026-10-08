<?php

namespace Tests\Unit;

use App\Models\StudioWorkspace;
use App\Services\Studio\WorkspacePhotoLogo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCreateDirectory;
use Tests\TestCase;

class WorkspacePhotoLogoPermissionsTest extends TestCase
{
    public function test_php_can_upload_a_logo_when_worker_outputs_are_not_group_writable(): void
    {
        $separateUsers = function_exists('pcntl_fork') && function_exists('posix_setuid') && posix_geteuid() === 0;

        $root = sys_get_temp_dir().'/studio-logo-permissions-'.Str::uuid();
        $workspace = new StudioWorkspace;
        $workspace->id = (string) Str::uuid();
        $outputDirectory = $root.'/studio/workspaces/'.$workspace->id;
        File::ensureDirectoryExists($outputDirectory);
        foreach ([$root, $root.'/studio', $root.'/studio/workspaces'] as $directory) {
            if ($separateUsers) {
                chgrp($directory, 33);
            }
            chmod($directory, 02775);
        }
        if ($separateUsers) {
            chown($outputDirectory, 1001);
            chgrp($outputDirectory, 33);
        }
        // Ordinary CI users cannot impersonate PHP-FPM. A read-only output
        // directory exercises the same denied write without requiring root.
        $outputMode = $separateUsers ? 02755 : 02555;
        chmod($outputDirectory, $outputMode);
        Storage::set('public', Storage::build([
            'driver' => 'local', 'root' => $root, 'url' => '/storage',
            'visibility' => 'public', 'throw' => true,
        ]));
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $exercise = function () use ($workspace, $bytes, $separateUsers): int {
            try {
                if ($separateUsers && (! posix_setgid(33) || ! posix_setuid(33))) {
                    return 2;
                }
                // Prove the old location cannot be created by PHP-FPM's user.
                try {
                    Storage::disk('public')->put('studio/workspaces/'.$workspace->id.'/logos/probe.png', $bytes);

                    return 3;
                } catch (UnableToCreateDirectory) {
                }
                $service = app(WorkspacePhotoLogo::class);
                $logo = $service->store($workspace, $bytes);
                $stored = $service->bytes($workspace, ['logo' => ['id' => $logo['id']]]);

                return getimagesizefromstring($stored)[0] === 10 ? 0 : 4;
            } catch (\Throwable) {
                return 5;
            }
        };

        try {
            if ($separateUsers) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    exit($exercise());
                }
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            } else {
                $this->assertSame(0, $exercise());
            }
            $this->assertCount(1, glob($root.'/studio/logos/'.$workspace->id.'/*.png'));
            clearstatcache(true, $outputDirectory);
            $this->assertSame($outputMode, fileperms($outputDirectory) & 07777);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
