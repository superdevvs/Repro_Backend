<?php

namespace Tests\Feature;

use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the retry-scan endpoint added by task 13.7 (Req 15.8).
 *
 * `POST /api/shoots/{shoot}/files/{file}/rescan` re-enqueues
 * {@see ScanShootFileJob} for a {@see ShootFile} whose status is `failed`,
 * resetting it to `quarantined` so it stays withheld until a fresh clean
 * verdict is recorded. Non-failed states are rejected with `409 Conflict`
 * so a terminal verdict is never silently overwritten.
 */
class FileScanRescanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'rescan-admin-' . uniqid() . '@test.com',
        ]);
    }

    private function createShoot(): Shoot
    {
        return Shoot::factory()->create();
    }

    private function createShootFile(Shoot $shoot, string $scanStatus): ShootFile
    {
        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'rescan-' . uniqid() . '.jpg',
            'stored_filename' => 'rescan.jpg',
            'path' => 'shoots/' . $shoot->id . '/raw/rescan.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'raw',
            'uploaded_by' => $this->admin->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
            'sort_order' => 0,
            'scan_status' => $scanStatus,
            'scan_result' => $scanStatus === ShootFile::SCAN_STATUS_FAILED ? 'scan_unavailable' : null,
            'scanned_at' => $scanStatus === ShootFile::SCAN_STATUS_FAILED ? now() : null,
        ]);
    }

    #[Test]
    public function rescan_resets_a_failed_file_to_quarantined_and_dispatches_scan_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, ShootFile::SCAN_STATUS_FAILED);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/rescan');

        $response->assertOk();
        $response->assertJson([
            'scan_status' => ShootFile::SCAN_STATUS_QUARANTINED,
        ]);

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_QUARANTINED, $file->scan_status);
        $this->assertNull($file->scan_result);
        $this->assertNull($file->scanned_at);

        Queue::assertPushed(ScanShootFileJob::class, function (ScanShootFileJob $job) use ($file) {
            return $job->shootFileId === $file->id;
        });
    }

    #[Test]
    public function rescan_rejects_a_clean_file_with_409_and_does_not_dispatch_a_scan_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, ShootFile::SCAN_STATUS_CLEAN);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/rescan');

        $response->assertStatus(409);
        $response->assertJson([
            'scan_status' => ShootFile::SCAN_STATUS_CLEAN,
        ]);

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status);

        Queue::assertNotPushed(ScanShootFileJob::class);
    }

    #[Test]
    public function rescan_rejects_an_infected_file_with_409_and_does_not_dispatch_a_scan_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, ShootFile::SCAN_STATUS_INFECTED);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/rescan');

        $response->assertStatus(409);
        $response->assertJson([
            'scan_status' => ShootFile::SCAN_STATUS_INFECTED,
        ]);

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $file->scan_status);

        Queue::assertNotPushed(ScanShootFileJob::class);
    }

    #[Test]
    public function rescan_rejects_a_still_quarantined_file_with_409_and_does_not_dispatch_a_scan_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, ShootFile::SCAN_STATUS_QUARANTINED);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/rescan');

        $response->assertStatus(409);
        $response->assertJson([
            'scan_status' => ShootFile::SCAN_STATUS_QUARANTINED,
        ]);

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_QUARANTINED, $file->scan_status);

        Queue::assertNotPushed(ScanShootFileJob::class);
    }

    #[Test]
    public function rescan_is_forbidden_for_non_admin_roles(): void
    {
        Queue::fake();

        $editor = User::factory()->create([
            'role' => 'editor',
            'email' => 'rescan-editor-' . uniqid() . '@test.com',
        ]);
        Sanctum::actingAs($editor);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, ShootFile::SCAN_STATUS_FAILED);

        $response = $this->postJson('/api/shoots/' . $shoot->id . '/files/' . $file->id . '/rescan');

        $response->assertStatus(403);

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_FAILED, $file->scan_status);

        Queue::assertNotPushed(ScanShootFileJob::class);
    }
}
