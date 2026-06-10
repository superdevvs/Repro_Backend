<?php

namespace Tests\Unit\Scanning;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\ShootMediaArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 13.6 — preview/download gate (Req 15.7).
 *
 * The ZIP/archive delivery path funnels every file selection through
 * {@see ShootMediaArchiveService::getFilesForType()}. This test pins the
 * invariant that an infected file is never packaged for delivery while
 * clean, quarantined-but-not-infected, and legacy (null scan_status) files
 * remain servable so existing media keeps flowing.
 */
class ArchiveDeliveryScanFilterTest extends TestCase
{
    use RefreshDatabase;

    private function rawFile(Shoot $shoot, ?string $scanStatus, string $name): ShootFile
    {
        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => $name,
            'stored_filename' => $name,
            'path' => "shoots/{$shoot->id}/{$name}",
            'storage_path' => "shoots/{$shoot->id}/{$name}",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 128,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
            'scan_status' => $scanStatus,
            'uploaded_by' => $this->uploaderId(),
        ]);
    }

    private function uploaderId(): int
    {
        return \App\Models\User::factory()->create([
            'role' => 'photographer',
            'email' => 'archive-scan-' . uniqid() . '@test.com',
        ])->id;
    }

    #[Test]
    public function infected_files_are_excluded_from_archive_delivery_while_others_remain(): void
    {
        $shoot = Shoot::factory()->create();

        $clean = $this->rawFile($shoot, ShootFile::SCAN_STATUS_CLEAN, 'clean.jpg');
        $infected = $this->rawFile($shoot, ShootFile::SCAN_STATUS_INFECTED, 'infected.jpg');
        $quarantined = $this->rawFile($shoot, ShootFile::SCAN_STATUS_QUARANTINED, 'quarantined.jpg');

        $service = app(ShootMediaArchiveService::class);
        $ids = $service->getFilesForType($shoot, 'raw')->pluck('id')->all();

        // Req 15.7: the infected file must never appear in the delivery set.
        $this->assertNotContains($infected->id, $ids, 'Infected files must be withheld from archive delivery.');

        // Clean and quarantined (non-infected) files are not hard-blocked from
        // this archive selection seam — only a positive infected verdict blocks.
        // (Legacy null-status inclusion is covered at the model layer in
        // ShootFileScanGatingTest; the DB column is NOT NULL so it cannot hold
        // null here.)
        $this->assertContains($clean->id, $ids);
        $this->assertContains($quarantined->id, $ids);
    }
}
