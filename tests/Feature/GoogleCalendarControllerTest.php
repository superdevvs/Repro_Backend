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
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('google_calendar_connections', [
            'user_id' => $this->photographer->id,
        ]);
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
}
