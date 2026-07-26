<?php

namespace Tests\Unit;

use App\Services\FalService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FalServiceQueueUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fal.key' => 'test-key-id:test-key-secret',
            'services.fal.image_model' => 'fal-ai/flux-kontext/dev',
            'services.fal.model' => 'fal-ai/wan-pro/image-to-video',
        ]);
    }

    public function test_image_edit_status_uses_base_app_id_without_model_sub_path(): void
    {
        Http::fake([
            'queue.fal.run/fal-ai/flux-kontext/requests/req-123/status' => Http::response(['status' => 'COMPLETED'], 200),
        ]);

        $status = app(FalService::class)->imageEditStatus('req-123');

        $this->assertSame('completed', $status['status']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://queue.fal.run/fal-ai/flux-kontext/requests/req-123/status';
        });
    }

    public function test_image_edit_result_uses_base_app_id_without_model_sub_path(): void
    {
        Http::fake([
            'queue.fal.run/fal-ai/flux-kontext/requests/req-123/response' => Http::response([
                'images' => [['url' => 'https://v3b.fal.media/files/b/edited.jpg']],
            ], 200),
        ]);

        $result = app(FalService::class)->imageEditResult('req-123');

        $this->assertSame('https://v3b.fal.media/files/b/edited.jpg', $result['edited_image_url']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://queue.fal.run/fal-ai/flux-kontext/requests/req-123/response';
        });
    }

    public function test_video_status_uses_base_app_id_without_model_sub_path(): void
    {
        Http::fake([
            'queue.fal.run/fal-ai/wan-pro/requests/req-456/status' => Http::response(['status' => 'IN_QUEUE'], 200),
        ]);

        $this->assertSame('IN_QUEUE', app(FalService::class)->status('req-456'));
        Http::assertSent(function ($request) {
            return $request->url() === 'https://queue.fal.run/fal-ai/wan-pro/requests/req-456/status';
        });
    }

    public function test_submit_still_posts_to_full_model_path(): void
    {
        Http::fake([
            'queue.fal.run/fal-ai/flux-kontext/dev' => Http::response(['request_id' => 'req-789'], 200),
        ]);

        $submission = app(FalService::class)->submitImageEdit('https://example.com/photo.jpg', 'enhance');

        $this->assertSame('req-789', $submission['request_id']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://queue.fal.run/fal-ai/flux-kontext/dev';
        });
    }
}
