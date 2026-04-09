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
}
