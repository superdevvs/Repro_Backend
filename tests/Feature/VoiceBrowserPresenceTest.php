<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceBrowserSession;
use App\Services\Voice\VoiceBrowserSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoiceBrowserPresenceTest extends TestCase
{
    use RefreshDatabase;

    private VoiceBrowserSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telnyx.api_key' => 'test-key', 'services.telnyx.voice.credential_connection_id' => 'browser-connection']);
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'sanctum');
        $this->session = VoiceBrowserSession::create([
            'user_id' => $user->id, 'device_id' => (string) Str::uuid(), 'credential_id' => 'owned-credential',
            'sip_username' => 'gencredOwned', 'status' => 'ready', 'expires_at' => now()->addHour(),
        ]);
    }

    private function heartbeat(array $data = ['transport_connected' => true])
    {
        return $this->postJson('/api/voice/browser/sessions/'.$this->session->id.'/heartbeat', $data);
    }

    public function test_provider_confirmation_uses_owned_identity_and_bounded_request_then_caches_for_only_ten_seconds(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('telephony_credential', $request['credential_type']);
            $this->assertSame('gencredOwned', $request['username']);
            $this->assertSame(3, $options['timeout']);

            return Http::response(['registered' => true, 'sip_registration_status' => 'registered', 'connection_id' => 'browser-connection']);
        });
        $this->getJson('/api/voice/browser/config')->assertOk()->assertJsonPath('presence_verification', 'provider');
        $response = $this->heartbeat()->assertOk()->assertJsonPath('registered', true);
        $this->assertStringNotContainsString('gencredOwned', $response->getContent());
        $this->assertStringNotContainsString('owned-credential', $response->getContent());
        $this->heartbeat()->assertOk()->assertJsonPath('registered', true);
        Http::assertSentCount(1);
        $this->travel(11)->seconds();
        $this->heartbeat()->assertOk()->assertJsonPath('registered', true);
        Http::assertSentCount(2);
        $this->assertTrue(app(VoiceBrowserSessionService::class)->usable($this->session->fresh()));
    }

    public function test_false_transport_wins_over_legacy_registered_and_never_calls_provider(): void
    {
        $this->session->update(['registered' => true]);
        Http::fake();
        $this->heartbeat(['transport_connected' => false, 'registered' => true])->assertOk()->assertJsonPath('registered', false);
        Http::assertNothingSent();
        $this->assertFalse($this->session->fresh()->registered);
    }

    public function test_legacy_true_hint_also_requires_provider_confirmation(): void
    {
        Http::fake(fn () => Http::response(['registered' => false, 'sip_registration_status' => 'failed']));
        $this->heartbeat(['registered' => true])->assertOk()->assertJsonPath('registered', false);
        Http::assertSentCount(1);
    }

    public function test_unknown_mismatched_and_untyped_provider_results_fail_closed(): void
    {
        foreach ([[], ['registered' => true, 'sip_registration_status' => 'unknown'],
            ['registered' => 'true', 'sip_registration_status' => 'registered'],
            ['registered' => true, 'sip_registration_status' => 'registered', 'connection_id' => 'different-connection'],
            ['registered' => true, 'sip_registration_status' => 'registered', 'credential_type' => 'sip_credential_connection'],
            ['registered' => true, 'sip_registration_status' => 'registered', 'credential_username' => 'gencredOther']] as $state) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(fn () => Http::response($state));
            $this->heartbeat(['transport_connected' => false])->assertOk();
            $this->heartbeat()->assertOk()->assertJsonPath('registered', false);
        }
    }

    public function test_provider_http_failure_and_network_timeout_clear_availability(): void
    {
        $this->session->update(['registered' => true]);
        Http::fake(fn () => Http::response([], 503));
        $this->heartbeat()->assertOk()->assertJsonPath('registered', false);
        $this->assertFalse($this->session->fresh()->registered);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $this->heartbeat()->assertOk()->assertJsonPath('registered', false);
    }

    public function test_revocation_during_provider_io_cannot_restore_registration(): void
    {
        Http::fake(function () {
            $this->session->update(['revoked_at' => now(), 'status' => 'revocation_pending', 'registered' => false]);

            return Http::response(['registered' => true, 'sip_registration_status' => 'registered']);
        });
        $this->heartbeat()->assertConflict();
        $this->assertFalse($this->session->fresh()->registered);
        $this->assertSame('revocation_pending', $this->session->fresh()->status);
    }

    public function test_later_disconnect_during_provider_io_wins_over_positive_result(): void
    {
        Http::fake(function () {
            app(VoiceBrowserSessionService::class)->heartbeat($this->session->fresh(), false, false);

            return Http::response(['registered' => true, 'sip_registration_status' => 'registered']);
        });
        $this->heartbeat()->assertOk()->assertJsonPath('registered', false);
        $this->assertFalse($this->session->fresh()->registered);
    }

    public function test_expired_failed_or_revoked_sessions_never_query_the_carrier(): void
    {
        Http::fake();
        $this->session->update(['status' => 'failed']);
        $this->heartbeat()->assertConflict();
        $this->session->update(['status' => 'ready', 'expires_at' => now()->subSecond()]);
        $this->heartbeat()->assertConflict();
        $this->session->update(['expires_at' => now()->addHour(), 'revoked_at' => now()]);
        $this->heartbeat()->assertConflict();
        Http::assertNothingSent();
    }
}
