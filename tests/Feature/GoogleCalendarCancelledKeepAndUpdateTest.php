<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\Services\GoogleCalendar\GoogleCalendarShootSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/** Cancelled shoots no longer occupy Google Calendar. */
class GoogleCalendarCancelledKeepAndUpdateTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_cancelled_shoot_removes_existing_event(): void
    {
        config([
            'services.google.calendar.base_url' => 'https://www.googleapis.com/calendar/v3',
            'services.google.calendar.dashboard_url' => 'https://reprodashboard.com',
        ]);

        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Jane Client',
            'email' => 'jane.client@example.com',
            'phone' => '410-555-0100',
        ]);

        $photographer = User::factory()->create([
            'role' => 'photographer',
            'timezone' => 'America/New_York',
        ]);

        $service = Service::factory()->create([
            'name' => 'HDR Photos',
            'delivery_time' => 2,
        ]);

        $connection = GoogleCalendarConnection::create([
            'user_id' => $photographer->id,
            'provider_email' => 'photographer-calendar@example.com',
            'calendar_id' => 'primary',
            'access_token' => 'access-token-cancelled',
            'refresh_token' => 'access-token-cancelled-refresh',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
            'scheduled_at' => now()->addDays(3)->setTime(10, 0),
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'time' => '10:00',
            'address' => '10 Cancelled St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'shoot_notes' => 'Side door access.',
        ]);

        // Attach a service without a per-item schedule so the legacy (whole-shoot) sync path
        // is used rather than the per-service-item path.
        $shoot->services()->attach($service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $photographer->id,
        ]);

        // Existing mapping for the photographer calendar (legacy/whole-shoot mapping).
        GoogleCalendarEventMapping::create([
            'shoot_id' => $shoot->id,
            'user_id' => $photographer->id,
            'shoot_service_id' => null,
            'calendar_id' => 'primary',
            'google_event_id' => 'existing-cancelled-event',
            'sync_fingerprint' => 'stale-fingerprint',
        ]);

        $calendarService = Mockery::mock(GoogleCalendarService::class);
        $calendarService->shouldReceive('deleteEvent')->once()
            ->with(Mockery::type(GoogleCalendarConnection::class), 'primary', 'existing-cancelled-event');
        $calendarService->shouldReceive('createEvent')->never();
        $calendarService->shouldReceive('updateEvent')->never();
        $syncService = new GoogleCalendarShootSyncService(
            $calendarService,
            app(GoogleCalendarEventPayloadBuilder::class)
        );

        $syncService->syncShoot($shoot->id);

        $this->assertDatabaseMissing('google_calendar_event_mappings', [
            'shoot_id' => $shoot->id, 'google_event_id' => 'existing-cancelled-event',
        ]);
    }
}