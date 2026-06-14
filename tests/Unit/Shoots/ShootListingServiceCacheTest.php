<?php

namespace Tests\Unit\Shoots;

use App\Services\Shoots\ShootListingService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ShootListingServiceCacheTest extends TestCase
{
    public function test_flush_cached_listings_forgets_registered_index_keys(): void
    {
        Cache::put('shoots_index_1_admin_scheduled_1_25', ['data' => ['stale']], now()->addMinute());
        Cache::put('shoots_index_2_client_scheduled_1_25', ['data' => ['stale']], now()->addMinute());
        Cache::put('shoots_index_cache_keys', [
            'shoots_index_1_admin_scheduled_1_25',
            'shoots_index_2_client_scheduled_1_25',
        ], now()->addMinute());

        ShootListingService::flushCachedListings();

        $this->assertNull(Cache::get('shoots_index_1_admin_scheduled_1_25'));
        $this->assertNull(Cache::get('shoots_index_2_client_scheduled_1_25'));
        $this->assertNull(Cache::get('shoots_index_cache_keys'));
    }
}
