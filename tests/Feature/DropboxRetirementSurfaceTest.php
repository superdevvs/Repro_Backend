<?php

namespace Tests\Feature;

use App\Models\OauthToken;
use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxTokenService;
use App\Services\UploadSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IsolatedSecurityTestCase;

class DropboxRetirementSurfaceTest extends IsolatedSecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        // Stale configuration or historical token rows must not re-enable routes.
        config(['services.dropbox' => [
            'enabled' => true, 'client_id' => 'retired-app', 'client_secret' => 'retired-secret',
            'access_token' => 'retired-access', 'refresh_token' => 'retired-refresh',
        ]]);
    }

    public function test_retired_routes_are_absent_for_guests_and_administrators(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $routes = [
            ['GET', '/api/dropbox/config'], ['POST', '/api/dropbox/connect'],
            ['POST', '/api/dropbox/disconnect'], ['GET', '/api/dropbox/callback'],
            ['GET', '/api/dropbox/webhook'], ['POST', '/api/dropbox/webhook'],
            ['GET', '/api/dropbox/browse'], ['GET', '/api/integrations/dropbox/status'],
            ['POST', "/api/shoots/{$shoot->id}/copy-from-dropbox"],
            ['POST', "/api/shoots/{$shoot->id}/archive"],
            ['GET', '/api/upload-sources/dropbox/callback'],
            ['POST', '/api/upload-sources/dropbox/connect'],
            ['DELETE', '/api/upload-sources/dropbox'], ['GET', '/api/upload-sources/dropbox/items'],
        ];
        foreach ([null, $admin] as $actor) {
            if ($actor) { Sanctum::actingAs($actor); }
            foreach ($routes as [$method, $path]) {
                $response = $this->json($method, $path);
                $this->assertContains($response->getStatusCode(), [404, 405], $method.' '.$path);
                $response->assertJsonMissingPath('auth_url');
            }
        }
        Http::assertNothingSent();
    }

    public function test_old_tokens_do_not_restore_upload_source_or_integration_controls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        OauthToken::create([
            'provider' => 'dropbox', 'user_id' => $admin->id, 'account_type' => 'personal',
            'access_token' => 'historical-test-token', 'refresh_token' => 'historical-test-refresh',
        ]);
        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/upload-sources')->assertOk();
        $response->assertJsonMissingPath('providers.dropbox');
        foreach (['google_drive', 'google_photos', 'onedrive'] as $provider) {
            $response->assertJsonPath('providers.'.$provider.'.provider', $provider);
        }
        $this->postJson('/api/integrations/test-connection', ['service' => 'dropbox'])
            ->assertUnprocessable()->assertJsonValidationErrors('service');
        foreach (['providerStatus', 'buildAuthorizationUrl', 'listItems'] as $method) {
            try {
                app(UploadSourceService::class)->{$method}('dropbox', $admin);
                $this->fail('Retired provider was accepted by '.$method);
            } catch (\RuntimeException $exception) {
                $this->assertSame('Unsupported upload source.', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_new_albums_are_local_and_cannot_claim_the_retired_provider(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $shoot = Shoot::factory()->create();
        $this->postJson("/api/shoots/{$shoot->id}/albums", ['source' => 'dropbox'])
            ->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->postJson("/api/shoots/{$shoot->id}/albums", ['source' => 'local', 'folder_path' => 'shoots/local-album'])
            ->assertCreated()->assertJsonPath('data.source', 'local');
        $this->assertDatabaseMissing('shoot_media_albums', ['shoot_id' => $shoot->id, 'source' => 'dropbox']);
        Http::assertNothingSent();
    }

    public function test_paused_recovery_seeder_token_compatibility_fails_locally(): void
    {
        try {
            (new DropboxTokenService())->getValidAccessToken();
            $this->fail('Retired token access was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Dropbox integration has been retired.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }
}
