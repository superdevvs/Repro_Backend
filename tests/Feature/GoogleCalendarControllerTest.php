<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $photographer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => 'http://frontend.test',
            'services.google.calendar.client_id' => 'google-client-id',
            'services.google.calendar.client_secret' => 'google-client-secret',
            'services.google.calendar.redirect' => 'http://backend.test/api/google-calendar/callback',
            'services.google.calendar.token_url' => 'https://oauth2.googleapis.com/token',
            'services.google.calendar.userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'services.google.calendar.base_url' => 'https://www.googleapis.com/calendar/v3',
            'services.google.calendar.auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'services.google.calendar.default_calendar_id' => 'primary',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'photographer@example.com',
        ]);
    }

    public function test_photographer_can_start_google_calendar_connection(): void
    {
        Sanctum::actingAs($this->photographer);

        $response = $this->postJson('/api/google-calendar/connect');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $authorizationUrl = $response->json('data.authorization_url');

        $this->assertIsString($authorizationUrl);
        $this->assertStringContainsString('accounts.google.com', $authorizationUrl);
        $this->assertStringContainsString(urlencode('http://backend.test/api/google-calendar/callback'), $authorizationUrl);
        $this->assertStringContainsString('login_hint=' . urlencode($this->photographer->email), $authorizationUrl);
    }

    public function test_non_photographers_cannot_access_google_calendar_management_routes(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/google-calendar/status')->assertUnprocessable();
        $this->deleteJson('/api/google-calendar/disconnect')->assertForbidden();
        $this->postJson('/api/google-calendar/connect')->assertUnprocessable();
    }

    public function test_owned_scope_preview_is_opt_in_and_preserves_photographer_authorization(): void
    {
        config(['services.google.calendar.scope' => 'openid email https://www.googleapis.com/auth/calendar.events']);
        Sanctum::actingAs($this->photographer);
        $normal = $this->postJson('/api/google-calendar/connect')->assertOk();
        parse_str(parse_url($normal->json('data.authorization_url'), PHP_URL_QUERY), $normalQuery);
        $this->assertSame('openid email https://www.googleapis.com/auth/calendar.events', $normalQuery['scope']);
        $preview = $this->postJson('/api/google-calendar/connect', ['owned_scope_preview' => true])->assertOk();
        parse_str(parse_url($preview->json('data.authorization_url'), PHP_URL_QUERY), $previewQuery);
        $this->assertSame('openid email https://www.googleapis.com/auth/calendar.events.owned', $previewQuery['scope']);
        $this->assertSame($this->photographer->email, $previewQuery['login_hint']);
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));
        $this->postJson('/api/google-calendar/connect', ['owned_scope_preview' => true])->assertForbidden();
    }

    public function test_admin_can_start_google_calendar_connection_for_a_selected_photographer(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/google-calendar/connect', [
            'user_id' => $this->photographer->id,
            'source' => 'availability',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $authorizationUrl = $response->json('data.authorization_url');

        $this->assertIsString($authorizationUrl);
        $this->assertStringContainsString('accounts.google.com', $authorizationUrl);
    }

    public function test_admin_can_view_google_calendar_status_for_a_selected_photographer(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
        ]);

        GoogleCalendarConnection::create([
            'user_id' => $this->photographer->id,
            'provider_email' => 'calendar-owner@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/google-calendar/status?user_id=' . $this->photographer->id)
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.user_id', $this->photographer->id)
            ->assertJsonPath('data.provider_email', 'calendar-owner@example.com');
    }

    public function test_callback_persists_connection_and_redirects_back_to_the_photographer_account_page(): void
    {
        Cache::put('google_calendar_oauth_state:test-state', [
            'user_id' => $this->photographer->id,
            'redirect_path' => '/photographer-account?tab=notifications',
        ], now()->addMinutes(10));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => 'openid email https://www.googleapis.com/auth/calendar.events.owned',
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'calendar-owner@example.com',
            ], 200),
        ]);

        $response = $this->get('/api/google-calendar/callback?code=test-code&state=test-state');

        $response->assertRedirect(
            'http://frontend.test/photographer-account?tab=notifications&' .
            http_build_query([
                'google_calendar' => 'connected',
                'message' => 'Google Calendar connected for ' . $this->photographer->name . '.',
            ])
        );

        $this->assertDatabaseHas('google_calendar_connections', [
            'user_id' => $this->photographer->id,
            'provider_email' => 'calendar-owner@example.com',
            'calendar_id' => 'primary',
            'sync_enabled' => true,
        ]);
    }

    public function test_callback_can_redirect_back_to_the_availability_page(): void
    {
        Cache::put('google_calendar_oauth_state:availability-state', [
            'user_id' => $this->photographer->id,
            'redirect_path' => '/availability',
        ], now()->addMinutes(10));

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'refresh_token' => 'google-refresh-token',
                'expires_in' => 3600,
                'scope' => 'openid email https://www.googleapis.com/auth/calendar.events',
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'calendar-owner@example.com',
            ], 200),
        ]);

        $response = $this->get('/api/google-calendar/callback?code=test-code&state=availability-state');

        $response->assertRedirect('http://frontend.test/availability?google_calendar=connected&message=' . urlencode('Google Calendar connected for ' . $this->photographer->name . '.'));
    }

    public function test_photographer_can_view_status_and_disconnect_google_calendar(): void
    {
        Sanctum::actingAs($this->photographer);

        GoogleCalendarConnection::create([
            'user_id' => $this->photographer->id,
            'provider_email' => 'calendar-owner@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
            'last_synced_at' => now(),
        ]);

        $this->getJson('/api/google-calendar/status')
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.provider_email', 'calendar-owner@example.com');

        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);

        $this->deleteJson('/api/google-calendar/disconnect')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.sync_enabled', false)
            ->assertJsonPath('data.user_id', $this->photographer->id)
            ->assertJsonPath('data.provider_email', null);

        $this->assertDatabaseMissing('google_calendar_connections', [
            'user_id' => $this->photographer->id,
        ]);

        // Status mirrors disconnect payload once connection is gone.
        $this->getJson('/api/google-calendar/status')
            ->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.sync_enabled', false);
    }

    public function test_disconnect_only_removes_the_authenticated_photographers_google_event_mappings(): void
    {
        Sanctum::actingAs($this->photographer);

        $otherPhotographer = User::factory()->create([
            'role' => 'photographer',
        ]);

        GoogleCalendarConnection::create([
            'user_id' => $this->photographer->id,
            'provider_email' => 'calendar-owner@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);

        GoogleCalendarConnection::create([
            'user_id' => $otherPhotographer->id,
            'provider_email' => 'other-calendar@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'other-access-token',
            'refresh_token' => 'other-refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);

        GoogleCalendarEventMapping::create([
            'shoot_id' => 999,
            'user_id' => $this->photographer->id,
            'calendar_id' => 'primary',
            'google_event_id' => 'own-event-id',
        ]);

        GoogleCalendarEventMapping::create([
            'shoot_id' => 999,
            'user_id' => $otherPhotographer->id,
            'calendar_id' => 'primary',
            'google_event_id' => 'other-event-id',
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events/own-event-id' => Http::response('', 204),
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);

        $this->deleteJson('/api/google-calendar/disconnect')
            ->assertOk();

        $this->assertDatabaseMissing('google_calendar_event_mappings', [
            'user_id' => $this->photographer->id,
        ]);

        $this->assertDatabaseHas('google_calendar_event_mappings', [
            'user_id' => $otherPhotographer->id,
            'google_event_id' => 'other-event-id',
        ]);
    }

    public function test_disconnect_is_idempotent_when_already_disconnected(): void
    {
        Sanctum::actingAs($this->photographer);

        Http::fake();

        $this->deleteJson('/api/google-calendar/disconnect')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.sync_enabled', false)
            ->assertJsonPath('data.user_id', $this->photographer->id);

        $this->deleteJson('/api/google-calendar/disconnect')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.connected', false);

        Http::assertNothingSent();
    }

    public function test_callback_rejects_missing_calendar_permission_without_saving_or_syncing(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Cache::put('google_calendar_oauth_state:denied-scope', [
            'user_id' => $this->photographer->id,
        ], now()->addMinutes(10));
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'identity-only-token',
                'scope' => 'openid email https://www.googleapis.com/auth/userinfo.email',
            ]),
        ]);

        $response = $this->get('/api/google-calendar/callback?code=code&state=denied-scope');
        $query = [];
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('error', $query['google_calendar']);
        $this->assertStringContainsString('Calendar permission was not granted', $query['message']);
        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $this->photographer->id]);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    public function test_callback_checks_tokeninfo_when_token_response_omits_scopes(): void
    {
        Cache::put('google_calendar_oauth_state:omitted-scope', [
            'user_id' => $this->photographer->id,
        ], now()->addMinutes(10));
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response(['scope' => 'openid email']),
        ]);
        $response = $this->get('/api/google-calendar/callback?code=code&state=omitted-scope');
        $this->assertStringContainsString('google_calendar=error', $response->headers->get('Location'));
        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $this->photographer->id]);
        Http::assertSentCount(2);
    }

    public function test_missing_permission_status_prompts_reconnection_and_shows_reviewed_message(): void
    {
        GoogleCalendarConnection::create([
            'user_id' => $this->photographer->id,
            'provider_email' => 'existing@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'sync_enabled' => false,
            'last_error' => 'Calendar permission was not granted. Please reconnect, select your intended Google account, and check "View and edit events on all your calendars" before continuing.',
        ]);
        Sanctum::actingAs($this->photographer);
        $this->getJson('/api/google-calendar/status')->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.sync_enabled', false)
            ->assertJsonPath('data.last_error', \App\Services\GoogleCalendar\GoogleCalendarService::MISSING_PERMISSION_MESSAGE);
    }

    public function test_switching_accounts_cannot_reuse_the_previous_accounts_refresh_token(): void
    {
        $this->assertRefreshTokenReconnect(false);
    }

    public function test_reconnecting_same_account_can_preserve_its_refresh_token(): void
    {
        $this->assertRefreshTokenReconnect(true);
    }

    private function assertRefreshTokenReconnect(bool $sameAccount): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        GoogleCalendarConnection::create([
            'user_id' => $this->photographer->id,
            'provider_email' => 'existing@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);
        Cache::put('google_calendar_oauth_state:reconnect', [
            'user_id' => $this->photographer->id,
        ], now()->addMinutes(10));
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access',
                'scope' => 'https://www.googleapis.com/auth/calendar.events',
            ]),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => $sameAccount ? 'existing@example.com' : 'different@example.com',
            ]),
        ]);
        $response = $this->get('/api/google-calendar/callback?code=code&state=reconnect');
        $this->assertStringContainsString('google_calendar=' . ($sameAccount ? 'connected' : 'error'), $response->headers->get('Location'));
        $connection = GoogleCalendarConnection::where('user_id', $this->photographer->id)->firstOrFail();
        $this->assertSame('existing@example.com', $connection->provider_email);
        $this->assertSame('old-refresh', $connection->refresh_token);
        $this->assertSame($sameAccount ? 'new-access' : 'old-access', $connection->access_token);
        if (!$sameAccount) {
            \Illuminate\Support\Facades\Queue::assertNothingPushed();
        }
    }

    public function test_disconnect_revokes_token_and_stops_future_sync_flag_before_cleanup(): void
    {
        Sanctum::actingAs($this->photographer);

        $connection = GoogleCalendarConnection::create([
            'user_id' => $this->photographer->id,
            'provider_email' => 'calendar-owner@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);

        GoogleCalendarEventMapping::create([
            'shoot_id' => 4242,
            'user_id' => $this->photographer->id,
            'calendar_id' => 'primary',
            'google_event_id' => 'event-to-remove',
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events/event-to-remove' => Http::response('', 204),
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);

        $this->deleteJson('/api/google-calendar/disconnect')
            ->assertOk()
            ->assertJsonPath('data.connected', false);

        $this->assertDatabaseMissing('google_calendar_connections', [
            'user_id' => $this->photographer->id,
        ]);
        $this->assertDatabaseMissing('google_calendar_event_mappings', [
            'user_id' => $this->photographer->id,
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/revoke'
                && $request['token'] === 'google-refresh-token';
        });
    }
}
