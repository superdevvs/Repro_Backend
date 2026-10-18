<?php

namespace Tests\Feature;

use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CubiCasaWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', 'https://cubicasa.test');
        config()->set('services.cubicasa.webhook_url', 'https://reprodashboard.com/cubicasa_webhook.php');
        Http::preventStrayRequests();
    }

    public function test_registers_v3_arrays_preserves_other_consumers_and_verifies_saved_configuration(): void
    {
        $url = 'https://reprodashboard.com/cubicasa_webhook.php';
        Http::fake([
            'https://cubicasa.test/companies/info' => Http::sequence()
                ->push(['configs' => ['webhook_urls' => ['https://other.example/webhook'], 'webhook_triggers' => ['moved_to_draft']]])
                ->push(['configs' => ['webhook_urls' => ['https://other.example/webhook', $url], 'webhook_triggers' => ['moved_to_ready']]]),
            'https://cubicasa.test/companies/webhook' => Http::response([], 200),
        ]);
        $this->assertTrue(app(CubiCasaService::class)->registerWebhook()['ok']);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['webhook_urls'] === ['https://other.example/webhook', $url]
            && $request['webhook_triggers'] === ['moved_to_draft', 'moved_to_ready', 'moved_to_fixing', 'moved_to_pending']
            && !isset($request['url']) && !isset($request['secret']));
    }

    public function test_does_not_replace_webhooks_when_existing_configuration_cannot_be_read(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertFalse(app(CubiCasaService::class)->registerWebhook()['ok']);
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_success_response_alone_does_not_claim_registration_succeeded(): void
    {
        Http::fake(['*' => Http::response(['configs' => ['webhook_urls' => []]], 200)]);
        $this->assertFalse(app(CubiCasaService::class)->registerWebhook()['ok']);
    }
}
