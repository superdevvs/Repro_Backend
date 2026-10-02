<?php

namespace Tests\Feature;

use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CubiCasaWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
        config()->set('services.cubicasa.webhook_url', 'https://api.reprodashboard.com/cubicasa_webhook.php');
        config()->set('app.url', 'https://api.reprodashboard.com');
    }

    public function test_register_webhook_sends_v3_webhook_urls_and_triggers(): void
    {
        Http::fake([
            self::BASE_URL . '/companies/webhook' => Http::response([
                'webhook_urls' => ['https://api.reprodashboard.com/cubicasa_webhook.php'],
                'webhook_triggers' => CubiCasaService::WEBHOOK_TRIGGERS,
            ], 200),
        ]);

        $result = app(CubiCasaService::class)->registerWebhook();

        $this->assertTrue($result['ok']);
        $this->assertSame('https://api.reprodashboard.com/cubicasa_webhook.php', $result['url']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PATCH' || $request->url() !== self::BASE_URL . '/companies/webhook') {
                return false;
            }

            $body = $request->data();

            return ($body['webhook_urls'] ?? null) === ['https://api.reprodashboard.com/cubicasa_webhook.php']
                && ($body['webhook_triggers'] ?? null) === CubiCasaService::WEBHOOK_TRIGGERS
                && !array_key_exists('url', $body)
                && !array_key_exists('secret', $body);
        });
    }
}
