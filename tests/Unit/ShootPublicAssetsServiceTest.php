<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootPaymentStatusSupport;
use App\Services\Shoots\ShootPublicAssetsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShootPublicAssetsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function it_returns_a_youtube_thumbnail_for_public_video_pages(): void
    {
        $shoot = Shoot::factory()->create();
        $shoot->tour_links = [
            'video_branded' => 'https://youtu.be/dQw4w9WgXcQ',
        ];
        $shoot->save();

        $payload = $this->makeService()->buildTypedPublicAssets($shoot, 'branded');

        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $payload['video_link']);
        $this->assertSame(
            'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            $payload['video_thumbnail_url']
        );
        $this->assertSame($payload['video_thumbnail_url'], $payload['video_poster_url']);
    }

    #[Test]
    public function it_fetches_a_vimeo_thumbnail_for_public_video_pages(): void
    {
        Http::fake([
            'https://vimeo.com/api/oembed.json*' => Http::response([
                'thumbnail_url' => 'https://i.vimeocdn.com/video/123456789-abc.jpg',
            ], 200),
        ]);

        $shoot = Shoot::factory()->create();
        $shoot->tour_links = [
            'video_branded' => 'https://player.vimeo.com/video/1186135733?share=copy',
        ];
        $shoot->save();

        $payload = $this->makeService()->buildTypedPublicAssets($shoot, 'branded');

        $this->assertSame(
            'https://i.vimeocdn.com/video/123456789-abc.jpg',
            $payload['video_thumbnail_url']
        );

        Http::assertSentCount(1);
    }

    protected function makeService(): ShootPublicAssetsService
    {
        $dropboxService = Mockery::mock(DropboxWorkflowService::class);
        $paymentStatusSupport = Mockery::mock(ShootPaymentStatusSupport::class);
        $clientReleaseAccessService = Mockery::mock(ShootClientReleaseAccessService::class);

        $paymentStatusSupport
            ->shouldReceive('reconcileStripePaymentState')
            ->andReturnUsing(function (Shoot $shoot, array $relations = []) {
                $shoot->loadMissing($relations);

                return $shoot;
            });

        return new ShootPublicAssetsService(
            $dropboxService,
            $paymentStatusSupport,
            $clientReleaseAccessService,
            new \App\Services\Media\MediaStorage()
        );
    }
}
