<?php

namespace Tests\Unit\Scanning;

use App\Models\ShootFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * QA #14 (hardened gating): a shoot file is served only once it has a recorded
 * CLEAN verdict. Infected, quarantined (not yet scanned), and failed files are
 * all withheld from preview/download/zip — fail-closed. Legacy files (null
 * status) predate the scanning feature and remain servable.
 *
 * These assertions exercise the in-model gate directly and require no database
 * or clamd, so they run everywhere and pin the security contract.
 */
class ShootFileDeliveryGatingTest extends TestCase
{
    private function fileWithStatus(?string $status): ShootFile
    {
        $file = new ShootFile();
        $file->scan_status = $status;

        return $file;
    }

    #[Test]
    public function clean_files_are_servable(): void
    {
        $this->assertFalse($this->fileWithStatus(ShootFile::SCAN_STATUS_CLEAN)->isBlockedFromDelivery());
    }

    #[Test]
    public function legacy_null_status_files_remain_servable(): void
    {
        $this->assertFalse($this->fileWithStatus(null)->isBlockedFromDelivery());
    }

    #[Test]
    public function infected_files_are_blocked(): void
    {
        $this->assertTrue($this->fileWithStatus(ShootFile::SCAN_STATUS_INFECTED)->isBlockedFromDelivery());
    }

    #[Test]
    public function quarantined_files_are_blocked_until_cleared(): void
    {
        $this->assertTrue($this->fileWithStatus(ShootFile::SCAN_STATUS_QUARANTINED)->isBlockedFromDelivery());
    }

    #[Test]
    public function failed_scan_files_are_blocked(): void
    {
        $this->assertTrue($this->fileWithStatus(ShootFile::SCAN_STATUS_FAILED)->isBlockedFromDelivery());
    }
}
