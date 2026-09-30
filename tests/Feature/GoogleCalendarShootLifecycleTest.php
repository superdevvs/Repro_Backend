<?php

namespace Tests\Feature;

use App\Jobs\SyncShootToGoogleCalendarJob;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleCalendarShootLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_new_booking_requires_an_enabled_calendar_connection(): void
    {
        $photographer = User::factory()->photographer()->create();
        $this->scheduledShoot($photographer);
        Queue::assertNotPushed(SyncShootToGoogleCalendarJob::class);

        $connection = $this->connection($photographer, false);
        $this->scheduledShoot($photographer);
        Queue::assertNotPushed(SyncShootToGoogleCalendarJob::class);

        $connection->update(['sync_enabled' => true]);
        $shoot = $this->scheduledShoot($photographer);
        Queue::assertPushed(SyncShootToGoogleCalendarJob::class, 1);
        Queue::assertPushed(SyncShootToGoogleCalendarJob::class, fn ($job) => $job->shootId === $shoot->id);
    }

    public function test_suppressed_imports_and_internal_shoots_stay_quiet_even_with_a_connection(): void
    {
        $photographer = User::factory()->photographer()->create();
        $this->connection($photographer);
        foreach ([
            ['status' => Shoot::STATUS_IMPORT_DRAFT],
            ['shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST],
            ['external_booking_payload' => ['legacy_migration' => ['historical_payments_only' => true, 'notifications_suppressed' => true]]],
        ] as $attributes) {
            $shoot = $this->scheduledShoot($photographer, $attributes);
            // Private review drafts intentionally reject every ordinary save.
            if (! $shoot->isImportDraft()) {
                $shoot->update(['scheduled_at' => '2026-10-02 14:00:00']);
            }
        }
        Queue::assertNotPushed(SyncShootToGoogleCalendarJob::class);
    }

    public function test_unmapped_requested_or_unscheduled_shoots_do_not_queue_calendar_work(): void
    {
        $photographer = User::factory()->photographer()->create();
        $this->connection($photographer);
        $this->scheduledShoot($photographer, ['status' => Shoot::STATUS_REQUESTED, 'workflow_status' => Shoot::STATUS_REQUESTED]);
        $this->scheduledShoot($photographer, ['scheduled_at' => null, 'scheduled_date' => null, 'time' => null]);
        Queue::assertNotPushed(SyncShootToGoogleCalendarJob::class);
    }

    public function test_creation_waits_for_committed_service_assignments(): void
    {
        $photographer = User::factory()->photographer()->create();
        $this->connection($photographer);
        DB::transaction(function () use ($photographer) {
            $shoot = $this->scheduledShoot($photographer, ['photographer_id' => null]);
            $shoot->services()->attach(Service::factory()->create()->id, [
                'photographer_id' => $photographer->id,
                'scheduled_at' => '2026-10-01 14:00:00',
            ]);
            Queue::assertNotPushed(SyncShootToGoogleCalendarJob::class);
        });
        Queue::assertPushed(SyncShootToGoogleCalendarJob::class, 1);
    }

    public function test_marketing_only_save_on_the_just_created_instance_does_not_resync(): void
    {
        $photographer = User::factory()->photographer()->create();
        $this->connection($photographer);
        $shoot = $this->scheduledShoot($photographer);
        Queue::fake();
        $shoot->update(['is_featured' => true, 'featured_homepage_title' => 'Public portfolio title']);
        Queue::assertNotPushed(SyncShootToGoogleCalendarJob::class);
    }

    public function test_existing_mapping_still_queues_cleanup_after_assignment_and_schedule_removal(): void
    {
        $photographer = User::factory()->photographer()->create();
        $shoot = $this->scheduledShoot($photographer);
        GoogleCalendarEventMapping::create([
            'shoot_id' => $shoot->id, 'user_id' => $photographer->id,
            'calendar_id' => 'primary', 'google_event_id' => 'existing-event',
        ]);
        $shoot->update(['photographer_id' => null, 'scheduled_at' => null, 'scheduled_date' => null, 'time' => null]);
        Queue::assertPushed(SyncShootToGoogleCalendarJob::class, 1);
    }

    private function scheduledShoot(User $photographer, array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => '2026-10-01 14:00:00', 'scheduled_date' => '2026-10-01', 'time' => '14:00',
        ], $attributes));
    }

    private function connection(User $photographer, bool $enabled = true): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::create([
            'user_id' => $photographer->id, 'provider_email' => 'calendar@example.test',
            'calendar_id' => 'primary', 'access_token' => 'test-token', 'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addHour(), 'sync_enabled' => $enabled,
        ]);
    }
}
