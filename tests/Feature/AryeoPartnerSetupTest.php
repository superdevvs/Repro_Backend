<?php

namespace Tests\Feature;

use App\Http\Controllers\API\AryeoPartnerSetupController as Setup;
use App\Models\AryeoConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AryeoPartnerSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Queue::fake();
    }

    public function test_guests_can_share_registration_details_without_signing_in(): void
    {
        $this->get(Setup::SETUP_PATH)->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Zillow / Aryeo integration')
            ->assertSee(Setup::ORIGIN.Setup::CALLBACK_PATH, false)
            ->assertSee('Awaiting partner configuration')
            ->assertSee('Authorization and token exchange are disabled.')
            ->assertSee('Copy callback URL')
            ->assertDontSee('No authorization code was processed');

        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_registration_is_fixed_public_metadata_and_explicitly_not_a_connection(): void
    {
        config()->set('services.aryeo.client_secret', 'private-test-secret-never-public');
        config()->set('services.aryeo.client_id', 'private-test-client-never-public');
        $response = $this->getJson(Setup::SETUP_PATH.'.json?client_secret=untrusted-input')
            ->assertOk()
            ->assertJsonPath('application_name', 'RePro Dashboard')
            ->assertJsonPath('application_url', Setup::ORIGIN)
            ->assertJsonPath('redirect_uri', Setup::ORIGIN.Setup::CALLBACK_PATH)
            ->assertJsonPath('status', 'awaiting_partner_configuration')
            ->assertJsonPath('callback_reserved', true)
            ->assertJsonPath('oauth_connection_enabled', false)
            ->assertJsonPath('token_exchange_enabled', false)
            ->assertJsonCount(6, 'required_partner_details')
            ->assertJsonMissingPath('client_secret')
            ->assertJsonMissingPath('authorization_endpoint')
            ->assertJsonMissingPath('token_endpoint');

        foreach (['private-test-secret-never-public', 'private-test-client-never-public', 'untrusted-input'] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_host_headers_and_return_parameters_cannot_change_the_registered_identifiers(): void
    {
        config()->set('app.url', 'https://development.example');
        $this->withHeaders(['Host' => 'untrusted.example', 'X-Forwarded-Host' => 'untrusted.example'])
            ->getJson(Setup::SETUP_PATH.'.json?redirect_uri=https://untrusted.example/callback')
            ->assertOk()->assertJsonPath('redirect_uri', Setup::ORIGIN.Setup::CALLBACK_PATH)
            ->assertJsonPath('setup_url', Setup::ORIGIN.Setup::SETUP_PATH)
            ->assertDontSee('untrusted.example');

        $this->get(Setup::CALLBACK_PATH.'?return_to=https://untrusted.example&redirect_uri=https://untrusted.example')
            ->assertStatus(303)
            ->assertRedirect(Setup::ORIGIN.Setup::SETUP_PATH.'?callback=not_configured');
    }

    public function test_callbacks_never_link_accounts_or_exchange_or_reflect_supplied_codes(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $connection = AryeoConnection::create([
            'name' => 'Existing connector', 'company_key' => 'existing-company',
            'token_hash' => hash('sha256', 'existing-worker-secret-not-for-oauth'),
            'client_ids' => [], 'delivery_shoot_ids' => [],
            'enabled' => true, 'processing_enabled' => true,
        ]);
        $before = $connection->fresh()->getRawOriginal();
        $query = http_build_query([
            'code' => 'incoming-sensitive-code', 'state' => 'incoming-sensitive-state',
            'error_description' => '<script>untrusted-error</script>', 'client_id' => $user->id,
        ]);

        foreach ([false, true] as $authenticated) {
            if ($authenticated) {
                $this->actingAs($user);
            }
            $response = $this->get(Setup::CALLBACK_PATH.'?'.$query)
                ->assertStatus(303)
                ->assertRedirect(Setup::ORIGIN.Setup::SETUP_PATH.'?callback=not_configured')
                ->assertHeader('Referrer-Policy', 'no-referrer');
            foreach (['incoming-sensitive-code', 'incoming-sensitive-state', 'untrusted-error'] as $secret) {
                $response->assertDontSee($secret);
                $this->assertStringNotContainsString($secret, json_encode($response->headers->all()));
            }
        }

        $this->assertDatabaseCount('aryeo_connections', 1);
        $this->assertSame($before, $connection->fresh()->getRawOriginal());
        $this->assertDatabaseCount('aryeo_jobs', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_invalid_and_denied_callbacks_always_show_disabled_state_without_reflection(): void
    {
        foreach (['', '?error=access_denied', '?code%5Bvalue%5D=code&state%5B%5D=state'] as $query) {
            $this->get(Setup::CALLBACK_PATH.$query)->assertStatus(303)
                ->assertRedirect(Setup::ORIGIN.Setup::SETUP_PATH.'?callback=not_configured');
        }
        $this->get(Setup::SETUP_PATH.'?callback=not_configured')->assertOk()
            ->assertSee('No authorization code was processed and no account was linked.');
        $this->get(Setup::SETUP_PATH.'?callback%5B%5D=%3Cscript%3Ereflected-input%3C/script%3E')
            ->assertOk()->assertDontSee('reflected-input');
    }

    public function test_public_pages_have_strict_headers_and_matching_unique_nonces(): void
    {
        $first = $this->get(Setup::SETUP_PATH)->assertOk();
        $second = $this->get(Setup::SETUP_PATH)->assertOk();
        foreach ([$first, $second, $this->getJson(Setup::SETUP_PATH.'.json'), $this->get(Setup::CALLBACK_PATH)] as $response) {
            $response->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringNotContainsString('unsafe-inline', $csp);
        }
        preg_match('/<style nonce="([^"]+)"/', $first->getContent(), $style);
        $this->assertNotEmpty($style[1]);
        $first->assertSee('<script nonce="'.$style[1].'">', false);
        $this->assertStringContainsString("'nonce-{$style[1]}'", $first->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString($style[1], $second->getContent());
    }

    public function test_registration_does_not_enable_unregistered_methods_or_bypass_worker_authentication(): void
    {
        $this->postJson(Setup::CALLBACK_PATH, ['code' => 'never-exchanged'])->assertStatus(405);
        $this->postJson(Setup::SETUP_PATH, [])->assertStatus(405);
        $this->getJson('/api/integrations/aryeo/v1/shoots')->assertUnauthorized();
        Http::assertNothingSent();
    }
}
