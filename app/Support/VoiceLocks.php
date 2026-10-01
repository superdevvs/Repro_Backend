<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use LogicException;

final class VoiceLocks
{
    public static function lock(string $name, int $seconds): Lock
    {
        // Webhooks run as PHP-FPM while queues run as a different Unix user.
        // The shared database owns leases without relying on file ownership.
        // Do not fall back to the application's default/file cache on failure.
        $store = trim((string) config('voice_calls.lock_store', 'database'));
        if ($store === '') {
            throw new LogicException('The shared voice lock store must be configured.');
        }

        return Cache::store($store)->lock($name, $seconds);
    }
}
