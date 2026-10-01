<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use LogicException;

final class VoiceCache
{
    public static function store(): Repository
    {
        $store = trim((string) config('voice_calls.cache_store', 'database'));
        if ($store === '') {
            throw new LogicException('The shared voice cache store must be configured.');
        }

        return Cache::store($store);
    }
}
