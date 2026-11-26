<?php

// Filesystem-only contender: no application kernel, providers, environment or DB.
require __DIR__.'/../../vendor/autoload.php';
$app = new class extends \Illuminate\Container\Container {
    public function abort(int $status, string $message = '', array $headers = []): never
    {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, $message, headers: $headers);
    }
};
\Illuminate\Container\Container::setInstance($app);
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$diskName = \App\Services\IguideLab\LabService::DISK;
$app->instance('config', new \Illuminate\Config\Repository(['filesystems' => ['default' => $diskName, 'disks' => [
    $diskName => ['driver' => 'local', 'root' => $argv[1], 'visibility' => 'private', 'directory_visibility' => 'private',
        'permissions' => ['file' => ['public' => 0660, 'private' => 0660], 'dir' => ['public' => 02770, 'private' => 02770]],
        'serve' => false, 'throw' => true, 'report' => false],
]]]));
$filesystems = new \Illuminate\Filesystem\FilesystemManager($app);
$app->instance('filesystem', $filesystems);
// Match the parent fixture's already-open local adapter before dropping owner;
// the subsequent real lock open still runs as uid 33 in the shared private group.
$filesystems->disk($diskName);
$upload = new \App\Models\IguideLabImportUpload;
$upload->forceFill(['project_id' => $argv[2], 'id' => $argv[3]]);
// Preserve the original cross-owner, shared-group lock proof when run as root.
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ! posix_seteuid(33)) exit(2);
$entered = app(\App\Services\IguideLab\LabImportUploadLock::class)->run($upload, function () use ($argv) {
    \Illuminate\Support\Facades\Storage::disk(\App\Services\IguideLab\LabService::DISK)->delete($argv[4]);
    return true;
}, false);
echo $entered === null ? 'blocked' : 'entered';
