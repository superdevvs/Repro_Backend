<?php

namespace Tests\Unit;

use App\Services\FalService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FalServiceListingVideoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.fal.key', 'test-key');
        config()->set('services.fal.model', 'fal-ai/wan-pro/image-to-video');
    }

    public function test_listing_video_status_is_normalized(): void
    {
        Http::fake([
            'https://queue.fal.run/fal-ai/wan-pro/requests/request-1/status' => Http::response([
                'status' => 'completed',
            ]),
        ]);

        $this->assertSame('COMPLETED', app(FalService::class)->status('request-1'));
    }

    public function test_listing_video_status_http_error_is_not_treated_as_processing(): void
    {
        Http::fake([
            'https://queue.fal.run/fal-ai/wan-pro/requests/request-2/status' => Http::response([
                'detail' => 'provider unavailable',
            ], 503),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fal.ai status check failed');

        app(FalService::class)->status('request-2');
    }
}
