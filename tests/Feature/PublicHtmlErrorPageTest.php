<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\ShootShareLink;
use App\Models\User;
use App\Services\Shoots\ShootShareLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicHtmlErrorPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_browser_opening_an_unknown_iguide_short_link_sees_the_blueprint_page(): void
    {
        Config::set('app.frontend_url', 'https://repro.test');

        $this->get('/api/g/notAReal1/tour/index.html')
            ->assertNotFound()
            ->assertSee('This page is under a different plan', false)
            ->assertSee('Go to Homepage', false)
            ->assertSee('https://repro.test/', false)
            ->assertSee('https://reprophotos.com/services/', false)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    #[Test]
    public function json_clients_still_receive_the_api_envelope_for_missing_short_links(): void
    {
        $response = $this->getJson('/api/g/notAReal1/tour/index.html')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        $this->assertStringNotContainsString('This page is under a different plan', $response->getContent());
    }

    #[Test]
    public function missing_iguide_assets_stay_json_so_the_viewer_does_not_embed_html(): void
    {
        $this->get('/api/g/notAReal1/tour/assets/app.js')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    #[Test]
    public function a_tampered_hmac_viewer_link_shows_the_blueprint_page(): void
    {
        Config::set('app.frontend_url', 'https://repro.test');
        Config::set('short_links.types.iguide_offline_viewer', false);

        $this->get('/api/iguide/offline-view/12/34/1700000000/'.str_repeat('ab', 32).'/tour/index.html')
            ->assertForbidden()
            ->assertSee('This page is under a different plan', false)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    #[Test]
    public function a_revoked_share_short_link_shows_the_blueprint_page(): void
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

        $path = (string) parse_url(app(ShootShareLinkService::class)->buildPublicShareUrl($link), PHP_URL_PATH);

        $this->get($path)
            ->assertNotFound()
            ->assertSee('This page is under a different plan', false);
    }

    #[Test]
    public function blocked_private_storage_paths_show_the_blueprint_page(): void
    {
        Config::set('app.frontend_url', 'https://repro.test');

        $this->get('/storage/shoots/hidden.jpg')
            ->assertNotFound()
            ->assertSee('This page is under a different plan', false);
    }
}
