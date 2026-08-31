<?php

namespace Tests\Unit\Scanning;

use App\Jobs\FinalizeIguideOfflinePackageJob;
use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\Scanning\FileScanService;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FileScanServiceTest extends TestCase
{
    private FileScanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FileScanService;
    }

    /**
     * Build an in-memory ShootFile that records save() without touching the DB,
     * keeping the state-transition logic under test pure.
     */
    private function file(string $scanStatus = ShootFile::SCAN_STATUS_QUARANTINED): ShootFile
    {
        $file = new class extends ShootFile
        {
            public bool $persisted = false;

            public function save(array $options = []): bool
            {
                $this->persisted = true;

                return true;
            }
        };

        $file->id = 101;
        $file->shoot_id = 7;
        $file->filename = 'photo.jpg';
        $file->scan_status = $scanStatus;

        return $file;
    }

    #[Test]
    public function record_result_maps_a_clean_verdict_to_clean_status(): void
    {
        $file = $this->file();

        $this->service->recordResult($file, ClamAvScanResult::clean());

        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status);
        $this->assertNotNull($file->scan_result);
        $this->assertNotNull($file->scanned_at);
        $this->assertTrue($file->persisted);
    }

    #[Test]
    public function record_result_maps_an_infected_verdict_to_infected_status_and_records_signature(): void
    {
        $file = $this->file();

        $this->service->recordResult($file, ClamAvScanResult::infected('Eicar-Test-Signature'));

        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $file->scan_status);
        $this->assertSame('Eicar-Test-Signature', $file->scan_result);
        $this->assertNotNull($file->scanned_at);
        $this->assertTrue($file->persisted);
    }

    #[Test]
    public function record_result_accepts_a_plain_verdict_string(): void
    {
        $clean = $this->file();
        $infected = $this->file();

        $this->service->recordResult($clean, 'clean');
        $this->service->recordResult($infected, 'infected');

        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $clean->scan_status);
        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $infected->scan_status);
    }

    #[Test]
    public function release_dispatches_downstream_processing_only_for_clean_files(): void
    {
        Queue::fake();

        $clean = $this->file(ShootFile::SCAN_STATUS_CLEAN);

        $released = $this->service->release($clean);

        $this->assertTrue($released);
        Queue::assertPushed(ProcessImageJob::class, 1);
    }

    #[Test]
    public function release_refuses_to_release_files_that_are_not_clean(): void
    {
        Queue::fake();

        foreach ([
            ShootFile::SCAN_STATUS_QUARANTINED,
            ShootFile::SCAN_STATUS_INFECTED,
            ShootFile::SCAN_STATUS_FAILED,
        ] as $status) {
            $file = $this->file($status);

            $released = $this->service->release($file);

            $this->assertFalse($released, "Status {$status} must not be released");
        }

        Queue::assertNothingPushed();
    }

    #[Test]
    public function release_routes_a_clean_offline_iguide_package_away_from_image_processing(): void
    {
        Queue::fake();

        $file = $this->file(ShootFile::SCAN_STATUS_CLEAN);
        $file->media_type = ShootFile::MEDIA_TYPE_IGUIDE;
        $file->metadata = ['kind' => ShootFile::IGUIDE_OFFLINE_PACKAGE_KIND, 'upload_id' => 'upload-1'];

        $this->assertTrue($this->service->release($file));

        Queue::assertPushed(FinalizeIguideOfflinePackageJob::class, 1);
        Queue::assertNotPushed(ProcessImageJob::class);
    }

    #[Test]
    public function flag_infected_sets_infected_status_and_records_the_result(): void
    {
        $file = $this->file();

        $this->service->flagInfected($file, ClamAvScanResult::infected('Win.Test.EICAR'));

        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $file->scan_status);
        $this->assertSame('Win.Test.EICAR', $file->scan_result);
        $this->assertNotNull($file->scanned_at);
        $this->assertTrue($file->persisted);
    }

    #[Test]
    public function flag_infected_records_a_generic_reason_when_no_verdict_is_supplied(): void
    {
        $file = $this->file();

        $this->service->flagInfected($file);

        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $file->scan_status);
        $this->assertSame('infected', $file->scan_result);
    }

    #[Test]
    public function flag_failed_transitions_a_quarantined_file_to_failed_with_a_reason(): void
    {
        $file = $this->file(ShootFile::SCAN_STATUS_QUARANTINED);

        $this->service->flagFailed($file, 'scan_unavailable: clamd refused connection');

        $this->assertSame(ShootFile::SCAN_STATUS_FAILED, $file->scan_status);
        $this->assertStringContainsString('scan_unavailable', (string) $file->scan_result);
        $this->assertNotNull($file->scanned_at);
        $this->assertTrue($file->persisted);
    }

    #[Test]
    public function flag_failed_falls_back_to_a_generic_reason_when_none_is_supplied(): void
    {
        $file = $this->file(ShootFile::SCAN_STATUS_QUARANTINED);

        $this->service->flagFailed($file);

        $this->assertSame(ShootFile::SCAN_STATUS_FAILED, $file->scan_status);
        $this->assertSame('scan_unavailable', $file->scan_result);
    }

    #[Test]
    public function flag_failed_refuses_to_demote_a_clean_or_infected_file(): void
    {
        // The (quarantined|failed) -> failed gate prevents a stale failed()
        // handler from clobbering a verdict that has, by then, already been
        // recorded by a successful re-scan.
        foreach ([ShootFile::SCAN_STATUS_CLEAN, ShootFile::SCAN_STATUS_INFECTED] as $terminal) {
            $file = $this->file($terminal);

            $this->service->flagFailed($file, 'late retry exhaustion');

            $this->assertSame($terminal, $file->scan_status, "{$terminal} must not be demoted to failed");
            $this->assertFalse($file->persisted, "no save() should occur for {$terminal}");
        }
    }

    #[Test]
    public function notify_admin_infected_does_not_throw_when_notifications_are_unavailable(): void
    {
        $file = $this->file(ShootFile::SCAN_STATUS_INFECTED);
        $file->scan_result = 'Eicar-Test-Signature';

        // Should complete cleanly (logging the event) even when the optional
        // in-app notifications infrastructure is absent.
        $this->service->notifyAdminInfected($file);

        $this->assertTrue(true);
    }
}
