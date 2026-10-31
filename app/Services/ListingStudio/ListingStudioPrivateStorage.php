<?php

namespace App\Services\ListingStudio;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Shared local storage for the PHP-FPM uploader and the separately owned Studio worker. */
class ListingStudioPrivateStorage
{
    public function prepareTrialDirectory(string $trialId): void
    {
        $this->uuid($trialId);
        $this->prepare(['trials', $trialId]);
    }

    public function prepareWorkspaceDirectory(string $workspaceId, bool $providerCache = false): void
    {
        $this->uuid($workspaceId);
        $this->prepare($providerCache ? ['studio', 'workspaces', $workspaceId, 'provider-results'] : ['studio', 'workspaces', $workspaceId]);
    }

    private function uuid(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Invalid private Studio directory.');
        }
    }

    private function prepare(array $segments): void
    {
        $disk = Storage::disk(ListingStudioGuestTrialService::DISK);
        $configuredRoot = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        $root = realpath($configuredRoot);
        if (! $root || ! is_dir($root) || is_link($configuredRoot)) {
            throw new RuntimeException('Private Studio storage is not ready.');
        }
        $this->validate($root, filegroup($root));
        $parent = $root;
        $relative = '';
        foreach ($segments as $segment) {
            $relative = ltrim($relative.'/'.$segment, '/');
            $path = $disk->path($relative);
            if (! is_dir($path)) {
                // Nonrecursive mkdir tells us whether this process created the directory.
                // Never chmod a path created concurrently by another Unix owner.
                if (@mkdir($path, 02770)) {
                    // mkdir obeys the FPM umask; chmod restores setgid and group write.
                    $disk->setVisibility($relative, 'private');
                } elseif (! is_dir($path)) {
                    throw new RuntimeException('Private Studio directory could not be created.');
                }
            }
            clearstatcache(true, $path);
            $resolved = realpath($path);
            if (! $resolved || is_link($path) || dirname($resolved) !== $parent) {
                throw new RuntimeException('Private Studio directory is outside its storage root.');
            }
            $this->validate($path, filegroup($root));
            $parent = $resolved;
        }
    }

    private function validate(string $path, int $group): void
    {
        clearstatcache(true, $path);
        if (! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException('Private Studio directory is unavailable.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && ((fileperms($path) & 07777) !== 02770 || filegroup($path) !== $group)) {
            throw new RuntimeException('Private Studio directory permissions need operator review.');
        }
    }
}
