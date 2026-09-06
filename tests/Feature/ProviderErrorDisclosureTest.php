<?php

namespace Tests\Feature;

use App\Services\BrightMlsService;
use App\Services\HiggsFieldService;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderErrorDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_higgsfield_failure_retains_status_but_not_echoed_secrets(): void
    {
        config(['services.higgsfield.api_key' => 'fake-key', 'services.higgsfield.api_secret' => 'fake-secret']);
        Http::fake(['*' => Http::response([
            'status' => 'failed', 'error' => 'sensitive-provider-echo',
            'debug' => 'sensitive-provider-echo',
        ])]);
        $result = app(HiggsFieldService::class)->getRequestStatus('request-123');
        $this->assertSame('failed', $result['status']);
        $this->assertSame('request-123', $result['request_id']);
        $this->assertNotEmpty($result['error']);
        $this->assertStringNotContainsString('sensitive-provider-echo', json_encode($result));
    }

    public function test_cakemail_connection_error_does_not_return_provider_body(): void
    {
        config(['services.cakemail.username' => 'example@example.com', 'services.cakemail.password' => 'fake-password', 'services.cakemail.base_url' => 'https://mail.example.com']);
        $provider = new class extends CakemailProvider {
            public function getAccessToken(): ?string { return 'fake-token'; }
        };
        Http::fake(['*' => Http::response('sensitive-provider-echo', 500)]);
        $result = $provider->testConnection();
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('sensitive-provider-echo', json_encode($result));
    }

    public function test_connection_exception_is_useful_without_technical_message(): void
    {
        config(['services.higgsfield.api_key' => 'fake-key', 'services.higgsfield.api_secret' => 'fake-secret']);
        Http::fake(fn () => throw new \RuntimeException('sensitive-provider-echo'));
        $result = app(HiggsFieldService::class)->testConnection();
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['message']);
        $this->assertStringNotContainsString('sensitive-provider-echo', json_encode($result));
    }

    public function test_bright_mls_upstream_rejection_does_not_echo_provider_validation(): void
    {
        config([
            'services.bright_mls.enabled' => true, 'services.bright_mls.api_mode' => 'legacy',
            'services.bright_mls.environment' => 'p1', 'services.bright_mls.vendor_id' => 'vendor-test',
            'services.bright_mls.api_key' => 'fake-key', 'services.bright_mls.api_user' => 'vendor-test',
        ]);
        Http::fake(['*' => Http::response([
            'message' => 'sensitive-provider-echo',
            'error' => [['path' => ['apiKey'], 'message' => 'sensitive-provider-echo']],
        ], 401)]);
        $service = app(BrightMlsService::class);
        $manifest = $service->buildManifestFromShoot([
            'address' => '12 Oak Street', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201', 'mls_id' => 'MLS-123',
        ], ['photos' => [[
            'url' => 'https://cdn.example.com/photo.jpg', 'filename' => 'photo.jpg', 'selected' => true,
        ]]]);
        $result = $service->publishManifest($manifest);
        Http::assertSent(fn () => true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Authentication failed', $result['error']);
        $this->assertSame([], $result['validation_errors']);
        $this->assertStringNotContainsString('sensitive-provider-echo', json_encode($result));
    }
}
