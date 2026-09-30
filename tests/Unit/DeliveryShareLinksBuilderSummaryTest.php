<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Services\Media\MediaStorage;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\ShootPaymentStatusSupport;
use App\Services\SystemEmails\DeliveryShareLinksBuilder;
use Mockery;
use Tests\TestCase;

class DeliveryShareLinksBuilderSummaryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unpaid_summary_contains_no_forwardable_links(): void
    {
        $archives = Mockery::mock(ShootMediaArchiveService::class);
        $archives->shouldNotReceive('hasDownloadableFiles');
        $builder = $this->makeBuilder($archives);
        $shoot = $this->makeShoot([
            'payment_status' => 'partial',
            'total_paid' => 50,
            'total_quote' => 299,
            'tour_links' => ['mls' => 'https://example.test/tour'],
        ]);

        $links = $builder->forShootSummary($shoot);

        $this->assertFalse($links['has_links']);
        $this->assertSame([], $links['links']);
        $this->assertSame([], $links['additional_links']);
    }

    public function test_summary_only_includes_available_safe_links_and_omits_empty_sections(): void
    {
        $archives = Mockery::mock(ShootMediaArchiveService::class);
        $archives->shouldReceive('hasDownloadableFiles')->twice()->andReturn(false);
        $builder = $this->makeBuilder($archives);
        $shoot = $this->makeShoot([
            'payment_status' => 'paid',
            'total_paid' => 299,
            'total_quote' => 299,
            'tour_links' => [
                'mls' => 'https://example.test/mls',
                'branded' => 'https://example.test/branded',
                'property_link' => 'https://example.test/property',
                'video_mls' => 'javascript:alert(1).mp4',
                'video_generic' => 'https://vimeo.com/12345',
                'matterport_branded' => 'https://my.matterport.com/show/?m=example',
                'zillow_3d' => 'https://www.zillow.com/view-3d-home/example',
            ],
        ]);
        $shoot->setRelation('files', collect());

        $links = $builder->forShootSummary($shoot);

        $this->assertNull($links['small_zip_link']);
        $this->assertNull($links['full_zip_link']);
        $this->assertSame('https://example.test/mls', $links['mls_tour_link']);
        $this->assertSame('https://example.test/branded', $links['branded_tour_link']);
        $this->assertSame('https://example.test/property', $links['property_url']);
        $this->assertNull($links['video_download_link']);
        $this->assertSame('https://www.zillow.com/view-3d-home/example', $links['zillow_3d_link']);
        $this->assertContains('https://vimeo.com/12345', array_column($links['additional_links'], 'url'));
        $this->assertContains('https://my.matterport.com/show/?m=example', array_column($links['additional_links'], 'url'));
        $this->assertNotContains('javascript:alert(1).mp4', array_column($links['links'], 'url'));

        $html = view('emails.shoot_summary', [
            'shoot' => (object) ['location' => '100 Test St', 'date' => 'Sep 30, 2026', 'services' => [], 'dashboard_url' => 'https://reprodashboard.com'],
            'deliveryShare' => $links,
        ])->render();
        $this->assertStringContainsString('MLS-Compliant Tour', $html);
        $this->assertStringContainsString('Additional links', $html);
        $this->assertStringNotContainsString('Image downloads', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
    }

    public function test_summary_generates_photo_links_and_tour_routes_only_when_edited_media_exists(): void
    {
        config(['app.frontend_url' => 'https://app.example.test']);
        $archives = Mockery::mock(ShootMediaArchiveService::class);
        $archives->shouldReceive('hasDownloadableFiles')->with(Mockery::type(Shoot::class), 'edited', 'small')->once()->andReturn(true);
        $archives->shouldReceive('buildPublicDownloadUrl')->with(Mockery::type(Shoot::class), 'edited', 'small')->once()->andReturn('https://app.example.test/download/small');
        $archives->shouldReceive('hasDownloadableFiles')->with(Mockery::type(Shoot::class), 'edited', 'original')->once()->andReturn(false);
        $builder = $this->makeBuilder($archives);
        $shoot = $this->makeShoot(['payment_status' => 'paid', 'total_paid' => 299, 'total_quote' => 299]);
        $shoot->setRelation('files', collect());

        $links = $builder->forShootSummary($shoot);

        $this->assertSame('https://app.example.test/download/small', $links['small_zip_link']);
        $this->assertNull($links['full_zip_link']);
        $this->assertSame('https://app.example.test/tour/mls?shootId=385', $links['mls_tour_link']);
        $this->assertSame('https://app.example.test/tour/branded?shootId=385', $links['branded_tour_link']);
    }

    public function test_explicit_zero_dollar_summary_includes_deliverable_links_without_bypass_flag(): void
    {
        $archives = Mockery::mock(ShootMediaArchiveService::class);
        $archives->shouldReceive('hasDownloadableFiles')->with(Mockery::type(Shoot::class), 'edited', 'small')->once()->andReturn(true);
        $archives->shouldReceive('buildPublicDownloadUrl')->with(Mockery::type(Shoot::class), 'edited', 'small')->once()->andReturn('https://app.example.test/download/free-shoot');
        $archives->shouldReceive('hasDownloadableFiles')->with(Mockery::type(Shoot::class), 'edited', 'original')->once()->andReturn(false);
        $builder = $this->makeBuilder($archives);
        $shoot = $this->makeShoot([
            'payment_status' => Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED,
            'total_quote' => 0,
            'bypass_paywall' => false,
        ]);
        $shoot->setRelation('files', collect());

        $links = $builder->forShootSummary($shoot);

        $this->assertTrue($links['has_links']);
        $this->assertSame('https://app.example.test/download/free-shoot', $links['small_zip_link']);
    }

    public function test_summary_view_keeps_dashboard_when_no_share_links_exist(): void
    {
        $html = view('emails.shoot_summary', [
            'shoot' => (object) ['location' => '100 Test St', 'date' => 'Sep 30, 2026', 'services' => [], 'dashboard_url' => 'https://reprodashboard.com'],
            'deliveryShare' => ['links' => [], 'additional_links' => [], 'has_links' => false],
        ])->render();

        $this->assertStringContainsString('Open Shoot in Dashboard', $html);
        $this->assertStringContainsString('Any downloadable files and share links will appear there when available.', $html);
        $this->assertStringNotContainsString('Image downloads', $html);
        $this->assertStringNotContainsString('Virtual tours', $html);
    }

    private function makeBuilder(ShootMediaArchiveService $archives): DeliveryShareLinksBuilder
    {
        return new DeliveryShareLinksBuilder(
            $archives,
            Mockery::mock(MediaStorage::class),
            new ShootClientReleaseAccessService(Mockery::mock(ShootPaymentStatusSupport::class)->shouldIgnoreMissing()),
        );
    }

    private function makeShoot(array $attributes): Shoot
    {
        $shoot = new Shoot(array_merge([
            'address' => '100 Test St', 'city' => 'Caledonia', 'state' => 'NY', 'zip' => '14423',
        ], $attributes));
        $shoot->id = 385;
        $shoot->exists = true;

        return $shoot;
    }
}
