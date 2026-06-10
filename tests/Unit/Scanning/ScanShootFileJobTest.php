<?php

namespace Tests\Unit\Scanning;

use App\Exceptions\Scanning\ClamAvUnavailable;
use App\Jobs\ProcessImageJob;
use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Scanning\ClamAvClient;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\Scanning\FileScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the scan + verdict flow owned by {@see ScanShootFileJob} (Req 14.4,
 * 15.1, 15.2, 15.4, 15.6).
 *
 *  - Clean verdict   -> recordResult + release (dispatches ProcessImageJob).
 *  - Infected verdict-> flagInfected + admin notification.
 *  - ClamAvUnavailable bubbles up so the queue retries; file stays quarantined.
 *  - failed() handler flips a still-quarantined file to `failed`, where it is
 *    re-scannable via the retry-scan endpoint (Req 15.8).
 */
class ScanShootFileJobTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    /**
     * Persist a {@see ShootFile} with a real local source file under the public
     * disk so {@see ClamAvClient::scan()} would, in principle, have something
     * to read. The actual ClamAvClient is mocked — only the path resolver
     * needs to find a local copy.
     */
    private function makeQuarantinedFile(string $contents = 'harmless'): ShootFile
    {
        $shoot = Shoot::factory()->create();
        $uploader = \App\Models\User::factory()->create([
            'role' => 'photographer',
            'email' => 'scan-test-uploader-' . uniqid() . '@test.com',
        ]);

        $relativePath = "shoots/{$shoot->id}/scan-test-" . uniqid() . '.jpg';
        Storage::disk('public')->put($relativePath, $contents);

        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'scan-test.jpg',
            'stored_filename' => 'scan-test.jpg',
            'path' => $relativePath,
            'storage_path' => $relativePath,
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => strlen($contents),
            'media_type' => 'raw',
            'uploaded_by' => $uploader->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
            'scan_status' => ShootFile::SCAN_STATUS_QUARANTINED,
        ]);
    }

    #[Test]
    public function clean_verdict_records_result_and_releases_for_downstream_processing(): void
    {
        Storage::fake('public');
        Queue::fake();

        $file = $this->makeQuarantinedFile();

        $clamAv = Mockery::mock(ClamAvClient::class);
        $clamAv->shouldReceive('scan')
            ->once()
            ->andReturn(ClamAvScanResult::clean());

        $job = new ScanShootFileJob($file->id);
        $job->handle($clamAv, app(FileScanService::class));

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status);
        $this->assertNotNull($file->scanned_at, 'scanned_at must be set after a clean verdict.');
        // release() is the single dispatch point that lets a clean file
        // proceed to ProcessImageJob (Req 14.4).
        Queue::assertPushed(ProcessImageJob::class, 1);
    }

    #[Test]
    public function infected_verdict_flags_the_file_and_blocks_downstream_processing(): void
    {
        Storage::fake('public');
        Queue::fake();

        $file = $this->makeQuarantinedFile('eicar-payload');

        $clamAv = Mockery::mock(ClamAvClient::class);
        $clamAv->shouldReceive('scan')
            ->once()
            ->andReturn(ClamAvScanResult::infected('Eicar-Test-Signature'));

        $job = new ScanShootFileJob($file->id);
        $job->handle($clamAv, app(FileScanService::class));

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $file->scan_status);
        $this->assertSame('Eicar-Test-Signature', $file->scan_result);
        $this->assertNotNull($file->scanned_at);
        // Infected files must NEVER trigger downstream processing (Req 15.1).
        Queue::assertNotPushed(ProcessImageJob::class);
    }

    #[Test]
    public function clamav_unavailable_bubbles_up_and_leaves_the_file_quarantined(): void
    {
        Storage::fake('public');
        Queue::fake();

        $file = $this->makeQuarantinedFile();

        $clamAv = Mockery::mock(ClamAvClient::class);
        $clamAv->shouldReceive('scan')
            ->once()
            ->andThrow(new ClamAvUnavailable('clamd refused connection'));

        $job = new ScanShootFileJob($file->id);

        $thrown = null;
        try {
            $job->handle($clamAv, app(FileScanService::class));
        } catch (ClamAvUnavailable $e) {
            $thrown = $e;
        }

        // Re-throw is required so Laravel retries with the configured backoff (Req 15.2).
        $this->assertNotNull($thrown, 'ClamAvUnavailable must propagate so the queue retries.');

        // The file remains quarantined — never released, never failed mid-retry.
        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_QUARANTINED, $file->scan_status);
        $this->assertNull($file->scanned_at, 'No verdict should be recorded on a connect failure.');
        Queue::assertNotPushed(ProcessImageJob::class);
    }

    #[Test]
    public function failed_handler_marks_the_file_failed_so_it_can_be_re_scanned(): void
    {
        Storage::fake('public');
        Queue::fake();

        $file = $this->makeQuarantinedFile();

        $job = new ScanShootFileJob($file->id);
        // Simulate Laravel calling failed() once $tries is exhausted.
        $job->failed(new ClamAvUnavailable('clamd unreachable after retries'));

        $file->refresh();
        $this->assertSame(
            ShootFile::SCAN_STATUS_FAILED,
            $file->scan_status,
            'Retry exhaustion must transition the file to failed (Req 15.2).'
        );
        $this->assertNotNull($file->scanned_at);
        $this->assertStringContainsString('scan_unavailable', (string) $file->scan_result);
        // A `failed` file is still withheld — no downstream dispatch.
        Queue::assertNotPushed(ProcessImageJob::class);
    }

    #[Test]
    public function failed_handler_does_not_demote_an_already_clean_file(): void
    {
        // If a delayed failed() handler fires for a job whose file was, by then,
        // already determined clean (e.g. after a manual rescan that succeeded),
        // the file must NOT be demoted from `clean` to `failed`. The
        // (quarantined|failed)->failed gate in FileScanService::flagFailed
        // protects that invariant.
        Storage::fake('public');

        $file = $this->makeQuarantinedFile();
        $file->scan_status = ShootFile::SCAN_STATUS_CLEAN;
        $file->save();

        $job = new ScanShootFileJob($file->id);
        $job->failed(new ClamAvUnavailable('late retry exhaustion'));

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status);
    }

    #[Test]
    public function handle_is_idempotent_and_skips_files_that_have_already_been_scanned(): void
    {
        Storage::fake('public');
        Queue::fake();

        $file = $this->makeQuarantinedFile();
        $file->scan_status = ShootFile::SCAN_STATUS_CLEAN;
        $file->save();

        $clamAv = Mockery::mock(ClamAvClient::class);
        // The job MUST NOT re-scan an already-determined file — calling scan()
        // here would be a regression of the idempotency guarantee.
        $clamAv->shouldNotReceive('scan');

        $job = new ScanShootFileJob($file->id);
        $job->handle($clamAv, app(FileScanService::class));

        Queue::assertNotPushed(ProcessImageJob::class);
    }

    #[Test]
    public function handle_returns_quietly_when_the_shoot_file_no_longer_exists(): void
    {
        Storage::fake('public');
        Queue::fake();

        $clamAv = Mockery::mock(ClamAvClient::class);
        $clamAv->shouldNotReceive('scan');

        $job = new ScanShootFileJob(999_999_999);
        $job->handle($clamAv, app(FileScanService::class));

        // A missing row is a no-op — no exception, no dispatch.
        Queue::assertNotPushed(ProcessImageJob::class);
        $this->assertTrue(true);
    }

    #[Test]
    public function rescanning_a_failed_file_succeeds_and_records_a_clean_result(): void
    {
        // Simulates the retry-scan endpoint flow (Req 15.8): a `failed` file
        // gets re-enqueued, the scan succeeds this time, and the file
        // transitions through quarantined -> clean.
        Storage::fake('public');
        Queue::fake();

        $file = $this->makeQuarantinedFile();
        $file->scan_status = ShootFile::SCAN_STATUS_FAILED;
        $file->scan_result = 'scan_unavailable: clamd was down earlier';
        $file->save();

        $clamAv = Mockery::mock(ClamAvClient::class);
        $clamAv->shouldReceive('scan')
            ->once()
            ->andReturn(ClamAvScanResult::clean());

        $job = new ScanShootFileJob($file->id);
        $job->handle($clamAv, app(FileScanService::class));

        $file->refresh();
        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status);
        Queue::assertPushed(ProcessImageJob::class, 1);
    }
}
