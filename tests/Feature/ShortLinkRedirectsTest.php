<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootShareLink;
use App\Models\User;
use App\Services\Payments\PublicPaymentAccessTokenService;
use App\Services\Shoots\ShootMediaArchiveService;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShortLinkRedirectsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_short_share_link_redirects_to_the_public_share_page(): void
    {
        Config::set('app.frontend_url', 'https://repro.test');
        Config::set('short_links.types.share_download', true);
        $user = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $link = ShootShareLink::create([
            'shoot_id' => $shoot->id,
            'created_by' => $user->id,
            'share_url' => 'https://repro.test/share/pending',
            'media_stage' => 'raw',
            'public_token' => 'share-token-abcdefghijklmnop',
        ]);

        $shortUrl = app(ShootShareLinkService::class)->buildPublicShareUrl($link);
        $path = (string) parse_url($shortUrl, PHP_URL_PATH);

        $this->get($path)
            ->assertRedirect('https://repro.test/share/share-token-abcdefghijklmnop');
    }

    #[Test]
    public function a_revoked_share_link_does_not_redirect(): void
    {
        Config::set('app.frontend_url', 'https://repro.test');
        Config::set('short_links.types.share_download', true);
        $user = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $link = ShootShareLink::create([
            'shoot_id' => $shoot->id,
            'created_by' => $user->id,
            'share_url' => 'https://repro.test/share/pending',
            'media_stage' => 'raw',
            'public_token' => 'share-token-revokedxxxxxxxx',
            'is_revoked' => true,
            'revoked_at' => now(),
        ]);

        $shortUrl = app(ShootShareLinkService::class)->buildPublicShareUrl($link);
        $path = (string) parse_url($shortUrl, PHP_URL_PATH);
        $this->assertMatchesRegularExpression('#^/api/g/[A-Za-z0-9]{8,12}$#', $path);

        $this->get($path)->assertNotFound();
    }

    #[Test]
    public function a_short_media_zip_link_redirects_to_a_fresh_signed_download(): void
    {
        Config::set('short_links.types.media_zip', true);
        $shoot = Shoot::factory()->create();

        $shortUrl = app(ShootMediaArchiveService::class)->buildPublicDownloadUrl($shoot, 'edited', 'small');
        $path = (string) parse_url($shortUrl, PHP_URL_PATH);

        $response = $this->get($path);
        $response->assertRedirect();
        $this->assertStringContainsString('/download/media?url=', $response->headers->get('Location', ''));
        $this->assertStringContainsString((string) $shoot->id, urldecode((string) $response->headers->get('Location')));
    }

    #[Test]
    public function a_short_payment_link_redirects_to_the_current_access_token(): void
    {
        Config::set('app.frontend_url', 'https://repro.test');
        Config::set('short_links.types.payment', true);
        $shoot = Shoot::factory()->create();
        $token = app(PublicPaymentAccessTokenService::class)->ensureActiveToken($shoot);

        $shortUrl = app(PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot);
        $path = (string) parse_url($shortUrl, PHP_URL_PATH);

        $this->get($path)->assertRedirect('https://repro.test/payment/'.$token->token);
    }

    #[Test]
    public function redirect_codes_reject_archive_paths(): void
    {
        Config::set('short_links.types.payment', true);
        $shoot = Shoot::factory()->create();
        $shortUrl = app(PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot);
        $path = (string) parse_url($shortUrl, PHP_URL_PATH);
        $this->assertMatchesRegularExpression('#^/api/g/[A-Za-z0-9]{8,12}$#', $path);

        $this->get($path.'/tour/index.html')->assertNotFound();
    }
}
