<?php

namespace Tests\Unit\Scanning\Properties;

use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Services\Scanning\FileScanService;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 13: Quarantined and infected files are withheld
 *
 * **Validates: Requirements 14.3, 15.1, 15.4, 15.7**
 *
 * Universal invariant under test: for every {@see ShootFile} whose
 * {@see ShootFile::$scan_status} is in
 * {{@see ShootFile::SCAN_STATUS_QUARANTINED}, {@see ShootFile::SCAN_STATUS_INFECTED},
 *  {@see ShootFile::SCAN_STATUS_FAILED}}, the file is withheld from downstream
 * processing — and ONLY {@see ShootFile::SCAN_STATUS_CLEAN} permits release.
 *
 * Concretely the property asserts, across every randomly generated and every
 * deterministic-edge {@see ShootFile} fixture:
 *
 *   1. {@see FileScanService::release()} returns `true` iff
 *      `scan_status === 'clean'` and `false` for every other status (Req 14.3,
 *      15.1, 15.4).
 *   2. {@see ProcessImageJob} is dispatched (counted via {@see Queue::fake()})
 *      iff `release()` returned true — i.e. exactly once per clean file and
 *      never for quarantined / infected / failed files. This is the
 *      "withheld from downstream processing" half of the invariant
 *      (Req 14.3, 15.1).
 *   3. {@see FileScanService::release()} is idempotent for non-clean files:
 *      repeated calls keep returning false and never enqueue a job (Req 15.4 —
 *      "no release without a recorded clean result").
 *
 * No property-based testing library is configured for the backend, so this
 * test follows the same deterministic-generator approach used by
 * {@see \Tests\Unit\Shoots\ShootEditingPayloadFilteringPropertyTest} and
 * {@see \Tests\Unit\Shoots\ShootDatePreservationPropertyTest}: a fixed table
 * of edge cases (one fixture per scan_status plus boundary scenarios) plus a
 * seeded PRNG that produces 25+ randomized {scan_status, file_id, shoot_id}
 * triples. The status of every random fixture is drawn uniformly from the
 * four-state space so each branch of the invariant is exercised many times.
 *
 * **Companion coverage (preview/download endpoints, Req 15.7).** The
 * downstream gates in {@see \App\Jobs\ProcessImageJob}/
 * {@see \App\Jobs\UploadShootAlbumMediaJob} (via
 * {@see ShootFile::isClearedForProcessing()}) and the preview/download
 * controller endpoints (`ShootMediaController::previewFile`,
 * `ShootMediaController::downloadMedia`, `bulkDownloadMedia`,
 * `DownloadSelectedShootFilesAction`, `ImageDownloadController`, the
 * editor-facing raw download, and the archive selection seam in
 * {@see \App\Services\Shoots\ShootMediaArchiveService::getFilesForType()})
 * are wired against `scan_status` under task 13.6 (infected files blocked
 * via {@see ShootFile::isBlockedFromDelivery()}). Those controller/archive
 * gates are covered by {@see \Tests\Unit\Scanning\ShootFileScanGatingTest}
 * and {@see \Tests\Unit\Scanning\ArchiveDeliveryScanFilterTest}. This
 * property test drives the release seam —
 * {@see FileScanService::release()} (the sole dispatch point for
 * {@see ProcessImageJob}).
 */
class WithholdingPropertyTest extends TestCase
{
    private FileScanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FileScanService();
    }

    /**
     * Build an in-memory {@see ShootFile} that records save() without touching
     * the database, mirroring {@see \Tests\Unit\Scanning\FileScanServiceTest}'s
     * pattern. Keeping the property pure-in-memory means the invariant is
     * verified across many cases without a DB round trip per case.
     */
    private function file(string $scanStatus, int $id = 1, int $shootId = 1): ShootFile
    {
        $file = new class extends ShootFile {
            public bool $persisted = false;

            public function save(array $options = []): bool
            {
                $this->persisted = true;

                return true;
            }
        };

        $file->id = $id;
        $file->shoot_id = $shootId;
        $file->filename = "fixture-{$id}.jpg";
        $file->scan_status = $scanStatus;

        return $file;
    }

    /**
     * Deterministic generator covering every scan_status value (the four-state
     * domain of the invariant) plus a seeded PRNG producing 25 randomized
     * fixtures. The randomized branch draws scan_status uniformly from the
     * four valid values so every state gets repeated coverage and every
     * `(file_id, shoot_id)` shape is exercised.
     *
     * Each case yields `[string $label, string $scanStatus, int $fileId, int $shootId]`.
     *
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function scanStatusProvider(): iterable
    {
        // Deterministic edge cases — one fixture per scan_status (the full
        // domain of the invariant) plus a few boundary id shapes so the
        // property's truth value is checked at every status corner.
        $edges = [
            'edge: clean (release allowed)' => [ShootFile::SCAN_STATUS_CLEAN, 1, 1],
            'edge: quarantined (withheld)' => [ShootFile::SCAN_STATUS_QUARANTINED, 2, 1],
            'edge: infected (withheld + flagged)' => [ShootFile::SCAN_STATUS_INFECTED, 3, 1],
            'edge: failed (withheld but re-scannable)' => [ShootFile::SCAN_STATUS_FAILED, 4, 1],
            // Same status, different ids — the property must hold regardless
            // of identity.
            'edge: clean alt id' => [ShootFile::SCAN_STATUS_CLEAN, 9999, 7],
            'edge: quarantined alt id' => [ShootFile::SCAN_STATUS_QUARANTINED, 9999, 7],
            'edge: infected alt id' => [ShootFile::SCAN_STATUS_INFECTED, 9999, 7],
            'edge: failed alt id' => [ShootFile::SCAN_STATUS_FAILED, 9999, 7],
        ];

        foreach ($edges as $label => [$status, $id, $shootId]) {
            yield $label => [$label, $status, $id, $shootId];
        }

        // Seeded PRNG so the generator is reproducible across runs (same
        // approach used by the editing-payload property test).
        mt_srand(20260615);

        $statuses = [
            ShootFile::SCAN_STATUS_QUARANTINED,
            ShootFile::SCAN_STATUS_CLEAN,
            ShootFile::SCAN_STATUS_INFECTED,
            ShootFile::SCAN_STATUS_FAILED,
        ];

        $randomCases = 25;
        for ($i = 0; $i < $randomCases; $i++) {
            $status = $statuses[mt_rand(0, count($statuses) - 1)];
            $fileId = mt_rand(1, 100000);
            $shootId = mt_rand(1, 10000);

            yield "random: case {$i} ({$status} id={$fileId})"
                => ["random: case {$i}", $status, $fileId, $shootId];
        }
    }

    /**
     * Property 13 (release returns true iff clean):
     *
     *   ∀ ShootFile f.
     *      FileScanService::release(f) == true  ⇔  f.scan_status == 'clean'
     *
     * This is the single statement of the withholding invariant for the
     * release seam (Req 14.3, 15.1, 15.4). Quarantined / infected / failed
     * files MUST refuse release; clean files MUST release.
     */
    #[Test]
    #[DataProvider('scanStatusProvider')]
    public function release_returns_true_iff_scan_status_is_clean(
        string $label,
        string $scanStatus,
        int $fileId,
        int $shootId,
    ): void {
        Queue::fake();

        $file = $this->file($scanStatus, $fileId, $shootId);

        $released = $this->service->release($file);

        $expected = $scanStatus === ShootFile::SCAN_STATUS_CLEAN;
        $this->assertSame(
            $expected,
            $released,
            "[{$label}] release() must return "
            . ($expected ? 'true' : 'false')
            . " for scan_status='{$scanStatus}'"
        );
    }

    /**
     * Property 13 (downstream job is queued iff clean):
     *
     *   ∀ ShootFile f.
     *      ProcessImageJob is dispatched after release(f)  ⇔  f.scan_status == 'clean'
     *
     * Pairs with the previous assertion to nail the "withheld from downstream
     * processing" half of Req 14.3 / 15.1: even if release() were to return
     * the wrong boolean, no ProcessImageJob may be queued for a non-clean
     * file, and exactly one must be queued for a clean file.
     */
    #[Test]
    #[DataProvider('scanStatusProvider')]
    public function process_image_job_is_dispatched_iff_scan_status_is_clean(
        string $label,
        string $scanStatus,
        int $fileId,
        int $shootId,
    ): void {
        Queue::fake();

        $file = $this->file($scanStatus, $fileId, $shootId);

        $this->service->release($file);

        if ($scanStatus === ShootFile::SCAN_STATUS_CLEAN) {
            Queue::assertPushed(
                ProcessImageJob::class,
                1,
                "[{$label}] exactly one ProcessImageJob must be queued for a clean file"
            );
        } else {
            Queue::assertNotPushed(
                ProcessImageJob::class,
                "[{$label}] no ProcessImageJob may be queued for scan_status='{$scanStatus}'"
            );
        }
    }

    /**
     * Property 13 (idempotence of withholding):
     *
     *   ∀ ShootFile f, ∀ n ≥ 1.
     *      f.scan_status ≠ 'clean'
     *        ⇒ release(f) called n times returns false n times
     *           AND no ProcessImageJob is ever queued.
     *
     * Closes the "no release without a recorded clean result" loophole
     * (Req 15.4): a non-clean file cannot be coaxed past the gate by repeated
     * release attempts. Three calls cover the realistic worst case (initial
     * upload + retry + late re-call from a race) without coupling to a
     * specific upper bound.
     */
    #[Test]
    #[DataProvider('scanStatusProvider')]
    public function repeated_release_calls_never_leak_a_non_clean_file(
        string $label,
        string $scanStatus,
        int $fileId,
        int $shootId,
    ): void {
        if ($scanStatus === ShootFile::SCAN_STATUS_CLEAN) {
            // Property only constrains non-clean files; the clean branch is
            // fully covered by the previous two tests.
            $this->assertTrue(true);

            return;
        }

        Queue::fake();

        $file = $this->file($scanStatus, $fileId, $shootId);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $released = $this->service->release($file);

            $this->assertFalse(
                $released,
                "[{$label}] release() attempt {$attempt} must return false for scan_status='{$scanStatus}'"
            );
        }

        Queue::assertNotPushed(
            ProcessImageJob::class,
            "[{$label}] repeated release() calls must never queue ProcessImageJob for scan_status='{$scanStatus}'"
        );
    }
}
