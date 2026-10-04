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
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarTimingResyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'UTC',
            'availability.default_shoot_duration_minutes' => 60,
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

        $shoot->serviceItems()->update(['duration_minutes' => 180]);
        $sync->syncShoot($shoot->id);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/events/timing-event')
            && $request['start']['dateTime'] === '2026-09-10T10:00:00-04:00'
            && $request['end']['dateTime'] === '2026-09-10T13:00:00-04:00');

        $this->assertNotSame($originalFingerprint, $mapping->fresh()->sync_fingerprint);
        $this->assertExistingMappingIsStable($sync, $shoot, $mapping);
    }


    #[DataProvider('syncPaths')]
    public function test_scheduled_at_time_change_updates_existing_event_once(bool $perService): void
    {
        [$shoot] = $this->createScheduledShoot($perService);
        $sync = app(GoogleCalendarShootSyncService::class);

        $sync->syncShoot($shoot->id);
        $mapping = GoogleCalendarEventMapping::query()->sole();
        $originalFingerprint = $mapping->sync_fingerprint;

        $sync->syncShoot($shoot->id);
        Http::assertSentCount(1);

        if ($perService) {
            // Keep service-item times aligned before the shoot save so the
            // ShootObserver lifecycle sync (and the explicit sync below) both
            // see the same schedule and do not produce an extra PATCH.
            $shoot->serviceItems()->update(['scheduled_at' => '2026-09-10 16:00:00']);
        }

        $shoot->forceFill([
            'scheduled_at' => '2026-09-10 16:00:00',
            'scheduled_date' => '2026-09-10',
            'time' => '16:00:00',
        ])->save();

        // Observer already dispatched a sync on scheduled_at change; a second
        // explicit sync must fingerprint-match and not call Google again.
        $sync->syncShoot($shoot->id);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/events/timing-event')
            && $request['start'] === [
                'dateTime' => '2026-09-10T12:00:00-04:00',
                'timeZone' => 'America/New_York',
            ]
            && $request['end'] === [
                'dateTime' => '2026-09-10T14:00:00-04:00',
                'timeZone' => 'America/New_York',
            ]);

        $this->assertNotSame($originalFingerprint, $mapping->fresh()->sync_fingerprint);
        $this->assertExistingMappingIsStable($sync, $shoot->fresh(), $mapping);
    }

    public function test_missing_app_key_is_recorded_and_rethrown_instead_of_silent_success(): void
    {
        [$shoot] = $this->createScheduledShoot(false);
        $connection = GoogleCalendarConnection::query()->where('user_id', $shoot->photographer_id)->sole();

        $calendar = \Mockery::mock(\App\Services\GoogleCalendar\GoogleCalendarService::class);
        $calendar->shouldReceive('createEvent')
            ->once()
            ->andThrow(new \Illuminate\Encryption\MissingAppKeyException(
                'No application encryption key has been specified.'
            ));

        $sync = new GoogleCalendarShootSyncService(
            $calendar,
            app(\App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder::class)
        );

        try {
            $sync->syncShoot($shoot->id);
            $this->fail('Expected MissingAppKeyException to propagate.');
        } catch (\Illuminate\Encryption\MissingAppKeyException $exception) {
            $this->assertSame(
                'No application encryption key has been specified.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            'No application encryption key has been specified.',
            $connection->fresh()->last_error
        );
        $this->assertDatabaseCount('google_calendar_event_mappings', 0);
        Http::assertNothingSent();
    }

    public function test_first_push_provider_failure_is_rethrown_so_the_job_retries(): void
    {
        [$shoot] = $this->createScheduledShoot(false);
        $connection = GoogleCalendarConnection::query()->where('user_id', $shoot->photographer_id)->sole();

        $calendar = \Mockery::mock(\App\Services\GoogleCalendar\GoogleCalendarService::class);
        $calendar->shouldReceive('createEvent')
            ->once()
            ->andThrow(new \RuntimeException('Google Calendar event request failed.'));

        $sync = new GoogleCalendarShootSyncService(
            $calendar,
            app(\App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder::class)
        );

        try {
            $sync->syncShoot($shoot->id);
            $this->fail('Expected provider failure on first push to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Google Calendar event request failed.', $exception->getMessage());
        }

        $this->assertSame(
            'Google Calendar event request failed.',
            $connection->fresh()->last_error
        );
        $this->assertDatabaseCount('google_calendar_event_mappings', 0);
    }

    public function test_existing_event_provider_failure_is_rethrown_for_retry(): void
    {
        [$shoot] = $this->createScheduledShoot(false);
        $connection = GoogleCalendarConnection::query()->where('user_id', $shoot->photographer_id)->sole();

        GoogleCalendarEventMapping::create([
            'shoot_id' => $shoot->id,
            'user_id' => $shoot->photographer_id,
            'calendar_id' => 'primary',
            'google_event_id' => 'existing-event',
            'sync_fingerprint' => 'stale-fingerprint',
        ]);

        $calendar = \Mockery::mock(\App\Services\GoogleCalendar\GoogleCalendarService::class);
        $calendar->shouldReceive('updateEvent')
            ->once()
            ->andThrow(new \RuntimeException('Google Calendar event request failed.'));

        $sync = new GoogleCalendarShootSyncService(
            $calendar,
            app(\App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder::class)
        );

        try {
            $sync->syncShoot($shoot->id);
            $this->fail('An existing event update must retry after provider failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Google Calendar event request failed.', $exception->getMessage());
        }

        $this->assertSame(
            'Google Calendar event request failed.',
            $connection->fresh()->last_error
        );
        $this->assertDatabaseHas('google_calendar_event_mappings', [
            'shoot_id' => $shoot->id,
            'google_event_id' => 'existing-event',
        ]);
    }

    public function test_decrypt_exception_is_recorded_and_rethrown_for_retry(): void
    {
        [$shoot] = $this->createScheduledShoot(false);
        $connection = GoogleCalendarConnection::query()->where('user_id', $shoot->photographer_id)->sole();

        $calendar = \Mockery::mock(\App\Services\GoogleCalendar\GoogleCalendarService::class);
        $calendar->shouldReceive('createEvent')
            ->once()
            ->andThrow(new \Illuminate\Contracts\Encryption\DecryptException('The MAC is invalid.'));

        $sync = new GoogleCalendarShootSyncService(
            $calendar,
            app(\App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder::class)
        );

        try {
            $sync->syncShoot($shoot->id);
            $this->fail('Expected DecryptException to propagate.');
        } catch (\Illuminate\Contracts\Encryption\DecryptException $exception) {
            $this->assertSame('The MAC is invalid.', $exception->getMessage());
        }

        $this->assertSame('The MAC is invalid.', $connection->fresh()->last_error);
        $this->assertDatabaseCount('google_calendar_event_mappings', 0);
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
            'duration_minutes' => 120,
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
