<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ShootActivityLogger;
use App\Services\ShootMediaStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShootApprovalScheduleSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([ShootMediaStorageService::class, InvoiceService::class, GoogleCalendarSyncDispatcher::class] as $class) {
            $this->app->instance($class, Mockery::mock($class)->shouldIgnoreMissing());
        }
        $mail = Mockery::mock(MailService::class)->shouldIgnoreMissing();
        $mail->shouldReceive('captureShootSnapshot')->andReturn([]);
        $mail->shouldReceive('buildShootChangeSummary')->andReturn(['summary' => '', 'html' => '']);
        $mail->shouldReceive('buildClientRequestChangeSummary')->andReturn(['lines' => [], 'service_deltas' => []]);
        $this->app->instance(MailService::class, $mail);

        $automation = Mockery::mock(AutomationService::class)->shouldIgnoreMissing();
        $automation->shouldReceive('buildShootContext')->andReturn([]);
        $automation->shouldReceive('handleEvent')->andReturn([]);
        $automation->shouldReceive('shouldUseFallback')->andReturnFalse();
        $this->app->instance(AutomationService::class, $automation);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    public function test_approval_moves_omitted_inherited_times_and_preserves_explicit_and_separate_visits(bool $resubmitInheritedTime): void
    {
        $photographer = User::factory()->photographer()->create();
        $specialist = User::factory()->photographer()->create();
        $shoot = $this->requestedShoot(['photographer_id' => $photographer->id]);
        $inherited = $this->attachService($shoot, '2026-10-06 10:30:00', $photographer->id);
        $unscheduled = $this->attachService($shoot, null, $photographer->id);
        $separate = $this->attachService($shoot, '2026-10-07 15:00:00', $specialist->id);

        $payload = ['scheduled_at' => '2026-10-06T12:00:00'];
        if ($resubmitInheritedTime) {
            $payload['service_items'] = [[
                'service_id' => $inherited->id,
                'scheduled_at' => '2026-10-06T10:30:00',
            ]];
        }
        $this->approve($shoot, $payload);

        $shoot->refresh();
        $this->assertSame('2026-10-06 12:00:00', $shoot->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06', $shoot->scheduled_date->toDateString());
        $this->assertSame('12:00', $shoot->time);
        foreach ([$inherited, $unscheduled] as $service) {
            $expectedSchedule = $resubmitInheritedTime && $service->id === $inherited->id
                ? '2026-10-06 10:30:00'
                : '2026-10-06 12:00:00';
            $this->assertDatabaseHas('shoot_service', [
                'shoot_id' => $shoot->id, 'service_id' => $service->id,
                'scheduled_at' => $expectedSchedule, 'photographer_id' => $photographer->id,
            ]);
        }
        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id, 'service_id' => $separate->id,
            'scheduled_at' => '2026-10-07 15:00:00', 'photographer_id' => $specialist->id,
        ]);
    }

    public function test_approval_preserves_explicit_service_schedule_overrides_and_assignments(): void
    {
        $shoot = $this->requestedShoot();
        $service = $this->attachService($shoot, '2026-10-06 10:30:00');
        $specialist = User::factory()->photographer()->create();

        $this->approve($shoot, [
            'scheduled_at' => '2026-10-06T12:00:00',
            'service_items' => [[
                'service_id' => $service->id,
                'scheduled_at' => '2026-10-08T14:00:00',
                'photographer_id' => $specialist->id,
            ]],
        ]);

        $this->assertSame('12:00', $shoot->fresh()->time);
        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id, 'service_id' => $service->id,
            'scheduled_at' => '2026-10-08 14:00:00', 'photographer_id' => $specialist->id,
        ]);
    }

    public function test_approval_uses_local_date_and_time_for_a_zoned_schedule(): void
    {
        $shoot = $this->requestedShoot([
            'scheduled_at' => '2026-10-06 16:00:00', 'time' => '12:00',
            'timezone' => 'America/New_York',
        ]);
        $service = $this->attachService($shoot, '2026-10-06 16:00:00');

        $this->approve($shoot, ['scheduled_at' => '2026-10-07T00:30:00Z']);

        $shoot->refresh();
        $this->assertSame('2026-10-07 00:30:00', $shoot->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06', $shoot->scheduled_date->toDateString());
        $this->assertSame('20:30', $shoot->time);
        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id, 'service_id' => $service->id,
            'scheduled_at' => '2026-10-07 00:30:00',
        ]);
    }

    public function test_lock_retry_reloads_workflow_state_and_preserves_atomic_schedules(): void
    {
        $shoot = $this->requestedShoot();
        $service = $this->attachService($shoot, '2026-10-06 10:30:00');
        // Let approval own the outermost SQLite transaction. RefreshDatabase's
        // teardown detects the ended fixture transaction and marks its shared
        // database for a fresh migration before the next test.
        DB::commit();
        $attempts = 0;
        $logger = Mockery::mock(ShootActivityLogger::class)->shouldIgnoreMissing();
        $logger->shouldReceive('log')->withArgs(fn ($model, $action, ...$args) => $action === 'shoot_approved')
            ->twice()->andReturnUsing(function () use (&$attempts) {
                if (++$attempts === 1) {
                    throw new \RuntimeException('database is locked');
                }

                return new \App\Models\ShootActivityLog;
            });
        $this->app->instance(ShootActivityLogger::class, $logger);

        $this->approve($shoot, ['scheduled_at' => '2026-10-06T12:00:00']);

        $this->assertSame(2, $attempts);
        $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->fresh()->status);
        $this->assertSame('12:00', $shoot->fresh()->time);
        $this->assertDatabaseHas('shoot_service', [
            'shoot_id' => $shoot->id, 'service_id' => $service->id,
            'scheduled_at' => '2026-10-06 12:00:00',
        ]);
    }

    private function requestedShoot(array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'status' => Shoot::STATUS_REQUESTED, 'workflow_status' => Shoot::STATUS_REQUESTED,
            'scheduled_at' => '2026-10-06 10:30:00', 'scheduled_date' => '2026-10-06',
            'time' => '10:30', 'timezone' => null, 'photographer_id' => null,
        ], $attributes));
    }

    private function attachService(Shoot $shoot, ?string $scheduledAt, ?int $photographerId = null): Service
    {
        $service = Service::factory()->create(['name' => 'Photos', 'price' => 100]);
        $shoot->services()->attach($service->id, [
            'price' => 100, 'quantity' => 1, 'scheduled_at' => $scheduledAt,
            'photographer_id' => $photographerId, 'workflow_status' => 'scheduled',
        ]);

        return $service;
    }

    private function approve(Shoot $shoot, array $payload): void
    {
        $response = $this->postJson('/api/shoots/'.$shoot->id.'/approve', $payload + [
            'notify_client' => false, 'notify_photographer' => false,
        ]);
        $this->assertSame(200, $response->status(), $response->getContent());
    }
}
