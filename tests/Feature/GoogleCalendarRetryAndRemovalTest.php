<?php

namespace Tests\Feature;

use App\Models\{GoogleCalendarConnection, GoogleCalendarEventMapping, Shoot, User};
use App\Services\GoogleCalendar\GoogleCalendarShootSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Queue};
use Tests\TestCase;

class GoogleCalendarRetryAndRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.google.calendar.base_url' => 'https://calendar.test']);
    }

    private function booking(): array
    {
        $user = User::factory()->photographer()->create(['timezone' => 'America/New_York']);
        $connection = GoogleCalendarConnection::create([
            'user_id' => $user->id, 'calendar_id' => 'primary', 'provider_email' => 'qa@example.test',
            'access_token' => 'test-token', 'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addHour(), 'sync_enabled' => true,
        ]);
        $shoot = Shoot::factory()->create([
            'photographer_id' => $user->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'timezone' => 'America/New_York', 'scheduled_at' => '2026-10-05 16:30:00',
        ]);
        $mapping = GoogleCalendarEventMapping::create([
            'shoot_id' => $shoot->id, 'user_id' => $user->id, 'calendar_id' => 'primary',
            'google_event_id' => 'existing-event', 'sync_fingerprint' => 'previous',
        ]);
        return [$shoot, $mapping, $connection];
    }

    public function test_failed_time_update_is_retried_without_creating_a_second_event(): void
    {
        [$shoot, $mapping] = $this->booking();
        $shoot->update(['scheduled_at' => '2026-10-06 17:00:00']);
        Http::fake(['*' => Http::sequence()->push([], 503)->push(['id' => 'existing-event'], 200)]);
        try {
            app(GoogleCalendarShootSyncService::class)->syncShoot($shoot->id);
            $this->fail('A failed update must not acknowledge the queue job.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Google Calendar', $e->getMessage());
        }
        $this->assertSame('previous', $mapping->fresh()->sync_fingerprint);
        app(GoogleCalendarShootSyncService::class)->syncShoot($shoot->id);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/existing-event')
            && $request['start']['dateTime'] === '2026-10-06T13:00:00-04:00');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame(1, GoogleCalendarEventMapping::where('shoot_id', $shoot->id)->count());
    }

    public function test_failed_removal_retains_mapping_and_retries_already_deleted_event(): void
    {
        [$shoot, $mapping] = $this->booking();
        Http::fake(['*' => Http::sequence()->push([], 503)->push([], 410)]);
        try {
            app(GoogleCalendarShootSyncService::class)->removeShoot($shoot->id);
            $this->fail('A failed removal must not acknowledge the queue job.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }
        $this->assertNotNull($mapping->fresh());
        app(GoogleCalendarShootSyncService::class)->removeShoot($shoot->id);
        $this->assertNull($mapping->fresh());
    }

    public function test_changed_calendar_removes_old_event_before_creating_new_mapping(): void
    {
        [$shoot, $mapping, $connection] = $this->booking();
        $connection->update(['calendar_id' => 'new-calendar']);
        Http::fake(fn ($request) => $request->method() === 'DELETE'
            ? Http::response([], 204) : Http::response(['id' => 'new-event'], 200));
        app(GoogleCalendarShootSyncService::class)->syncShoot($shoot->id);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/primary/events/existing-event'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), '/new-calendar/events'));
        $this->assertDatabaseHas('google_calendar_event_mappings', ['shoot_id' => $shoot->id, 'calendar_id' => 'new-calendar', 'google_event_id' => 'new-event']);
    }
}
