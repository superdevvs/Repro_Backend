<?php

namespace Tests\Unit;

use App\Services\FalService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FalWorkspacePayloadTest extends TestCase
{
    public function test_walkthrough_submits_both_conditioning_images_to_the_pinned_model(): void
    {
        config(['services.fal.key' => 'test-only-key', 'services.fal.walkthrough_model' => 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video']);
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'clip-one'])]);
        $id = (new FalService)->submitWalkthroughClip('https://fixture.test/start.jpg', 'https://fixture.test/end.jpg', 'Move smoothly');
        $this->assertSame('clip-one', $id);
        Http::assertSent(fn ($request) => $request->url() === 'https://queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/image-to-video'
            && $request['image_url'] === 'https://fixture.test/start.jpg' && $request['tail_image_url'] === 'https://fixture.test/end.jpg' && $request['duration'] === '5');
    }

    public function test_last_shot_does_not_invent_a_tail_frame(): void
    {
        config(['services.fal.key' => 'test-only-key', 'services.fal.walkthrough_model' => 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video']);
        Http::fake(['queue.fal.run/*' => Http::response(['request_id' => 'last-shot'])]);
        (new FalService)->submitWalkthroughClip('https://fixture.test/start.jpg', null, 'Final gentle movement');
        Http::assertSent(fn ($request) => ! isset($request['tail_image_url']));
    }
}
