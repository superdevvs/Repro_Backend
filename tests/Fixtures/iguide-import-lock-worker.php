<?php

// Filesystem-only contender. No inherited PDO socket and no deployment environment.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('__import_lock_test_no_environment__');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'queue.default' => 'sync',
    'filesystems.disks.'.\App\Services\IguideLab\LabService::DISK.'.root' => $argv[1]]);
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Queue::fake();
$upload = new \App\Models\IguideLabImportUpload;
$upload->forceFill(['project_id' => $argv[2], 'id' => $argv[3]]);
// Preserve the original cross-owner, shared-group lock proof when run as root.
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ! posix_seteuid(33)) exit(2);
$entered = app(\App\Services\IguideLab\LabImportUploadLock::class)->run($upload, function () use ($argv) {
    \Illuminate\Support\Facades\Storage::disk(\App\Services\IguideLab\LabService::DISK)->delete($argv[4]);
    return true;
}, false);
echo $entered === null ? 'blocked' : 'entered';
