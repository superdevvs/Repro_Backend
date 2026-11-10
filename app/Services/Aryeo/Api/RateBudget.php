<?php

namespace App\Services\Aryeo\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class RateBudget
{
    public function reserve(): void
    {
        $key = 'aryeo-api:'.hash('sha256', (string) config('aryeo_api.client_id'));
        Cache::lock($key.':reservation', 5)->block(1, function () use ($key) {
            abort_if(RateLimiter::tooManyAttempts($key, config('aryeo_api.requests_per_minute')), 429, 'provider_rate_limited');
            RateLimiter::hit($key, 60);
        });
    }
}
