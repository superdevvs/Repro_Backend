<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Services\DropboxWorkflowService;
use App\Services\LinkPreview\ImageSourceLoader;
use App\Services\LinkPreview\LinkPreviewService;
use App\Services\LinkPreview\PreviewPayload;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\DeliveryMediaOrderService;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootPaymentStatusSupport;
use App\Services\Shoots\ShootPublicAssetsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MLS rules forbid agent, brokerage and vendor identity on unbranded links, and
 * a preview must never advertise media the page it links to cannot show. Both
 * are silent failures - the card looks fine, it is just not compliant - so they
 * are pinned down here rather than left to visual review.
 */
class LinkPreviewComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    #[Test]
    public function unbranded_payloads_drop_every_branded_3d_destination(): void
    {
        $shoot = Shoot::factory()->create(['address' => '4821 Brandywine St NW']);
        $shoot->iguide_tour_url = 'https://youriguide.com/branded_4821/';
        $shoot->tour_links = [
            'matterport_branded' => 'https://my.matterport.com/show/?m=branded',
            'matterport' => 'https://my.matterport.com/show/?m=legacy',
            'iguide_branded' => 'https://youriguide.com/branded_4821/',
            'zillow_3d' => 'https://www.zillow.com/view-3d-home/abc123',
        ];
        $shoot->save();

        $payload = $this->makeAssets()->buildTypedPublicAssets($shoot, 'mls');

        $this->assertNull($payload['matterport_url']);
        $this->assertNull($payload['iguide_tour_url']);
        $this->assertNull($payload['iguide_url']);

        foreach (['matterport_branded', 'matterport', 'iguide_branded', 'iGuide', 'iguide', 'zillow_3d'] as $key) {
            $this->assertArrayNotHasKey($key, $payload['tour_links'], "{$key} leaked into the MLS payload");
        }
    }

    #[Test]
    public function unbranded_payloads_reject_an_iguide_mls_url_identical_to_the_branded_one(): void
    {
        $shoot = Shoot::factory()->create();
        $shoot->tour_links = [
            'iguide_branded' => 'https://youriguide.com/branded_4821/',
            'iguide_mls' => 'https://youriguide.com/branded_4821/',
        ];
        $shoot->save();

        $payload = $this->makeAssets()->buildTypedPublicAssets($shoot, 'mls');

        $this->assertNull($payload['iguide_tour_url']);
        $this->assertArrayNotHasKey('iguide_mls', $payload['tour_links']);
    }

    #[Test]
    public function unbranded_payloads_reject_an_iguide_mls_url_with_untrusted_provenance(): void
    {
        $shoot = Shoot::factory()->create();
        $shoot->tour_links = [
            'iguide_branded' => 'https://youriguide.com/branded_4821/',
            'iguide_mls' => 'https://youriguide.com/some_other_4821/',
            'iguide_mls_source' => 'tour_url',
        ];
        $shoot->save();

        $payload = $this->makeAssets()->buildTypedPublicAssets($shoot, 'mls');

        $this->assertNull($payload['iguide_tour_url']);
        $this->assertArrayNotHasKey('iguide_mls', $payload['tour_links']);
    }

    #[Test]
    public function unbranded_payloads_keep_a_verified_unbranded_iguide_url(): void
    {
        $shoot = Shoot::factory()->create();
        $shoot->tour_links = [
            'iguide_branded' => 'https://youriguide.com/branded_4821/',
            'iguide_mls' => 'https://youriguide.com/unbranded_4821/',
            'iguide_mls_source' => 'unbranded_url',
        ];
        $shoot->save();

        $payload = $this->makeAssets()->buildTypedPublicAssets($shoot, 'mls');

        $this->assertSame('https://youriguide.com/unbranded_4821/', $payload['iguide_tour_url']);
    }

    #[Test]
    public function the_mls_preview_carries_no_vendor_agent_or_brokerage_identity(): void
    {
        $shoot = Shoot::factory()->create(['address' => '4821 Brandywine St NW']);

        $payload = $this->makePreviews()->forShoot($shoot, 'mls');

        $this->assertFalse($payload->branded);
        $this->assertNull($payload->agentName);
        $this->assertNull($payload->agentCompany);

        foreach ([$payload->title, $payload->description, (string) $payload->chipLabel] as $copy) {
            $this->assertStringNotContainsStringIgnoringCase('R/E Pro', $copy);
            $this->assertStringNotContainsStringIgnoringCase('REPRO', $copy);
            $this->assertStringNotContainsStringIgnoringCase('Pro Photos', $copy);
        }
    }

    #[Test]
    public function the_mls_preview_does_not_advertise_a_branded_only_zillow_walkthrough(): void
    {
        $shoot = Shoot::factory()->create(['address' => '4821 Brandywine St NW']);
        $shoot->tour_links = ['zillow_3d' => 'https://www.zillow.com/view-3d-home/abc123'];
        $shoot->save();

        $previews = $this->makePreviews();

        $mls = $previews->forShoot($shoot, 'mls');
        $this->assertStringNotContainsStringIgnoringCase('3d', $mls->description);
        $this->assertStringNotContainsStringIgnoringCase('walkthrough', $mls->description);

        // The branded link may still advertise it.
        $branded = $previews->forShoot($shoot, 'branded');
        $this->assertStringContainsStringIgnoringCase('3d', $branded->description . $branded->chipLabel);
    }

    #[Test]
    public function remote_image_loading_is_limited_to_allowlisted_hosts(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://i.ytimg.com/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $loader = new ImageSourceLoader(new MediaStorage());

        $this->assertSame(
            'jpeg-bytes',
            $loader->load('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'),
            'An allowlisted CDN thumbnail should load.'
        );

        Http::assertSentCount(1);

        // Anything else must be refused without touching the network: these
        // would otherwise be SSRF vectors reachable from stored tour data.
        foreach ([
            'http://i.ytimg.com/vi/x/hqdefault.jpg',          // plaintext
            'https://evil.example.com/card.jpg',              // unknown host
            'https://127.0.0.1/card.jpg',                     // loopback
            'https://169.254.169.254/latest/meta-data/',       // cloud metadata
            'https://i.ytimg.com:8443/vi/x/hqdefault.jpg',     // non-443 port
            'https://user:pass@i.ytimg.com/vi/x/hq.jpg',       // credentials
            'https://notytimg.com/vi/x/hqdefault.jpg',         // suffix confusion
        ] as $blocked) {
            $this->assertNull($loader->load($blocked), "{$blocked} should be blocked");
        }

        // Still 1: none of the blocked URLs generated a request.
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_card_fingerprint_changes_when_the_underlying_media_changes(): void
    {
        $base = [
            'type' => 'mls',
            'design' => 'd2',
            'branded' => false,
            'title' => '4821 Brandywine St NW',
            'description' => 'View the property tour.',
            'url' => 'https://reprodashboard.com/tour/mls?shootId=1',
            'hero' => 'shoots/1/webs/hero_web.jpg',
        ];

        $before = new PreviewPayload(...$base, fingerprintSeed: 'hero_web.jpg:1700000000:184320');
        $after = new PreviewPayload(...$base, fingerprintSeed: 'hero_web.jpg:1700009999:191001');

        $this->assertNotSame(
            $before->fingerprint(),
            $after->fingerprint(),
            'A replaced photo at a stable object key must produce a new immutable card URL.'
        );
    }

    #[Test]
    public function a_stale_card_url_serves_the_current_card_instead_of_a_broken_image(): void
    {
        // A crawler keeps an og:image URL long after the shoot changes, and an
        // edge-cached metadata response can publish a fingerprint that was never
        // rendered. Returning 404 there shows a broken preview to everyone who
        // already shared the link.
        $stale = str_repeat('a', 16);

        $response = $this->get("/api/public/link-previews/dashboard/image/{$stale}.jpg");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringNotContainsString(
            'immutable',
            (string) $response->headers->get('Cache-Control'),
            'Bytes that do not match the requested fingerprint must not be cached forever.'
        );

        $info = @getimagesizefromstring((string) $response->getContent());
        $this->assertIsArray($info);
        $this->assertSame([1200, 630], [$info[0], $info[1]]);
    }

    #[Test]
    public function a_matching_card_url_is_cached_immutably(): void
    {
        $metadata = $this->getJson('/api/public/link-previews/dashboard')->assertOk()->json();
        $fingerprint = $metadata['fingerprint'];

        $response = $this->get("/api/public/link-previews/dashboard/image/{$fingerprint}.jpg");

        $response->assertOk();
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('"' . $fingerprint . '"', $response->headers->get('ETag'));
    }

    private function makePreviews(): LinkPreviewService
    {
        return new LinkPreviewService($this->makeAssets());
    }

    private function makeAssets(): ShootPublicAssetsService
    {
        $paymentStatusSupport = Mockery::mock(ShootPaymentStatusSupport::class);
        $paymentStatusSupport
            ->shouldReceive('reconcileStripePaymentState')
            ->andReturnUsing(function (Shoot $shoot, array $relations = []) {
                $shoot->loadMissing($relations);

                return $shoot;
            });

        return new ShootPublicAssetsService(
            Mockery::mock(DropboxWorkflowService::class),
            $paymentStatusSupport,
            Mockery::mock(ShootClientReleaseAccessService::class),
            new MediaStorage(),
            new DeliveryMediaOrderService()
        );
    }
}
