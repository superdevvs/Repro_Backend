<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GoogleCalendarConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.calendar.client_id' => 'google-client-id',
            'services.google.calendar.client_secret' => 'google-client-secret',
            'services.google.calendar.token_url' => 'https://oauth2.googleapis.com/token',
            'services.google.calendar.base_url' => 'https://www.googleapis.com/calendar/v3',
        ]);

        $user = User::factory()->create([
            'role' => 'photographer',
        ]);

        $this->connection = GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'provider_email' => 'calendar-owner@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);
    }

    public function test_it_creates_updates_and_deletes_google_calendar_events(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events/google-event-id' => Http::sequence()
                ->push(['id' => 'google-event-id'], 200)
                ->push('', 204),
            'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'google-event-id',
            ], 200),
        ]);

        $service = app(GoogleCalendarService::class);

        $created = $service->createEvent($this->connection, ['summary' => 'Created Event']);
        $updated = $service->updateEvent($this->connection, 'google-event-id', ['summary' => 'Updated Event']);
        $service->deleteEvent($this->connection, 'primary', 'google-event-id');

        $this->assertSame('google-event-id', $created['id']);
        $this->assertSame('google-event-id', $updated['id']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/calendars/primary/events')
                && ($request['summary'] ?? null) === 'Created Event';
        });

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/calendars/primary/events/google-event-id')
                && ($request['summary'] ?? null) === 'Updated Event';
        });

        Http::assertSent(function (Request $request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/calendars/primary/events/google-event-id');
        });
    }

    public function test_it_refreshes_expired_google_calendar_tokens_before_sending_event_requests(): void
    {
        $this->connection->forceFill([
            'access_token' => 'expired-access-token',
            'token_expires_at' => now()->subMinute(),
        ])->save();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'refreshed-event-id',
            ], 200),
        ]);

        $service = app(GoogleCalendarService::class);
        $service->createEvent($this->connection->fresh(), ['summary' => 'Refresh Event']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request->method() === 'POST';
        });

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/calendars/primary/events')
                && $request->hasHeader('Authorization', 'Bearer fresh-access-token');
        });

        $this->assertSame('fresh-access-token', $this->connection->fresh()->access_token);
    }

    public function test_missing_calendar_permission_returns_a_reviewed_reconnect_instruction(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'error' => ['errors' => [['reason' => 'insufficientPermissions']]],
            ], 403),
        ]);
        $this->expectException(\App\Exceptions\PublicBusinessRuleException::class);
        $this->expectExceptionMessage(GoogleCalendarService::MISSING_PERMISSION_MESSAGE);
        app(GoogleCalendarService::class)->createEvent($this->connection, ['summary' => 'Test']);
    }

    public function test_primary_calendar_authorization_narrows_legacy_scope_without_losing_identity_scopes(): void
    {
        config([
            'services.google.calendar.default_calendar_id' => 'primary',
            'services.google.calendar.scope' => 'openid email https://www.googleapis.com/auth/calendar.events',
        ]);
        parse_str(parse_url(app(GoogleCalendarService::class)->buildAuthorizationUrl('state', 'demo@example.com', true), PHP_URL_QUERY), $query);
        $this->assertSame('openid email https://www.googleapis.com/auth/calendar.events.owned', $query['scope']);
        $this->assertSame('demo@example.com', $query['login_hint']);
        $this->assertSame('offline', $query['access_type']);
        parse_str(parse_url(app(GoogleCalendarService::class)->buildAuthorizationUrl('state', 'demo@example.com'), PHP_URL_QUERY), $normalQuery);
        $this->assertSame('openid email https://www.googleapis.com/auth/calendar.events', $normalQuery['scope']);
    }

    public function test_permission_validation_accepts_owned_scope_and_existing_broader_grants(): void
    {
        foreach (['calendar.events.owned', 'calendar.events', 'calendar'] as $scope) {
            app(GoogleCalendarService::class)->assertCalendarPermission([
                'scope' => 'openid email https://www.googleapis.com/auth/'.$scope,
            ]);
        }
        $this->addToAssertionCount(3);
    }

    public function test_owned_readonly_permission_is_not_enough_to_sync_appointments(): void
    {
        $this->expectException(\App\Exceptions\PublicBusinessRuleException::class);
        app(GoogleCalendarService::class)->assertCalendarPermission([
            'scope' => 'openid email https://www.googleapis.com/auth/calendar.events.owned.readonly',
        ]);
    }

    public function test_tokeninfo_fallback_accepts_owned_calendar_permission(): void
    {
        Http::fake(['https://oauth2.googleapis.com/tokeninfo*' => Http::response([
            'scope' => 'openid email https://www.googleapis.com/auth/calendar.events.owned',
        ])]);
        app(GoogleCalendarService::class)->assertCalendarPermission(['access_token' => 'test-token']);
        Http::assertSentCount(1);
    }
}
