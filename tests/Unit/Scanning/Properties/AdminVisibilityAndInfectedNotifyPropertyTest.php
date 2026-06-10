<?php

namespace Tests\Unit\Scanning\Properties;

use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\Scanning\FileScanService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2,
 * Property 26: Scan status is admin-visible and infected files are blocked and notified
 *
 * **Validates: Requirements 15.5, 15.6, 15.7**
 *
 * Universal claims under test, for any {@see ShootFile} carried through the
 * scan verdict pipeline ({@see FileScanService}, mirroring the verdict-handling
 * branch of {@see \App\Jobs\ScanShootFileJob}):
 *
 *   (15.5) The scan status surfaced to the admin is ALWAYS exactly one of the
 *          four canonical states — `quarantined` (rendered "scanning"), `clean`,
 *          `infected`, or `failed`. This is the same {@see ShootFile::$scan_status}
 *          value that {@see \App\Services\Shoots\ShootMediaReadService} emits on
 *          every file payload for the admin scan-status badge, so asserting on it
 *          is asserting on what the admin sees.
 *
 *   (15.6) An infected verdict triggers EXACTLY ONE admin notification
 *          (notify-once); a clean verdict triggers none. The service's admin
 *          notification mechanism is {@see FileScanService::notifyAdminInfected()},
 *          which always emits a single admin-alert log line — and, where the
 *          optional in-app `App\Models\Notification` model/table is present, one
 *          record per admin. In this environment that model is absent, so the
 *          observable, deterministic notify side effect is the single admin-alert
 *          warning, counted here via {@see Log::spy()}.
 *
 *   (15.7) An infected file is blocked from preview/download
 *          ({@see ShootFile::isBlockedFromDelivery()} === true) AND is never
 *          released downstream ({@see FileScanService::release()} === false / no
 *          {@see ProcessImageJob}); a clean file is servable (not blocked) AND
 *          releasable.
 *
 * No property-based testing library is configured for the backend, so this test
 * follows the same deterministic-generator + seeded-PRNG approach used by its
 * sibling {@see WithholdingPropertyTest} and {@see ReleaseRequiresCleanPropertyTest}:
 * a fixed table of edge verdicts plus a seeded PRNG producing many randomized
 * verdicts drawn uniformly from the verdict space. Fixtures are pure in-memory
 * {@see ShootFile} instances (save() is stubbed) so the verdict→outcome mapping
 * is exercised across many cases without a DB round trip.
 *
 * Companion coverage: the release/withholding seam is owned by
 * {@see WithholdingPropertyTest} (Property 13) and {@see ReleaseRequiresCleanPropertyTest}
 * (Property 14); the gating predicates by {@see \Tests\Unit\Scanning\ShootFileScanGatingTest};
 * and verdict recording by {@see \Tests\Unit\Scanning\FileScanServiceTest}. This
 * test adds the distinct Property-26 claims: admin-visible status totality and
 * the exactly-once admin notification on infection.
 */
class AdminVisibilityAndInfectedNotifyPropertyTest extends TestCase
{
    /** The four canonical scan states surfaced to the admin (Req 15.5). */
    private const ADMIN_VISIBLE_STATES = [
        ShootFile::SCAN_STATUS_QUARANTINED,
        ShootFile::SCAN_STATUS_CLEAN,
        ShootFile::SCAN_STATUS_INFECTED,
        ShootFile::SCAN_STATUS_FAILED,
    ];

    /** Message emitted by FileScanService::notifyAdminInfected — the notify side effect. */
    private const ADMIN_ALERT_MESSAGE = 'Infected upload detected and withheld from delivery.';

    private FileScanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FileScanService();
    }

    /**
     * Build an in-memory {@see ShootFile} whose save() is a no-op, mirroring
     * {@see FileScanServiceTest}/{@see WithholdingPropertyTest}. Keeps the
     * verdict→outcome mapping pure across many generated cases.
     */
    private function file(string $scanStatus = ShootFile::SCAN_STATUS_QUARANTINED, int $id = 1, int $shootId = 1): ShootFile
    {
        $file = new class extends ShootFile {
            public function save(array $options = []): bool
            {
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
     * Mirror of the verdict-handling branch in {@see \App\Jobs\ScanShootFileJob}:
     * record the result, then release on clean / flag + notify on infected. The
     * job is the single production caller of this branch; reproducing the (small,
     * stable) branch here lets the property assert the end-to-end
     * verdict→(status, block, notify) outcome.
     *
     * @return bool whether the file was released downstream
     */
    private function handleVerdict(ShootFile $file, ClamAvScanResult $result): bool
    {
        $this->service->recordResult($file, $result);

        if ($result->isClean()) {
            return $this->service->release($file);
        }

        $this->service->flagInfected($file, $result);
        $this->service->notifyAdminInfected($file);

        return false;
    }

    /**
     * Deterministic edge verdicts plus a seeded PRNG of randomized verdicts.
     * Each case yields `[string $label, bool $isClean, int $fileId, int $shootId]`.
     *
     * @return iterable<string, array{0: string, 1: bool, 2: int, 3: int}>
     */
    public static function verdictProvider(): iterable
    {
        $edges = [
            'edge: clean verdict' => [true, 1, 1],
            'edge: infected verdict' => [false, 2, 1],
            'edge: clean alt id' => [true, 9999, 7],
            'edge: infected alt id' => [false, 9999, 7],
        ];

        foreach ($edges as $label => [$isClean, $id, $shootId]) {
            yield $label => [$label, $isClean, $id, $shootId];
        }

        // Seeded so the generated verdicts are reproducible run to run.
        mt_srand(20260626);

        $randomCases = 30;
        for ($i = 0; $i < $randomCases; $i++) {
            $isClean = mt_rand(0, 1) === 1;
            $fileId = mt_rand(1, 100000);
            $shootId = mt_rand(1, 10000);

            yield "random: case {$i} (" . ($isClean ? 'clean' : 'infected') . " id={$fileId})"
                => ["random: case {$i}", $isClean, $fileId, $shootId];
        }
    }

    /**
     * Property 26 (admin-visible status totality, Req 15.5):
     *
     *   ∀ ShootFile f, ∀ verdict v.
     *      after handling v, f.scan_status ∈ {quarantined, clean, infected, failed}
     *
     * The surfaced status is never null/unknown/out-of-domain after a verdict.
     */
    #[Test]
    #[DataProvider('verdictProvider')]
    public function admin_visible_scan_status_is_always_one_of_the_four_states(
        string $label,
        bool $isClean,
        int $fileId,
        int $shootId,
    ): void {
        Queue::fake();

        $file = $this->file(ShootFile::SCAN_STATUS_QUARANTINED, $fileId, $shootId);
        $result = $isClean
            ? ClamAvScanResult::clean()
            : ClamAvScanResult::infected('Eicar-Test-Signature');

        $this->handleVerdict($file, $result);

        $this->assertContains(
            $file->scan_status,
            self::ADMIN_VISIBLE_STATES,
            "[{$label}] admin-visible scan_status '{$file->scan_status}' must be one of "
            . implode('/', self::ADMIN_VISIBLE_STATES)
        );
    }

    /**
     * Property 26 (infected ⇒ blocked + withheld; clean ⇒ servable + releasable, Req 15.7):
     *
     *   ∀ ShootFile f.
     *      infected(f) ⇒ isBlockedFromDelivery(f) ∧ ¬released(f)
     *      clean(f)    ⇒ ¬isBlockedFromDelivery(f) ∧ released(f)
     */
    #[Test]
    #[DataProvider('verdictProvider')]
    public function infected_file_is_blocked_and_withheld_while_clean_is_servable_and_released(
        string $label,
        bool $isClean,
        int $fileId,
        int $shootId,
    ): void {
        Queue::fake();

        $file = $this->file(ShootFile::SCAN_STATUS_QUARANTINED, $fileId, $shootId);
        $result = $isClean
            ? ClamAvScanResult::clean()
            : ClamAvScanResult::infected('Eicar-Test-Signature');

        $released = $this->handleVerdict($file, $result);

        if ($isClean) {
            $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status, "[{$label}] clean verdict must record clean");
            $this->assertFalse($file->isBlockedFromDelivery(), "[{$label}] a clean file must not be blocked from preview/download");
            $this->assertTrue($released, "[{$label}] a clean file must be released downstream");
            Queue::assertPushed(ProcessImageJob::class, 1, "[{$label}] a clean file must dispatch exactly one ProcessImageJob");
        } else {
            $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $file->scan_status, "[{$label}] infected verdict must record infected");
            $this->assertTrue($file->isBlockedFromDelivery(), "[{$label}] an infected file must be blocked from preview/download (Req 15.7)");
            $this->assertFalse($released, "[{$label}] an infected file must never be released downstream");
            Queue::assertNotPushed(ProcessImageJob::class, "[{$label}] an infected file must never dispatch ProcessImageJob");
        }
    }

    /**
     * Property 26 (notify-once on infection, Req 15.6):
     *
     *   ∀ ShootFile f.
     *      infected(f) ⇒ exactly one admin notification is emitted
     *      clean(f)    ⇒ zero admin notifications are emitted
     *
     * The admin notification is {@see FileScanService::notifyAdminInfected()},
     * whose deterministic observable side effect is a single admin-alert warning.
     */
    #[Test]
    #[DataProvider('verdictProvider')]
    public function infection_triggers_exactly_one_admin_notification(
        string $label,
        bool $isClean,
        int $fileId,
        int $shootId,
    ): void {
        Queue::fake();
        Log::spy();

        $file = $this->file(ShootFile::SCAN_STATUS_QUARANTINED, $fileId, $shootId);
        $result = $isClean
            ? ClamAvScanResult::clean()
            : ClamAvScanResult::infected('Eicar-Test-Signature');

        $this->handleVerdict($file, $result);

        if ($isClean) {
            Log::shouldNotHaveReceived('warning', [self::ADMIN_ALERT_MESSAGE]);
            $this->assertTrue(true, "[{$label}] a clean verdict emits no admin notification");
        } else {
            Log::shouldHaveReceived('warning')
                ->withArgs(fn ($message) => $message === self::ADMIN_ALERT_MESSAGE)
                ->once();
            $this->assertTrue(true, "[{$label}] an infected verdict emits exactly one admin notification");
        }
    }

    /**
     * Property 26 (aggregate notify-once invariant, Req 15.5/15.6/15.7):
     *
     * Across a seeded batch of mixed verdicts processed in one run, the total
     * number of admin notifications equals the number of infected verdicts
     * (never more — no duplicate alerts; never fewer — no missed infection), and
     * every resulting status is admin-visible. This pins the "exactly once per
     * infection" cardinality globally rather than per single file.
     */
    #[Test]
    public function total_admin_notifications_equal_the_number_of_infected_files(): void
    {
        Queue::fake();
        Log::spy();

        mt_srand(20260627);

        $infectedCount = 0;
        $batch = 40;

        for ($i = 0; $i < $batch; $i++) {
            $isClean = mt_rand(0, 1) === 1;
            $file = $this->file(ShootFile::SCAN_STATUS_QUARANTINED, mt_rand(1, 100000), mt_rand(1, 10000));
            $result = $isClean
                ? ClamAvScanResult::clean()
                : ClamAvScanResult::infected('Flag.Signature.' . $i);

            $this->handleVerdict($file, $result);

            // Every file remains admin-visible with a canonical status (Req 15.5).
            $this->assertContains($file->scan_status, self::ADMIN_VISIBLE_STATES);

            if (! $isClean) {
                $infectedCount++;
                // Infected files are blocked from preview/download (Req 15.7).
                $this->assertTrue($file->isBlockedFromDelivery());
            }
        }

        // Exactly one admin notification per infection — no duplicates, none missed (Req 15.6).
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => $message === self::ADMIN_ALERT_MESSAGE)
            ->times($infectedCount);
    }
}
