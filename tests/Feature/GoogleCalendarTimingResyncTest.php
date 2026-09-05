<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarShootSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarTimingResyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'UTC',
            'availability.default_shoot_duration_minutes' => 120,
            'services.google.calendar.base_url' => 'https://calendar.test/calendar/v3',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://calendar.test/calendar/v3/calendars/primary/events*' => Http::response([
                'id' => 'timing-event',
            ]),
        ]);
    }

    public static function syncPaths(): array
    {
        return [
            'whole shoot' => [false],
            'scheduled service item' => [true],
        ];
    }

    #[DataProvider('syncPaths')]
    public function test_photographer_timezone_change_updates_existing_event_once(bool $perService): void
    {
        [$shoot, $photographer] = $this->createScheduledShoot($perService);
        $sync = app(GoogleCalendarShootSyncService::class);

        $sync->syncShoot($shoot->id);
        $mapping = GoogleCalendarEventMapping::query()->sole();
        $originalFingerprint = $mapping->sync_fingerprint;

        $sync->syncShoot($shoot->id);
        Http::assertSentCount(1);

        $photographer->update(['timezone' => 'America/Los_Angeles']);
        $sync->resyncUser($photographer->id);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/events/timing-event')
            && $request['start'] === [
                'dateTime' => '2026-09-10T07:00:00-07:00',
                'timeZone' => 'America/Los_Angeles',
            ]
            && $request['end'] === [
                'dateTime' => '2026-09-10T09:00:00-07:00',
                'timeZone' => 'America/Los_Angeles',
            ]);

        $this->assertNotSame($originalFingerprint, $mapping->fresh()->sync_fingerprint);
        $this->assertExistingMappingIsStable($sync, $shoot, $mapping);
    }

    #[DataProvider('syncPaths')]
    public function test_duration_change_updates_existing_event_once(bool $perService): void
    {
        [$shoot] = $this->createScheduledShoot($perService);
        $sync = app(GoogleCalendarShootSyncService::class);

        $sync->syncShoot($shoot->id);
        $mapping = GoogleCalendarEventMapping::query()->sole();
        $originalFingerprint = $mapping->sync_fingerprint;

        $sync->syncShoot($shoot->id);
        Http::assertSentCount(1);

        config(['availability.default_shoot_duration_minutes' => 180]);
        $sync->syncShoot($shoot->id);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/events/timing-event')
            && $request['start']['dateTime'] === '2026-09-10T10:00:00-04:00'
            && $request['end']['dateTime'] === '2026-09-10T13:00:00-04:00');

        $this->assertNotSame($originalFingerprint, $mapping->fresh()->sync_fingerprint);
        $this->assertExistingMappingIsStable($sync, $shoot, $mapping);
    }

    private function createScheduledShoot(bool $perService): array
    {
        $photographer = User::factory()->photographer()->create([
            'timezone' => 'America/New_York',
        ]);
        $service = Service::factory()->create(['name' => 'HDR Photos']);
        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => '2026-09-10 14:00:00',
            'scheduled_date' => '2026-09-10',
            'time' => '14:00',
            'timezone' => 'UTC',
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 100,
            'quantity' => 1,
            'photographer_id' => $photographer->id,
            'scheduled_at' => $perService ? '2026-09-10 14:00:00' : null,
        ]);

        GoogleCalendarConnection::create([
            'user_id' => $photographer->id,
            'provider_email' => 'photographer@example.test',
            'calendar_id' => 'primary',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);

        return [$shoot, $photographer];
    }

    private function assertExistingMappingIsStable(
        GoogleCalendarShootSyncService $sync,
        Shoot $shoot,
        GoogleCalendarEventMapping $mapping
    ): void {
        $fingerprint = $mapping->fresh()->sync_fingerprint;
        $sync->syncShoot($shoot->id);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('google_calendar_event_mappings', 1);
        $this->assertDatabaseHas('google_calendar_event_mappings', [
            'id' => $mapping->id,
            'google_event_id' => 'timing-event',
            'sync_fingerprint' => $fingerprint,
        ]);
    }
}
