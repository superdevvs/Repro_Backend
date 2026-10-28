<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootPaymentStatusSupport;
use App\Services\Shoots\ShootPublicAssetsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Jobs\ResolvePublicVideoThumbnail;
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
    public function it_resolves_a_vimeo_thumbnail_off_the_public_page_request(): void
    {
        Queue::fake();
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

        $this->assertNull($payload['video_thumbnail_url']);
        Http::assertNothingSent();
        Queue::assertPushed(ResolvePublicVideoThumbnail::class, fn ($job) => $job->vimeoId === '1186135733');
        (new ResolvePublicVideoThumbnail('1186135733'))->handle();
        $payload = $this->makeService()->buildTypedPublicAssets($shoot, 'branded');

        $this->assertSame(
            'https://i.vimeocdn.com/video/123456789-abc.jpg',
            $payload['video_thumbnail_url']
        );

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_missing_vimeo_thumbnail_is_negatively_cached(): void
    {
        Queue::fake();
        Http::fake(['https://vimeo.com/api/oembed.json*' => Http::response([], 404)]);
        (new ResolvePublicVideoThumbnail('12345'))->handle();
        $shoot = Shoot::factory()->create(['tour_links' => ['video_branded' => 'https://vimeo.com/12345']]);
        $this->assertNull($this->makeService()->buildTypedPublicAssets($shoot, 'branded')['video_thumbnail_url']);
        Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    protected function makeService(): ShootPublicAssetsService
    {
        $paymentStatusSupport = Mockery::mock(ShootPaymentStatusSupport::class);
        $clientReleaseAccessService = Mockery::mock(ShootClientReleaseAccessService::class);

        $paymentStatusSupport
            ->shouldReceive('reconcileStripePaymentState')
            ->andReturnUsing(function (Shoot $shoot, array $relations = []) {
                $shoot->loadMissing($relations);

                return $shoot;
            });

        return new ShootPublicAssetsService(
            $paymentStatusSupport,
            $clientReleaseAccessService,
            new \App\Services\Media\MediaStorage(),
            // Real instance: the delivery ordering is pure sorting over the files
            // already loaded, so there is nothing to stub and a mock would only
            // hide a genuine ordering regression in the public gallery.
            new \App\Services\Shoots\DeliveryMediaOrderService()
        );
    }
}
