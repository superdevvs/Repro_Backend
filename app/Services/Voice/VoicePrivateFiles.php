<?php

namespace App\Services\Voice;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VoicePrivateFiles
{
    /** Touch only the generated voice subtree, never permissions of the general private root. */
    public function prepare(string $directory): void
    {
        if (! preg_match('~^voice/(?:greetings|voicemails(?:/\d+)?)$~', $directory)) {
            throw new RuntimeException('Invalid private voice directory.');
        }
        $disk = Storage::disk('voice_private');
        $parts = explode('/', $directory);
        $relative = '';
        $sharedGroup = config('filesystems.disks.voice_private.shared_group');
        $group = $sharedGroup && function_exists('posix_getgrnam') ? posix_getgrnam($sharedGroup) : false;
        if ($sharedGroup && function_exists('posix_getgrnam') && ! $group) {
            throw new RuntimeException('The private voice service group is unavailable.');
        }
        foreach ($parts as $part) {
            $relative = $relative === '' ? $part : $relative.'/'.$part;
            $path = $disk->path($relative);
            if (! is_dir($path) && ! @mkdir($path, 02770, true) && ! is_dir($path)) {
                throw new RuntimeException('Private voice directory could not be created.');
            }
            clearstatcache(true, $path);
            if ($group && filegroup($path) !== $group['gid'] && ! @chgrp($path, $group['gid'])) {
                throw new RuntimeException('Private voice service-group access requires repair.');
            }
            if ((fileperms($path) & 07777) !== 02770 && ! @chmod($path, 02770)) {
                throw new RuntimeException('Private voice directory permissions require repair.');
            }
            clearstatcache(true, $path);
        }
    }
}
