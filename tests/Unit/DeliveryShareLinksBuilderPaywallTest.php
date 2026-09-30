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

/**
 * Unpaid delivered shoots must not receive forwardable share links in emails.
 * Paid and bypass_paywall shoots keep the full link set (same public-release gate).
 */
class DeliveryShareLinksBuilderPaywallTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unpaid_shoot_without_bypass_omits_all_share_links(): void
    {
        $archives = Mockery::mock(ShootMediaArchiveService::class);
        $archives->shouldNotReceive('hasDownloadableFiles');
        $archives->shouldNotReceive('buildPublicDownloadUrl');

        $builder = $this->makeBuilder($archives);
        $shoot = $this->makeShoot([
            'payment_status' => 'unpaid',
            'bypass_paywall' => false,
            'total_paid' => 0,
            'total_quote' => 299.00,
        ]);

        $result = $builder->forShoot($shoot);

        $this->assertFalse($result['has_links']);
        $this->assertSame([], $result['links']);
        $this->assertSame('', $result['completed_shoots_note']);
        $this->assertNull($result['property_url']);
        $this->assertNull($result['small_zip_link']);
        $this->assertNull($result['full_zip_link']);
        $this->assertNull($result['mls_tour_link']);
        $this->assertNull($result['branded_tour_link']);
        $this->assertNull($result['video_download_link']);
        $this->assertNull($result['zillow_3d_link']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function partial_payment_without_bypass_omits_share_links(): void
    {
        $archives = Mockery::mock(ShootMediaArchiveService::class);
        $archives->shouldNotReceive('hasDownloadableFiles');

        $builder = $this->makeBuilder($archives);
        $shoot = $this->makeShoot([
            'payment_status' => 'partial',
            'bypass_paywall' => false,
            'total_paid' => 50,
            'total_quote' => 299.00,
        ]);

        $result = $builder->forShoot($shoot);

        $this->assertFalse($result['has_links']);
        $this->assertSame([], $result['links']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paid_shoot_includes_share_links(): void
    {
        config(['app.frontend_url' => 'https://app.example.test']);

        $archives = Mockery::mock(ShootMediaArchiveService::class);
        $archives->shouldReceive('hasDownloadableFiles')
            ->with(Mockery::type(Shoot::class), 'edited', 'small')
            ->once()
            ->andReturn(true);
        $archives->shouldReceive('buildPublicDownloadUrl')
            ->with(Mockery::type(Shoot::class), 'edited', 'small')
            ->once()
            ->andReturn('https://cdn.example.test/small.zip');
        $archives->shouldReceive('hasDownloadableFiles')
            ->with(Mockery::type(Shoot::class), 'edited', 'original')
            ->once()
            ->andReturn(true);
        $archives->shouldReceive('buildPublicDownloadUrl')
            ->with(Mockery::type(Shoot::class), 'edited', 'original')
            ->once()
            ->andReturn('https://cdn.example.test/full.zip');

        $builder = $this->makeBuilder($archives);
        $shoot = $this->makeShoot([
            'payment_status' => 'paid',
            'bypass_paywall' => false,
            'total_paid' => 299.00,
            'total_quote' => 299.00,
            'tour_links' => [
                'zillow_3d' => 'https://www.zillow.com/view-3d-home/example',
            ],
        ]);
        $shoot->setRelation('files', collect());

        $result = $builder->forShoot($shoot);

        $this->assertTrue($result['has_links']);
        $this->assertNotSame([], $result['links']);
        $this->assertSame('https://cdn.example.test/small.zip', $result['small_zip_link']);
        $this->assertSame('https://cdn.example.test/full.zip', $result['full_zip_link']);
        $this->assertNotEmpty($result['mls_tour_link']);
        $this->assertNotEmpty($result['branded_tour_link']);
        $this->assertSame('https://www.zillow.com/view-3d-home/example', $result['zillow_3d_link']);
        $this->assertStringContainsString('Completed Shoots', $result['completed_shoots_note']);

        $keys = array_column($result['links'], 'key');
        $this->assertContains('small_zip', $keys);
        $this->assertContains('full_zip', $keys);
        $this->assertContains('mls_tour', $keys);
        $this->assertContains('branded_tour', $keys);
        $this->assertContains('property', $keys);
        $this->assertContains('zillow_3d', $keys);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function bypass_paywall_unlocks_share_links_while_unpaid(): void
    {
        config(['app.frontend_url' => 'https://app.example.test']);

        $archives = Mockery::mock(ShootMediaArchiveService::class);
        $archives->shouldReceive('hasDownloadableFiles')->andReturn(false);

        $builder = $this->makeBuilder($archives);
        $shoot = $this->makeShoot([
            'payment_status' => 'unpaid',
            'bypass_paywall' => true,
            'total_paid' => 0,
            'total_quote' => 299.00,
        ]);
        $shoot->setRelation('files', collect());

        $result = $builder->forShoot($shoot);

        $this->assertTrue($result['has_links']);
        $keys = array_column($result['links'], 'key');
        $this->assertContains('mls_tour', $keys);
        $this->assertContains('branded_tour', $keys);
        $this->assertContains('property', $keys);
        $this->assertStringContainsString('Completed Shoots', $result['completed_shoots_note']);
    }

    private function makeBuilder(ShootMediaArchiveService $archives): DeliveryShareLinksBuilder
    {
        $media = Mockery::mock(MediaStorage::class);
        $release = new ShootClientReleaseAccessService(
            Mockery::mock(ShootPaymentStatusSupport::class)->shouldIgnoreMissing()
        );

        return new DeliveryShareLinksBuilder($archives, $media, $release);
    }

    private function makeShoot(array $attrs): Shoot
    {
        $shoot = new Shoot(array_merge([
            'address' => '100 Test St',
            'city' => 'Caledonia',
            'state' => 'NY',
            'zip' => '14423',
        ], $attrs));
        $shoot->id = 385;
        $shoot->exists = true;

        return $shoot;
    }
}
