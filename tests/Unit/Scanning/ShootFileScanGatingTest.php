<?php

namespace Tests\Unit\Scanning;

use App\Models\ShootFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Validates the quarantine gating predicates on ShootFile that drive both the
 * downstream-job withholding (Req 14.3 / 15.1 / 15.4) and the infected
 * preview/download block (Req 15.7).
 */
class ShootFileScanGatingTest extends TestCase
{
    private function file(?string $scanStatus): ShootFile
    {
        $file = new ShootFile();
        $file->scan_status = $scanStatus;

        return $file;
    }

    #[Test]
    public function only_clean_or_legacy_null_files_are_cleared_for_processing(): void
    {
        $this->assertTrue($this->file(ShootFile::SCAN_STATUS_CLEAN)->isClearedForProcessing());
        // Legacy files predating the scanning feature (no scan row) are allowed.
        $this->assertTrue($this->file(null)->isClearedForProcessing());

        $this->assertFalse($this->file(ShootFile::SCAN_STATUS_QUARANTINED)->isClearedForProcessing());
        $this->assertFalse($this->file(ShootFile::SCAN_STATUS_INFECTED)->isClearedForProcessing());
        $this->assertFalse($this->file(ShootFile::SCAN_STATUS_FAILED)->isClearedForProcessing());
    }

    #[Test]
    public function every_non_clean_scanned_file_is_blocked_from_delivery(): void
    {
        $this->assertTrue($this->file(ShootFile::SCAN_STATUS_INFECTED)->isBlockedFromDelivery());

        $this->assertFalse($this->file(ShootFile::SCAN_STATUS_CLEAN)->isBlockedFromDelivery());
        $this->assertTrue($this->file(ShootFile::SCAN_STATUS_QUARANTINED)->isBlockedFromDelivery());
        $this->assertTrue($this->file(ShootFile::SCAN_STATUS_FAILED)->isBlockedFromDelivery());
        $this->assertFalse($this->file(null)->isBlockedFromDelivery());
    }
}
