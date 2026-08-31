<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\DropboxWorkflowService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\PhotographerAvailabilityService;
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

        // The sync service now emits one calendar event per scheduled service item
        // (per-service architecture via buildForServiceItem), so assert both events.
        $assertServiceEventSent = function (string $service) {
            Http::assertSent(function (Request $request) use ($service) {
                $description = (string) ($request['description'] ?? '');

                return $request->method() === 'POST'
                    && str_contains($request->url(), '/calendars/primary/events')
                    && ($request['summary'] ?? null) === $service
                    && ($request['location'] ?? null) === '100 Sync Street, Baltimore, MD 21201'
                    && str_contains($description, "Service\n" . $service)
                    && str_contains($description, 'Use side door. Gate code 1234.')
                    && str_contains($description, 'Bring the wide-angle lens.')
                    && !str_contains($description, 'Internal dispatch detail');
            });
        };

        $assertServiceEventSent('HDR Photos');
        $assertServiceEventSent('Floor Plan');
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

        $this->assertDatabaseMissing('google_calendar_event_mappings', [
            'shoot_id' => $shoot->id,
            'user_id' => $this->photographer->id,
        ]);
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

    public function test_removing_all_services_collapses_service_events_to_one_shoot_level_event(): void
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
            'https://www.googleapis.com/calendar/v3/calendars/*/events/service-event-1' => Http::response([
                'id' => 'service-event-1',
            ], 200),
            'https://www.googleapis.com/calendar/v3/calendars/*/events/service-event-2' => Http::response('', 204),
        ]);

        $confirmation = $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
        ])->assertStatus(409);
        $this->patchJson("/api/shoots/{$shoot->id}", [
            'services' => [],
            'confirm_service_detach' => true,
            'service_detach_confirmation_token' => $confirmation->json('confirmation_token'),
        ])->assertOk();

        $mappings = GoogleCalendarEventMapping::query()->where('shoot_id', $shoot->id)->get();
        $this->assertCount(1, $mappings);
        $this->assertNull($mappings->first()->shoot_service_id);
        $this->assertSame('service-event-1', $mappings->first()->google_event_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/events/service-event-2'));
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/events/service-event-1'));
    }

    public function test_google_calendar_payload_builder_formats_service_titles_and_notes_cleanly(): void
    {
        $camelCaseService = Service::factory()->create([
            'name' => 'LuxuryHighlightVideo',
            'delivery_time' => 2,
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay()->setTime(10, 30),
            'scheduled_date' => now()->addDay()->toDateString(),
            'time' => '10:30',
            'shoot_notes' => "need it fast\n\nfront gate open",
            'photographer_notes' => "green paint\nbring flash",
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
        $this->assertStringContainsString("Shoot Notes:\nneed it fast\nfront gate open", $description);
        // photographer_notes now surface under "Arrival Instructions:" (description rebuilt by tasks 2-4).
        $this->assertStringContainsString("Arrival Instructions:\ngreen paint\nbring flash", $description);
        $this->assertStringContainsString("On-Site Contact:\n{$this->client->name}", $description);
        $this->assertStringContainsString("View shoot: https://reprodashboard.com/shoots/{$shoot->id}", $description);
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
        $dropboxService = Mockery::mock(DropboxWorkflowService::class);
        $dropboxService->shouldIgnoreMissing();
        $dropboxService->shouldReceive('createShootFolders')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(DropboxWorkflowService::class, $dropboxService);

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
