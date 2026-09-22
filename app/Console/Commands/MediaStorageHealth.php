<?php

namespace App\Console\Commands;

use App\Services\Media\MediaStorage;
use App\Services\Media\OriginalsStorageGuard;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class MediaStorageHealth extends Command
{
    protected $signature = 'media:storage-health {--probe : Write, read, and remove unique private test files}';

    protected $description = 'Check the originals drive identity and the separate NVMe preview storage';

    public function handle(MediaStorage $media, OriginalsStorageGuard $guard): int
    {
        $enabled = (bool) config('media.tiered_storage_enabled', false);
        $report = ['healthy' => false, 'tiered_storage_enabled' => $enabled, 'probes' => []];
        try {
            if ($enabled && $media->r2Only()) {
                throw new RuntimeException('HDD tiering and R2-only storage must not be enabled together.');
            }
            $localName = $media->localDiskName();
            $localRoot = realpath((string) config("filesystems.disks.{$localName}.root"));
            $databaseFile = (string) config('database.connections.sqlite.database', ':memory:');
            $databaseRoot = config('database.default') === 'sqlite' && $databaseFile !== ':memory:'
                ? realpath($databaseFile)
                : realpath(database_path());
            $tempRoot = realpath(sys_get_temp_dir());
            if (! $localRoot || ! $databaseRoot || ! $tempRoot) {
                throw new RuntimeException('Local media, database, or working storage directory is missing.');
            }
            $localDevice = stat($localRoot)['dev'];
            if (stat($databaseRoot)['dev'] !== $localDevice) {
                throw new RuntimeException('Previews and the configured database are not on the same local filesystem.');
            }
            $report['preview_disk'] = $localName;
            $report['preview_root'] = $localRoot;
            $report['database_path'] = $databaseRoot;
            $report['processing_temp_root'] = $tempRoot;
            if ($enabled) {
                $guard->assertAvailable();
                $originalName = $media->localDiskName('shoots/0/todo/health.txt');
                $originalRoot = realpath((string) config("filesystems.disks.{$originalName}.root"));
                if (! $originalRoot || stat($originalRoot)['dev'] === $localDevice) {
                    throw new RuntimeException('Originals and previews must be on different filesystems.');
                }
                if (stat($originalRoot)['dev'] === stat($tempRoot)['dev']) {
                    throw new RuntimeException('Processing temporary files must not use the originals drive.');
                }
                $report['originals_disk'] = $originalName;
                $report['originals_root'] = $originalRoot;
            }
            if ($this->option('probe')) {
                $probeKeys = ['preview' => 'shoots/0/thumbnails/.storage-health-'.bin2hex(random_bytes(12)).'.txt'];
                if ($enabled) {
                    $probeKeys['original'] = 'shoots/0/todo/.storage-health-'.bin2hex(random_bytes(12)).'.txt';
                }
                foreach ($probeKeys as $role => $key) {
                    $disk = $media->localDisk($key);
                    $bytes = 'repro-private-storage-check:'.bin2hex(random_bytes(24));
                    try {
                        if (! $disk->put($key, $bytes) || ! hash_equals($bytes, (string) $disk->get($key))) {
                            throw new RuntimeException("The {$role} write/read probe failed.");
                        }
                        if (! hash_equals($bytes, (string) $media->get($key))) {
                            throw new RuntimeException("The {$role} application read probe failed.");
                        }
                        $report['probes'][$role] = 'passed';
                    } finally {
                        if (! $disk->delete($key)) {
                            throw new RuntimeException("Could not remove the unique {$role} health probe.");
                        }
                    }
                }
            }
            $report['healthy'] = true;
        } catch (Throwable $error) {
            $report['error'] = $error->getMessage();
        }
        $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
