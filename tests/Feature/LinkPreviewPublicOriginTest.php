<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LinkPreviewPublicOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://api.reprodashboard.com',
            'link_preview.enabled' => true,
            'link_preview.frontend_url' => 'https://reprodashboard.com',
        ]);
        URL::forceRootUrl('https://api.reprodashboard.com');
        URL::forceScheme('https');
    }

    #[Test]
    public function dashboard_og_image_urls_use_the_public_origin_not_the_api_host(): void
    {
        $metadata = $this->getJson('/api/public/link-previews/dashboard')->assertOk()->json();

        $imageUrl = $metadata['image']['url'] ?? '';
        $this->assertIsString($imageUrl);
        $this->assertStringStartsWith('https://reprodashboard.com/', $imageUrl);
        $this->assertStringContainsString('/api/public/link-previews/', $imageUrl);
        $this->assertStringNotContainsString('api.reprodashboard.com', $imageUrl);
    }

    #[Test]
    public function prerendered_preview_document_does_not_advertise_the_api_host(): void
    {
        $html = $this->get('/link-preview/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/property="og:image" content="https:\/\/reprodashboard\.com\/api\/public\/link-previews\/[^"]+"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="twitter:image" content="https:\/\/reprodashboard\.com\/api\/public\/link-previews\/[^"]+"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?:property="og:image"|name="twitter:image") content="https:\/\/api\.reprodashboard\.com/',
            $html
        );
    }
}
