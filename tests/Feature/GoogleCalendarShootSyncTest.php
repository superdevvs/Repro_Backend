<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\ShootMediaStorageService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\PhotographerAvailabilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class GoogleCalendarShootSyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $photographer;
    protected Service $service;
    protected Service $secondService;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.calendar.base_url' => 'https://www.googleapis.com/calendar/v3',
        ]);

        $this->bindShootSideEffectFakes();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
            'email_verified_email' => null,
            'email_verification_required_at' => null,
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'timezone' => 'America/New_York',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'HDR Photos',
            'delivery_time' => 2,
        ]);

        $this->secondService = Service::factory()->create([
            'name' => 'Floor Plan',
            'delivery_time' => 1,
        ]);
    }

    public function test_scheduled_shoot_creation_syncs_to_google_calendar_for_connected_photographers(): void
    {
        Sanctum::actingAs($this->admin);
        $this->createGoogleCalendarConnection($this->photographer, 'photographer-calendar@example.com', 'access-token-1');

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'created-google-event',
            ], 200),
        ]);

        $scheduledAt = now()->addDays(2)->setTime(9, 0)->format('Y-m-d H:i:s');

        $this->postJson('/api/shoots', [
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'address' => '100 Sync Street',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'scheduled_at' => $scheduledAt,
            'services' => [
                ['id' => $this->service->id, 'quantity' => 1],
                ['id' => $this->secondService->id, 'quantity' => 1],
            ],
            'shoot_notes' => 'Use side door. Gate code 1234.',
            'photographer_notes' => 'Bring the wide-angle lens.',
            'company_notes' => 'Internal dispatch detail',
        ])->assertCreated();

        $shoot = Shoot::query()->latest('id')->firstOrFail();

        $this->assertDatabaseHas('google_calendar_event_mappings', [
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
            'google_event_id' => 'created-google-event',
        ]);

        // One event per shoot (not per service), titled with the client name.
        $expectedStart = Carbon::parse($scheduledAt, 'America/New_York');
        Http::assertSent(function (Request $request) use ($expectedStart) {
            $description = (string) ($request['description'] ?? '');

            return $request->method() === 'POST'
                && str_contains($request->url(), '/calendars/primary/events')
                && ($request['summary'] ?? null) === $this->client->name
                && ($request['start'] ?? null) === [
                    'dateTime' => $expectedStart->toRfc3339String(),
                    'timeZone' => 'America/New_York',
                ]
                && ($request['end'] ?? null) === [
                    'dateTime' => $expectedStart->copy()->addMinutes(60)->toRfc3339String(),
                    'timeZone' => 'America/New_York',
                ]
                && ($request['location'] ?? null) === '100 Sync Street, Baltimore, MD 21201'
                && str_contains($description, "Shoot Services:\n- HDR Photos\n- Floor Plan")
                && str_contains($description, 'Use side door. Gate code 1234.')
                && str_contains($description, 'Bring the wide-angle lens.')
                && !str_contains($description, 'Internal dispatch detail');
        });

        $this->assertSame([60, 60], $shoot->serviceItems()->orderBy('id')->pluck('duration_minutes')->all());

        $this->assertSame(
            1,
            GoogleCalendarEventMapping::query()->where('shoot_id', $shoot->id)->count()
        );
        $this->assertDatabaseHas('google_calendar_event_mappings', [
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
            'shoot_service_id' => null,
            'google_event_id' => 'created-google-event',
        ]);
    }

    public function test_updating_a_synced_shoot_patches_the_existing_google_calendar_event(): void
    {
        Sanctum::actingAs($this->admin);
        $this->createGoogleCalendarConnection($this->photographer, 'photographer-calendar@example.com', 'access-token-2');

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(3)->setTime(10, 0),
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'time' => '10:00',
            'address' => '10 Original St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'shoot_notes' => 'Original access note',
            'photographer_notes' => 'Original photographer note',
        ]);

        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);

        GoogleCalendarEventMapping::create([
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
            'calendar_id' => 'primary',
            'google_event_id' => 'existing-google-event',
            'sync_fingerprint' => 'old-fingerprint',
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response([
                'id' => 'existing-google-event',
            ], 200),
        ]);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'address' => '500 Updated Ave',
            'shoot_notes' => 'Updated access note',
        ])->assertOk();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/events/existing-google-event')
                && ($request['location'] ?? null) === '500 Updated Ave, Baltimore, MD 21201'
                && str_contains((string) ($request['description'] ?? ''), 'Updated access note');
        });
    }

    public function test_photographer_reassignment_deletes_the_old_google_event_and_creates_a_new_one(): void
    {
        Sanctum::actingAs($this->admin);

        $replacementPhotographer = User::factory()->create([
            'role' => 'photographer',
            'timezone' => 'America/Chicago',
        ]);

        $this->createGoogleCalendarConnection($this->photographer, 'old-calendar@example.com', 'access-token-old');
        $this->createGoogleCalendarConnection($replacementPhotographer, 'new-calendar@example.com', 'access-token-new');

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(4)->setTime(11, 0),
            'scheduled_date' => now()->addDays(4)->toDateString(),
            'time' => '11:00',
            'address' => '22 Reassign Rd',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);

        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => null,
        ]);

        GoogleCalendarEventMapping::create([
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
            'calendar_id' => 'primary',
            'google_event_id' => 'old-google-event',
            'sync_fingerprint' => 'old-fingerprint',
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events/old-google-event' => Http::response('', 204),
            'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'new-google-event',
            ], 200),
        ]);

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'photographer_id' => $replacementPhotographer->id,
        ])->assertOk();

        $this->assertDatabaseMissing('google_calendar_event_mappings', [
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
        ]);

        $this->assertDatabaseHas('google_calendar_event_mappings', [
            'shoot_id' => $shoot->id,
            'user_id' => $replacementPhotographer->id,
            'google_event_id' => 'new-google-event',
        ]);
    }

    public function test_deleting_a_synced_shoot_removes_the_google_calendar_event_mapping(): void
    {
        Sanctum::actingAs($this->admin);
        $this->createGoogleCalendarConnection($this->photographer, 'photographer-calendar@example.com', 'access-token-3');

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(5)->setTime(14, 0),
            'scheduled_date' => now()->addDays(5)->toDateString(),
            'time' => '14:00',
        ]);

        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);

        GoogleCalendarEventMapping::create([
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
            'calendar_id' => 'primary',
            'google_event_id' => 'delete-me-event',
            'sync_fingerprint' => 'old-fingerprint',
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events/delete-me-event' => Http::response('', 204),
        ]);

        $this->deleteJson("/api/shoots/{$shoot->id}")
            ->assertOk();

        $this->assertDatabaseMissing('shoots', ['id' => $shoot->id]);
        $this->assertDatabaseMissing('google_calendar_event_mappings', [
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
        ]);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/calendars/primary/events/delete-me-event');
        });
    }

    public function test_unconnected_photographers_are_skipped_without_failing_the_shoot_mutation(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(6)->setTime(9, 30),
            'scheduled_date' => now()->addDays(6)->toDateString(),
            'time' => '09:30',
            'address' => '11 Quiet Lane',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);

        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);

        Http::fake();

        $this->patchJson("/api/shoots/{$shoot->id}", [
            'address' => '11 Quiet Lane Updated',
        ])->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseCount('google_calendar_event_mappings', 0);
    }

    public function test_resync_collapses_legacy_per_service_events_to_one_shoot_level_event(): void
    {
        Sanctum::actingAs($this->admin);
        $this->createGoogleCalendarConnection($this->photographer, 'photographer-calendar@example.com', 'access-token-zero-services');

        $scheduledAt = now()->addDays(7)->setTime(10, 0);
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'scheduled_date' => $scheduledAt->toDateString(),
            'time' => '10:00',
            'address' => '77 Collapse Ct',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);
        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_id' => $this->photographer->id,
            'scheduled_at' => $scheduledAt,
        ]);
        $shoot->services()->attach($this->secondService->id, [
            'price' => 90,
            'quantity' => 1,
            'photographer_id' => $this->photographer->id,
            'scheduled_at' => $scheduledAt,
        ]);
        $items = $shoot->serviceItems()->orderBy('id')->get();

        foreach ($items->values() as $index => $item) {
            GoogleCalendarEventMapping::create([
                'shoot_id' => $shoot->id,
                'shoot_service_id' => $item->id,
                'user_id' => $this->photographer->id,
                'calendar_id' => 'primary',
                'google_event_id' => 'service-event-'.($index + 1),
                'sync_fingerprint' => 'old-fingerprint-'.$index,
            ]);
        }

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events/service-event-1' => Http::response('', 204),
            'https://www.googleapis.com/calendar/v3/calendars/*/events/service-event-2' => Http::response('', 204),
            'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'shoot-level-event',
            ], 200),
        ]);

        app(\App\Services\GoogleCalendar\GoogleCalendarShootSyncService::class)->syncShoot($shoot->id);

        $mappings = GoogleCalendarEventMapping::query()->where('shoot_id', $shoot->id)->get();
        $this->assertCount(1, $mappings);
        $this->assertNull($mappings->first()->shoot_service_id);
        $this->assertSame('shoot-level-event', $mappings->first()->google_event_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/events/service-event-1'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/events/service-event-2'));
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/calendars/primary/events')
                && ($request['summary'] ?? null) === $this->client->name
                && str_contains((string) ($request['description'] ?? ''), 'Shoot Services:');
        });
    }

    public function test_google_calendar_payload_builder_formats_service_titles_and_notes_cleanly(): void
    {
        $camelCaseService = Service::factory()->create([
            'name' => 'LuxuryHighlightVideo',
            'delivery_time' => 2,
        ]);

        $rep = User::factory()->create([
            'role' => 'rep',
            'name' => 'Jordan Rep',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'rep_id' => $rep->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay()->setTime(10, 30),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:30',
            'shoot_notes' => "need it fast\n\nfront gate open",
            'photographer_notes' => "green paint\nbring flash",
            'property_details' => [
                'presenceOption' => 'lockbox',
                'lockboxCode' => '2468',
                'lockboxLocation' => 'Side door',
            ],
        ]);

        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);

        $shoot->services()->attach($camelCaseService->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);

        $payload = app(GoogleCalendarEventPayloadBuilder::class)->build($shoot->fresh('services'), $this->photographer);

        $this->assertSame($this->client->name, $payload['summary']);

        $description = (string) $payload['description'];
        $this->assertStringStartsWith($this->client->name, $description);
        $this->assertStringContainsString("Shoot Services:\n- HDR Photos\n- Luxury Highlight Video", $description);
        $this->assertStringContainsString("Rep: Jordan Rep", $description);
        $this->assertStringContainsString("Shoot Notes:\nneed it fast\nfront gate open", $description);
        $this->assertStringContainsString("Property Access:\nLockbox\nLockbox / combo code: 2468\nLockbox location: Side door", $description);
        // photographer_notes now surface under "Arrival Instructions:" (description rebuilt by tasks 2-4).
        $this->assertStringContainsString("Arrival Instructions:\ngreen paint\nbring flash", $description);
        $this->assertStringContainsString("On-Site Contact:\n{$this->client->name}", $description);
        $this->assertStringContainsString("View shoot: https://reprodashboard.com/shoots/{$shoot->id}", $description);
    }


    public function test_changing_shoot_time_patches_the_existing_google_calendar_event(): void
    {
        Sanctum::actingAs($this->admin);
        $this->createGoogleCalendarConnection($this->photographer, 'photographer-calendar@example.com', 'access-token-time');

        $scheduledAt = now()->addDays(3)->setTime(10, 0, 0);
        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'scheduled_date' => $scheduledAt->toDateString(),
            'time' => '10:00:00',
            'timezone' => null,
            'address' => '5818 Inman Park Cir',
            'city' => 'Rockville',
            'state' => 'MD',
            'zip' => '20852',
        ]);

        $shoot->services()->attach($this->service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);

        GoogleCalendarEventMapping::create([
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
            'calendar_id' => 'primary',
            'google_event_id' => 'existing-time-event',
            'sync_fingerprint' => 'old-fingerprint',
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response([
                'id' => 'existing-time-event',
            ], 200),
        ]);

        $newTime = '14:30:00';
        $this->patchJson("/api/shoots/{$shoot->id}", [
            'scheduled_date' => $scheduledAt->toDateString(),
            'time' => $newTime,
        ])->assertOk();

        $expectedStart = Carbon::parse(
            $scheduledAt->toDateString().' '.$newTime,
            'America/New_York'
        );

        Http::assertSent(function (Request $request) use ($expectedStart) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/events/existing-time-event')
                && ($request['start']['dateTime'] ?? null) === $expectedStart->toRfc3339String()
                && ($request['start']['timeZone'] ?? null) === 'America/New_York';
        });

        $this->assertDatabaseHas('shoots', [
            'id' => $shoot->id,
            'time' => $newTime,
        ]);
    }

    protected function createGoogleCalendarConnection(User $user, string $email, string $accessToken): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'provider_email' => $email,
            'calendar_id' => 'primary',
            'access_token' => $accessToken,
            'refresh_token' => $accessToken . '-refresh',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => true,
        ]);
    }

    protected function bindShootSideEffectFakes(): void
    {
        $dropboxService = Mockery::mock(ShootMediaStorageService::class);
        $dropboxService->shouldIgnoreMissing();
        $dropboxService->shouldReceive('createShootFolders')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(ShootMediaStorageService::class, $dropboxService);

        $invoiceService = Mockery::mock(InvoiceService::class);
        $invoiceService->shouldIgnoreMissing();
        $invoiceService->shouldReceive('generateForShoot')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(InvoiceService::class, $invoiceService);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('captureShootSnapshot')->zeroOrMoreTimes()->andReturn([]);
        $mailService->shouldReceive('buildShootChangeSummary')->zeroOrMoreTimes()->andReturn([
            'summary' => 'Shoot details updated',
            'html' => '<p>Shoot details updated</p>',
        ]);
        $mailService->shouldReceive('sendShootUpdatedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendShootScheduledEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendShootRemovedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('sendPhotographerChangedEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mailService->shouldReceive('generatePaymentLink')->zeroOrMoreTimes()->andReturn('https://example.test/payment');
        $this->app->instance(MailService::class, $mailService);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $shoot) => [
                'shoot' => $shoot,
                'shoot_id' => $shoot->id,
                'client' => $shoot->client,
                'photographer' => $shoot->photographer,
                'photographers' => $shoot->photographer ? [$shoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')->zeroOrMoreTimes()->andReturn([]); // current contract: array (was null)
        $automationService->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $availabilityService = Mockery::mock(PhotographerAvailabilityService::class);
        $availabilityService->shouldIgnoreMissing();
        $availabilityService->shouldReceive('isAvailable')->zeroOrMoreTimes()->andReturnTrue();
        $this->app->instance(PhotographerAvailabilityService::class, $availabilityService);
    }
}
