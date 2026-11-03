<?php

namespace Tests\Feature;

use App\Jobs\SyncShootIguideJob;
use App\Models\{Service, Shoot, ShootFile, ShootService, User};
use App\Services\Shoots\ShootSubmissionCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Notification, Queue, Storage};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActiveRawSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(12, 0));
        Http::preventStrayRequests(); Mail::fake(); Notification::fake(); Queue::fake(); Storage::fake('public');
    }

    private function fixture(string $stage = 'editing'): array
    {
        $oldArtist = User::factory()->photographer()->create();
        $artistA = User::factory()->photographer()->create();
        $artistB = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create(['photographer_id' => $oldArtist->id, 'status' => 'active', 'workflow_status' => $stage,
            'scheduled_date' => '2026-11-03', 'scheduled_at' => '2026-11-03 15:00:00', 'time' => '10:00', 'timezone' => 'America/New_York',
            'admin_verified_at' => '2026-11-04 15:00:00', 'completed_at' => '2026-11-04 15:00:00',
            'photos_uploaded_at' => '2026-11-04 12:00:00']);
        $makeItem = function (User $artist, bool $done = false) use ($shoot): ShootService {
            $service = Service::factory()->unbracketedPhoto()->create();
            return ShootService::create(['shoot_id' => $shoot->id, 'service_id' => $service->id,
                'photographer_id' => $artist->id, 'quantity' => 1, 'price' => 100, 'is_deliverable' => true,
                'workflow_status' => $done ? 'delivered' : 'pending', 'delivery_status' => $done ? 'delivered' : 'not_started',
                'delivered_at' => $done ? '2026-11-04 15:00:00' : null]);
        };
        $done = $makeItem($oldArtist, true);
        $a = $makeItem($artistA);
        $b = $makeItem($artistB);
        $shoot->forceFill(['active_work_started_at' => '2026-11-06 09:00:00', 'active_work_completed_at' => null,
            'active_work_at' => '2026-11-06 09:00:00', 'active_work_date' => '2026-11-06'])->save();
        return [$shoot->fresh(), $done->fresh(), $a->fresh(), $b->fresh(), $artistA, $artistB, $oldArtist];
    }

    private function raw(Shoot $shoot, ?ShootService $item, string $createdAt, array $extra = []): ShootFile
    {
        $name = 'input-'.bin2hex(random_bytes(4)).'.jpg';
        $file = new ShootFile($extra + ['shoot_id' => $shoot->id, 'shoot_service_id' => $item?->id,
            'filename' => $name, 'stored_filename' => $name, 'path' => "shoots/{$shoot->id}/todo/{$name}",
            'file_type' => 'image/jpeg', 'file_size' => 100, 'workflow_stage' => 'todo', 'media_type' => 'raw',
            'uploaded_by' => $item?->photographer_id ?? $shoot->photographer_id, 'scan_status' => 'clean']);
        $file->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        return $file->fresh();
    }

    public static function advancedStages(): array
    {
        return [['editing'], ['ready']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('advancedStages')]
    public function test_fresh_owned_service_raw_can_be_submitted_after_another_service_advanced(string $stage): void
    {
        [$shoot, $done, $a, $b, $artistA, $artistB] = $this->fixture($stage);
        $a->forceFill(['workflow_status' => 'ready', 'delivery_status' => 'ready',
            'editing_completed_at' => '2026-11-06 11:00:00', 'ready_at' => '2026-11-06 11:00:00'])->save();
        $doneBefore = $done->fresh()->getRawOriginal();
        $otherBefore = $a->fresh()->getRawOriginal();
        $file = $this->raw($shoot, $b, '2026-11-06 10:30:00');
        $this->assertTrue(app(ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot->fresh(), $artistB));
        Sanctum::actingAs($artistB);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertOk()->assertJsonPath('workflow_status_changed', true);
        $shoot->refresh();
        $this->assertSame('uploaded', $shoot->workflow_status);
        $this->assertSame('active', $shoot->status);
        $this->assertSame('2026-11-03', $shoot->scheduled_date->toDateString());
        $this->assertSame('2026-11-04 15:00:00', $shoot->admin_verified_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 15:00:00', $shoot->completed_at->format('Y-m-d H:i:s'));
        $this->assertSame($doneBefore, $done->fresh()->getRawOriginal());
        $this->assertSame($otherBefore, $a->fresh()->getRawOriginal());
        $this->assertSame($shoot->active_work_started_at->toIso8601String(), data_get($file->fresh()->metadata, 'active_work_raw_submission.cycle'));
        $this->assertFalse(app(ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot, $artistB));
        $this->assertFalse(app(ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot, $artistA));
    }

    public function test_old_unscoped_other_artist_and_already_delivered_raw_cannot_enable_submission(): void
    {
        [$shoot, $done, $a, $b, $artistA, $artistB, $oldArtist] = $this->fixture();
        $old = $this->raw($shoot, $a, '2026-11-05 10:00:00');
        $this->raw($shoot, null, '2026-11-06 10:00:00');
        $this->raw($shoot, $b, '2026-11-06 10:00:00');
        $this->raw($shoot, $done, '2026-11-06 10:00:00');
        $before = $shoot->photos_uploaded_at->toIso8601String();
        $this->assertFalse(app(ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot, $artistA));
        Sanctum::actingAs($artistA);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertUnprocessable()->assertJsonPath('error_type', 'no_new_files');
        $this->assertSame($before, $shoot->fresh()->photos_uploaded_at->toIso8601String());
        $this->assertNull(data_get($old->fresh()->metadata, 'active_work_raw_submission'));
        Sanctum::actingAs($oldArtist);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertForbidden();
    }

    public function test_repeated_submission_does_not_restamp_raw_or_repeat_its_handoff(): void
    {
        [$shoot, $done, $a, $b, $artistA] = $this->fixture();
        $file = $this->raw($shoot, $a, '2026-11-06 10:00:00');
        Sanctum::actingAs($artistA);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertOk();
        $receipt = data_get($file->fresh()->metadata, 'active_work_raw_submission');
        $submittedAt = $shoot->fresh()->photos_uploaded_at->toIso8601String();
        $this->travel(5)->minutes();
        $shoot->refresh()->forceFill(['status' => 'active', 'workflow_status' => 'editing'])->save();
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertUnprocessable();
        $this->assertSame($receipt, data_get($file->fresh()->metadata, 'active_work_raw_submission'));
        $this->assertSame($submittedAt, $shoot->fresh()->photos_uploaded_at->toIso8601String());
        Queue::assertPushed(SyncShootIguideJob::class, 1);
    }

    public function test_one_artists_later_submission_does_not_hide_another_artists_earlier_unsubmitted_batch(): void
    {
        [$shoot, $done, $a, $b, $artistA, $artistB] = $this->fixture();
        $fileA = $this->raw($shoot, $a, '2026-11-06 10:00:00');
        $fileB = $this->raw($shoot, $b, '2026-11-06 11:00:00');
        Sanctum::actingAs($artistB);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertOk();
        $receiptB = data_get($fileB->fresh()->metadata, 'active_work_raw_submission');
        $this->assertTrue(app(ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot->fresh(), $artistA));
        Sanctum::actingAs($artistA);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertOk();
        $this->assertNotNull(data_get($fileA->fresh()->metadata, 'active_work_raw_submission.submitted_at'));
        $this->assertSame($receiptB, data_get($fileB->fresh()->metadata, 'active_work_raw_submission'));
    }

    public function test_paused_cycles_and_unassigned_roles_cannot_submit_fresh_input(): void
    {
        [$shoot, $done, $a, $b, $artistA] = $this->fixture();
        $file = $this->raw($shoot, $a, '2026-11-06 10:00:00');
        $shoot->forceFill(['workflow_status' => 'on_hold'])->save();
        Sanctum::actingAs($artistA);
        $this->assertFalse(app(ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot->fresh(), $artistA));
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertConflict();
        $this->assertNull(data_get($file->fresh()->metadata, 'active_work_raw_submission'));
        $shoot->forceFill(['status' => 'active', 'workflow_status' => 'active'])->save();
        $unassigned = User::factory()->photographer()->create();
        $this->assertFalse(app(ShootSubmissionCapabilityService::class)->canSubmitRaw($shoot->fresh(), $unassigned));
        Sanctum::actingAs($unassigned);
        $this->postJson('/api/shoots/'.$shoot->id.'/upload/finalize-raw')->assertForbidden();
    }
}
