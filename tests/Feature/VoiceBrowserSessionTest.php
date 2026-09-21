<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceBrowserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceBrowserSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telnyx.api_key' => 'test-key', 'services.telnyx.voice.browser_enabled' => true,
            'services.telnyx.voice.credential_connection_id' => 'browser-connection', 'services.telnyx.voice.connection_id' => 'server-app',
            'services.telnyx.voice.webhook_url' => 'https://example.test/voice']);
        Http::fake(function ($r) {
            if (str_ends_with($r->url(), '/token')) {
                return Http::response('header.payload.signature', 201, ['Content-Type' => 'text/plain']);
            }

            return Http::response(['data' => ['id' => 'credential-one', 'sip_username' => 'gencredSecure', 'sip_password' => 'must-not-leak']]);
        });
    }

    public function test_ephemeral_registration_and_refresh_never_expose_sip_secret_and_extend_same_identity(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $device = (string) Str::uuid();
        $response = $this->postJson('/api/voice/browser/sessions', ['device_id' => $device])->assertOk()
            ->assertJsonPath('token', 'header.payload.signature')->assertJsonPath('registration_delay_ms', 5000);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
        $this->assertStringNotContainsString('gencredSecure', $response->getContent());
        $session = VoiceBrowserSession::findOrFail($response->json('id'));
        $this->assertLessThanOrEqual(3600, now()->diffInSeconds($session->expires_at));
        $this->travel(45)->minutes();
        $this->postJson('/api/voice/browser/sessions/'.$session->id.'/token', ['device_id' => $device])->assertOk()->assertJsonPath('id', $session->id);
        $this->assertGreaterThan(now()->addMinutes(59), $session->fresh()->expires_at);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/telephony_credentials/credential-one') && filled($r['expires_at']));
        $this->getJson('/api/voice/browser/sessions/'.$session->id)->assertOk()->assertJsonMissingPath('token');
        $this->postJson('/api/voice/browser/sessions/'.$session->id.'/heartbeat', ['registered' => true])->assertOk()->assertJsonPath('registered', true);
        $this->deleteJson('/api/voice/browser/sessions/'.$session->id)->assertOk();
        $this->postJson('/api/voice/browser/sessions/'.$session->id.'/token', ['device_id' => $device])->assertConflict();
    }

    public function test_supervisor_without_operate_can_connect_but_view_only_user_cannot_mint_credential(): void
    {
        $supervisor = User::factory()->photographer()->create(['permission_overrides' => ['allow' => ['voice-calls-view', 'voice-calls-supervise'], 'deny' => ['voice-calls-operate']]]);
        $this->actingAs($supervisor, 'sanctum');
        $this->getJson('/api/voice/browser/config')->assertOk()->assertJsonPath('capabilities.monitor', true)->assertJsonPath('capabilities.human_outbound', false);
        $response = $this->postJson('/api/voice/browser/sessions', ['device_id' => (string) Str::uuid()])->assertOk();
        $id = $response->json('id');
        $viewer = User::factory()->photographer()->create(['permission_overrides' => ['allow' => ['voice-calls-view'], 'deny' => []]]);
        $this->actingAs($viewer, 'sanctum');
        $this->postJson('/api/voice/browser/sessions', ['device_id' => (string) Str::uuid()])->assertForbidden();
        $this->getJson('/api/voice/browser/sessions/'.$id)->assertForbidden();
        $this->postJson('/api/voice/browser/sessions/'.$id.'/token', ['device_id' => $response->json('device_id')])->assertForbidden();
        $this->deleteJson('/api/voice/browser/sessions/'.$id)->assertForbidden();
        $this->assertDatabaseCount('voice_browser_sessions', 1);
    }

    public function test_disabled_browser_phone_returns_capability_blocker_without_provider_calls(): void
    {
        config(['services.telnyx.voice.browser_enabled' => false]);
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $this->getJson('/api/voice/browser/config')->assertOk()->assertJsonPath('ready', false);
        $this->postJson('/api/voice/browser/sessions', ['device_id' => (string) Str::uuid()])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_failed_provider_revocation_blocks_local_use_immediately_and_reconciles_later(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $device = (string) Str::uuid();
        $response = $this->postJson('/api/voice/browser/sessions', ['device_id' => $device])->assertOk();
        $session = VoiceBrowserSession::findOrFail($response->json('id'));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fakeSequence()->push([], 503)->push([], 204);
        $this->deleteJson('/api/voice/browser/sessions/'.$session->id)->assertStatus(502);
        $this->assertSame('revocation_pending', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertFalse($session->fresh()->registered);
        $this->postJson('/api/voice/browser/sessions/'.$session->id.'/token', ['device_id' => $device])->assertConflict();
        $this->postJson('/api/voice/browser/sessions/'.$session->id.'/heartbeat', ['registered' => true])->assertConflict();
        $this->artisan('voice-browser:reconcile')->assertSuccessful();
        $this->assertSame('revoked', $session->fresh()->status);
        Http::assertSentCount(2);
        $this->artisan('voice-browser:reconcile')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_already_absent_provider_credential_is_successfully_revoked(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum');
        $response = $this->postJson('/api/voice/browser/sessions', ['device_id' => (string) Str::uuid()])->assertOk();
        $session = VoiceBrowserSession::findOrFail($response->json('id'));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response([], 404)]);
        $this->deleteJson('/api/voice/browser/sessions/'.$session->id)->assertOk();
        $this->assertSame('revoked', $session->fresh()->status);
        $this->deleteJson('/api/voice/browser/sessions/'.$session->id)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_hangup_failure_during_disconnect_still_revokes_the_browser_credential(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'sanctum');
        $device = (string) Str::uuid();
        $response = $this->postJson('/api/voice/browser/sessions', ['device_id' => $device])->assertOk();
        $session = VoiceBrowserSession::findOrFail($response->json('id'));
        $call = \App\Models\VoiceCall::create(['provider' => 'telnyx', 'direction' => 'OUTBOUND', 'status' => 'dialing', 'from_phone' => '+12025550100', 'to_phone' => '+12025550200']);
        $browser = \App\Models\VoiceBrowserCall::create(['voice_call_id' => $call->id, 'owner_id' => $user->id, 'session_id' => $session->id,
            'mode' => 'human_outbound', 'idempotency_key' => 'disconnect-test', 'request_hash' => hash('sha256', 'disconnect')]);
        $browser->legs()->create(['role' => 'agent', 'session_id' => $session->id, 'user_id' => $user->id,
            'call_control_id' => 'disconnect-agent', 'client_state' => base64_encode('disconnect-agent'), 'destination' => 'sip:gencredSecure@sip.telnyx.com']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn ($r) => Http::response([], $r->method() === 'DELETE' ? 204 : 503));
        $this->deleteJson('/api/voice/browser/sessions/'.$session->id)->assertStatus(502);
        $this->assertSame('revoked', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->postJson('/api/voice/browser/sessions/'.$session->id.'/token', ['device_id' => $device])->assertConflict();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/telephony_credentials/credential-one'));
    }
}
