<?php

namespace Tests\Feature;

use App\Models\StudioProviderSetting;
use App\Models\User;
use App\Services\Studio\StudioProviderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudioProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config([
            'studio.client_access_enabled' => false,
            'services.fal.key' => 'fal-fixture-secret',
            'services.openai.api_key' => 'openai-fixture-secret',
            'studio_providers.fotello.api_key' => null,
            'studio_providers.fotello.team_id' => null,
        ]);
    }

    public function test_only_primary_superadmin_can_read_and_save_provider_settings(): void
    {
        $this->getJson('/api/studio/provider-settings')->assertUnauthorized();
        foreach (['admin', 'editing_manager', 'editor', 'client'] as $role) {
            foreach ([[], ['superadmin']] as $secondary) {
                $this->actor($role, $secondary);
                $this->getJson('/api/studio/provider-settings')->assertForbidden();
                $this->putJson('/api/studio/provider-settings', ['credentials' => ['fotello' => ['apiKey' => 'not-authorized']]])->assertForbidden();
            }
        }
        $this->assertSame(0, StudioProviderSetting::count());
        $this->actor('superadmin');
        $this->getJson('/api/studio/provider-settings')->assertOk()->assertJsonCount(14, 'data.services');
        $this->putJson('/api/studio/provider-settings', [])->assertOk();
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_write_only_and_absent_from_responses_and_logs(): void
    {
        $this->actor('superadmin');
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        $key = 'fotello-fixture-confidential-key';
        $team = 'team-fixture-confidential-id';
        $saved = $this->putJson('/api/studio/provider-settings', ['credentials' => ['fotello' => ['apiKey' => $key, 'teamId' => $team]]])
            ->assertOk()->assertJsonPath('data.credentials.fotello.keyConfigured', true)->assertJsonPath('data.credentials.fotello.teamIdConfigured', true);
        $loaded = $this->getJson('/api/studio/provider-settings')->assertOk();
        $raw = DB::table('studio_provider_settings')->value('payload');
        foreach ([$saved->getContent(), $loaded->getContent(), $raw, json_encode($logs)] as $value) {
            $this->assertStringNotContainsString($key, $value);
            $this->assertStringNotContainsString($team, $value);
            $this->assertStringNotContainsString('fal-fixture-secret', $value);
            $this->assertStringNotContainsString('openai-fixture-secret', $value);
        }
        $this->assertStringContainsString('no-store', $loaded->headers->get('Cache-Control'));
        $stored = StudioProviderSetting::findOrFail(1);
        $this->assertSame($key, $stored->payload['credentials']['fotello']['apiKey']);
        $this->assertArrayNotHasKey('payload', $stored->toArray());
        Http::assertNothingSent();
    }

    public function test_credentials_only_and_blank_secret_save_preserve_existing_routes_and_key(): void
    {
        $this->actor('superadmin');
        $route = ['id' => 'listing-ready', 'provider' => 'openai', 'model' => 'gpt-image-2'];
        $this->putJson('/api/studio/provider-settings', ['services' => [$route]])->assertOk();
        $before = app(StudioProviderSettings::class)->route('listing-ready');
        $this->putJson('/api/studio/provider-settings', ['credentials' => ['fotello' => ['apiKey' => 'saved-fixture', 'teamId' => 'team-fixture']]])->assertOk();
        $this->putJson('/api/studio/provider-settings', ['credentials' => ['fotello' => ['apiKey' => '', 'teamId' => null]]])->assertOk();
        $this->assertSame($before, app(StudioProviderSettings::class)->route('listing-ready'));
        $this->assertSame('saved-fixture', app(StudioProviderSettings::class)->credentials('fotello')['api_key']);
        $this->assertSame('team-fixture', app(StudioProviderSettings::class)->credentials('fotello')['team_id']);
    }

    #[DataProvider('invalidRoutes')]
    public function test_invalid_routes_are_rejected_atomically(array $route): void
    {
        $this->actor('superadmin');
        $this->putJson('/api/studio/provider-settings', ['credentials' => ['fotello' => ['apiKey' => 'must-not-survive']], 'services' => [$route]])->assertUnprocessable();
        $this->assertSame(0, StudioProviderSetting::count());
        Http::assertNothingSent();
    }

    public static function invalidRoutes(): array
    {
        return [
            [['id' => 'listing-ready', 'provider' => 'untrusted', 'model' => 'model']],
            [['id' => 'listing-ready', 'provider' => 'openai', 'model' => 'unapproved-model']],
            [['id' => 'not-a-service', 'provider' => 'openai', 'model' => 'gpt-image-2']],
            [['id' => 'listing-ready', 'provider' => 'openai', 'model' => 'gpt-image-2', 'fallback' => ['provider' => 'fal', 'model' => 'fal-ai/flux-kontext/dev']]],
            [['id' => 'outpaint', 'provider' => 'fal', 'model' => 'fal-ai/flux-2-pro/outpaint', 'fallback' => ['provider' => 'openai', 'model' => 'unapproved-model']]],
        ];
    }

    #[DataProvider('missingTeamIds')]
    public function test_missing_or_placeholder_team_id_can_be_saved_without_activating_fotello(?string $teamId): void
    {
        $this->actor('superadmin');
        $before = app(StudioProviderSettings::class)->route('listing-ready');
        $this->putJson('/api/studio/provider-settings', ['credentials' => ['fotello' => ['apiKey' => 'saved-fixture', 'teamId' => $teamId]]])
            ->assertOk()->assertJsonPath('data.credentials.fotello.keyConfigured', true)->assertJsonPath('data.credentials.fotello.teamIdConfigured', false);
        $this->putJson('/api/studio/provider-settings', ['services' => [['id' => 'listing-ready', 'provider' => 'fotello', 'model' => 'enhance']]])->assertUnprocessable();
        $this->assertSame($before, app(StudioProviderSettings::class)->route('listing-ready'));
        Http::assertNothingSent();
    }

    public static function missingTeamIds(): array
    {
        return [[null], [''], ['pending'], ['TBD'], ['your-team-id']];
    }

    public function test_unverified_fotello_variant_retrieval_stays_unavailable_even_with_credentials(): void
    {
        $this->actor('superadmin');
        $this->putJson('/api/studio/provider-settings', ['credentials' => ['fotello' => ['apiKey' => 'saved-fixture', 'teamId' => 'team-fixture']]])->assertOk();
        foreach ([['twilight', 'twilight'], ['virtual-staging', 'virtual_staging'], ['revision', 'pro'], ['upscale', 'upscale']] as [$id, $model]) {
            $this->putJson('/api/studio/provider-settings', ['services' => [['id' => $id, 'provider' => 'fotello', 'model' => $model]]])->assertUnprocessable();
        }
        $this->putJson('/api/studio/provider-settings', ['services' => [['id' => 'listing-ready', 'provider' => 'fotello', 'model' => 'enhance']]])->assertOk();
        Http::assertNothingSent();
    }

    public function test_staff_capabilities_expose_no_provider_brand_models_or_credentials(): void
    {
        $this->getJson('/api/studio/workspaces/capabilities')->assertUnauthorized();
        foreach (['superadmin', 'admin', 'editing_manager', 'editor'] as $role) {
            $this->actor($role);
            $response = $this->getJson('/api/studio/workspaces/capabilities')->assertOk()
                ->assertJsonPath('data.outpaint.ready', true)->assertJsonPath('data.upscale.ready', true);
            $body = strtolower($response->getContent());
            foreach (['fotello', 'openai', 'fal-ai', 'fal.ai', 'gpt-image', 'nano-banana', 'api_key', 'apikey', 'provider', 'model', 'fixture-secret'] as $identifier) {
                $this->assertStringNotContainsString($identifier, $body);
            }
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_capabilities_and_upscale_remain_client_gated_before_validation(): void
    {
        foreach ([[], ['superadmin']] as $secondary) {
            $this->actor('client', $secondary);
            $this->getJson('/api/studio/workspaces/capabilities')->assertForbidden();
            $this->postJson('/api/studio/workspaces/unknown-id/upscale', [])->assertForbidden();
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_outpaint_is_ready_with_a_configured_fallback_when_the_primary_key_is_missing(): void
    {
        config(['services.fal.key' => null, 'services.openai.api_key' => 'openai-fixture-secret']);
        $this->actor('admin');
        $this->getJson('/api/studio/workspaces/capabilities')->assertOk()
            ->assertJsonPath('data.outpaint.ready', true)
            ->assertJsonPath('data.presets.listing-ready.ready', false);
        config(['services.openai.api_key' => null]);
        $this->getJson('/api/studio/workspaces/capabilities')->assertOk()->assertJsonPath('data.outpaint.ready', false);
        Http::assertNothingSent();
    }

    public function test_sensitive_unknown_fields_and_header_newlines_are_rejected(): void
    {
        $this->actor('superadmin');
        $this->putJson('/api/studio/provider-settings', ['credentials' => ['fotello' => ['apiKey' => "key\r\nInjected: value"]]])->assertUnprocessable();
        $this->putJson('/api/studio/provider-settings', ['credentials' => ['openai' => ['apiKey' => 'unapproved-secret']]])->assertUnprocessable();
        $this->putJson('/api/studio/provider-settings', ['services' => [['id' => 'listing-ready', 'provider' => 'openai', 'model' => 'gpt-image-2', 'apiKey' => 'unapproved-secret']]])->assertUnprocessable();
        $this->assertSame(0, StudioProviderSetting::count());
    }

    private function actor(string $role, array $secondary = []): User
    {
        $user = User::factory()->create(['role' => $role, 'secondary_roles' => $secondary]);
        Sanctum::actingAs($user);

        return $user;
    }
}
