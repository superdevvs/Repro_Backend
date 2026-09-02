<?php

namespace Tests\Feature;

use App\Jobs\ProcessImageJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScanFailedFileRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function file(Shoot $shoot, User $uploader, string $status, string $filename = 'listing.cr3'): ShootFile
    {
        $path = "shoots/{$shoot->id}/todo/{$filename}";

        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => $filename,
            'stored_filename' => $filename,
            'path' => $path,
            'file_type' => 'image/x-canon-cr3',
            'file_size' => 2048,
            'media_type' => 'raw',
            'uploaded_by' => $uploader->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
            'scan_status' => $status,
            'scan_result' => $status === ShootFile::SCAN_STATUS_FAILED ? 'scanner_unavailable' : null,
            'scanned_at' => now(),
        ]);
    }

    #[Test]
    public function superadmin_can_download_only_a_scan_failed_original_as_an_audited_attachment(): void
    {
        Storage::fake('public');
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create();
        $file = $this->file($shoot, $superadmin, ShootFile::SCAN_STATUS_FAILED);
        Storage::disk('public')->put($file->path, 'untrusted-cr3-bytes');
        Sanctum::actingAs($superadmin);

        $response = $this->get("/api/shoots/{$shoot->id}/files/{$file->id}/scan-failed-original");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('user_activity_logs', [
            'actor_user_id' => $superadmin->id,
            'event_type' => 'shoot_file.scan_failed_original_downloaded',
            'target_id' => $file->id,
        ]);
    }

    #[Test]
    public function infected_and_quarantined_files_never_use_the_recovery_download(): void
    {
        Storage::fake('public');
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($superadmin);

        foreach ([ShootFile::SCAN_STATUS_INFECTED, ShootFile::SCAN_STATUS_QUARANTINED] as $status) {
            $file = $this->file($shoot, $superadmin, $status, $status.'.cr3');
            Storage::disk('public')->put($file->path, $status);

            $this->getJson("/api/shoots/{$shoot->id}/files/{$file->id}/scan-failed-original")
                ->assertStatus(409)
                ->assertJsonPath('scan_status', $status);
        }
    }

    #[Test]
    public function ordinary_admin_cannot_use_the_recovery_download(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        $file = $this->file($shoot, $admin, ShootFile::SCAN_STATUS_FAILED);
        Storage::disk('public')->put($file->path, 'bytes');
        Sanctum::actingAs($admin);

        $this->getJson("/api/shoots/{$shoot->id}/files/{$file->id}/scan-failed-original")
            ->assertForbidden();
    }

    #[Test]
    public function superadmin_can_rebuild_a_clean_raw_preview_without_erasing_existing_paths(): void
    {
        Queue::fake([ProcessImageJob::class]);
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create();
        $file = $this->file($shoot, $superadmin, ShootFile::SCAN_STATUS_CLEAN);
        $file->update([
            'thumbnail_path' => 'shoots/old-thumb.jpg',
            'web_path' => 'shoots/old-web.jpg',
            'processed_at' => now(),
            'processing_failed_at' => now(),
            'processing_error' => 'decoder failed',
        ]);
        Sanctum::actingAs($superadmin);

        $this->postJson("/api/shoots/{$shoot->id}/files/{$file->id}/rebuild-preview")
            ->assertStatus(202);

        $file->refresh();
        $this->assertNull($file->processed_at);
        $this->assertNull($file->processing_failed_at);
        $this->assertNull($file->processing_error);
        $this->assertSame('shoots/old-thumb.jpg', $file->thumbnail_path);
        $this->assertSame('shoots/old-web.jpg', $file->web_path);
        Queue::assertPushed(ProcessImageJob::class);
    }

    #[Test]
    public function preview_rebuild_never_bypasses_the_scan_gate(): void
    {
        Queue::fake([ProcessImageJob::class]);
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $shoot = Shoot::factory()->create();
        $file = $this->file($shoot, $superadmin, ShootFile::SCAN_STATUS_FAILED);
        Sanctum::actingAs($superadmin);

        $this->postJson("/api/shoots/{$shoot->id}/files/{$file->id}/rebuild-preview")
            ->assertStatus(409);

        Queue::assertNotPushed(ProcessImageJob::class);
    }
}
