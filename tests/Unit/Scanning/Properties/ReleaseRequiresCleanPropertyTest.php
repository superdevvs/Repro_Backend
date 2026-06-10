<?php

namespace Tests\Unit\Scanning\Properties;

use App\Jobs\ProcessImageJob;
use App\Models\ShootFile;
use App\Services\Scanning\ClamAvScanResult;
use App\Services\Scanning\FileScanService;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 14: Release requires a recorded clean result
 *
 * Validates: Requirements 14.4, 15.2, 15.3, 15.4
 *
 * Universal invariant under test (the release-requires-clean invariant):
 *
 *   release(file) dispatches a ProcessImageJob ⇔ file.scan_status === clean
 *
 * The property is verified across:
 *   1. Every value of {@see ShootFile} `scan_status` (quarantined, clean,
 *      infected, failed) — calling {@see FileScanService::release()} exactly
 *      once dispatches a {@see ProcessImageJob} iff status is clean.
 *   2. Repeated release() calls on a non-clean file dispatch zero jobs (no
 *      number of retries can leak a non-clean file out of Quarantine).
 *   3. After {@see FileScanService::flagInfected()} on a previously-clean
 *      file, release() refuses to dispatch (a late infected verdict revokes
 *      release eligibility).
 *   4. release() after recordResult('clean') succeeds; release() after
 *      recordResult('infected') refuses.
 *   5. Randomized interleavings of recordResult / flagInfected / release
 *      preserve the invariant: at every step, the cumulative dispatch count
 *      equals the number of release() calls invoked while scan_status === clean
 *      at the moment of the call. The scan outcome (scan_result, scanned_at)
 *      is recorded on the file whenever a verdict is observed (Req 15.3).
 *
 * No PBT library is configured for the backend, so the test follows the same
 * "deterministic generator" approach used by other property tests in this
 * spec ({@see Tests\Unit\Shoots\ShootEditingPayloadFilteringPropertyTest},
 * {@see Tests\Feature\PaymentReminderStopOnPaidNoDuplicatePropertyTest}): a
 * seeded PRNG produces randomized starting states + operation sequences plus a
 * fixed table of deterministic edge cases. Each generated case asserts the
 * release-iff-clean invariant.
 */
class ReleaseRequiresCleanPropertyTest extends TestCase
{
    private FileScanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FileScanService();
    }

    /**
     * Build an in-memory ShootFile that records save() without touching the DB,
     * keeping the state-transition logic under test pure. Mirrors the pattern
     * used by {@see Tests\Unit\Scanning\FileScanServiceTest}.
     */
    private function file(string $scanStatus = ShootFile::SCAN_STATUS_QUARANTINED, int $id = 101): ShootFile
    {
        $file = new class extends ShootFile {
            public int $persistCount = 0;

            public function save(array $options = []): bool
            {
                $this->persistCount++;

                return true;
            }
        };

        $file->id = $id;
        $file->shoot_id = 7;
        $file->filename = 'photo.jpg';
        $file->scan_status = $scanStatus;

        return $file;
    }

    /**
     * Every value of `scan_status` the state machine recognizes
     * (Req 14.1, 14.4, 15.1).
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function everyScanStatusProvider(): iterable
    {
        yield 'quarantined' => [ShootFile::SCAN_STATUS_QUARANTINED, false];
        yield 'clean' => [ShootFile::SCAN_STATUS_CLEAN, true];
        yield 'infected' => [ShootFile::SCAN_STATUS_INFECTED, false];
        yield 'failed' => [ShootFile::SCAN_STATUS_FAILED, false];
    }

    /**
     * Statuses that must NEVER release (Req 15.4): a file is held in Quarantine
     * until — and only until — a clean result is recorded.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function nonCleanStatusProvider(): iterable
    {
        yield 'quarantined' => [ShootFile::SCAN_STATUS_QUARANTINED];
        yield 'infected' => [ShootFile::SCAN_STATUS_INFECTED];
        yield 'failed' => [ShootFile::SCAN_STATUS_FAILED];
    }

    /**
     * Property 14 (per-status biconditional):
     * For every starting scan_status, calling release() exactly once leaves
     * a ProcessImageJob queued iff scan_status === clean (Req 14.4, 15.4).
     */
    #[Test]
    #[DataProvider('everyScanStatusProvider')]
    public function release_dispatches_iff_status_is_clean(string $status, bool $shouldDispatch): void
    {
        Queue::fake();

        $file = $this->file($status);

        $released = $this->service->release($file);

        $this->assertSame(
            $shouldDispatch,
            $released,
            "release() return value must equal (status === clean) for status='{$status}'"
        );

        if ($shouldDispatch) {
            Queue::assertPushed(ProcessImageJob::class, 1);
        } else {
            Queue::assertNotPushed(ProcessImageJob::class);
        }

        // release() never persists state — the verdict is recorded by
        // recordResult() / flagInfected() / flagFailed(), not by release().
        $this->assertSame(
            0,
            $file->persistCount,
            "release() must not persist on the file for status='{$status}'"
        );
    }

    /**
     * Property 14 (idempotency on non-clean files, Req 15.4):
     * No number of repeated release() calls on a non-clean file dispatches a
     * single ProcessImageJob — the gate cannot be worn down by retries.
     */
    #[Test]
    #[DataProvider('nonCleanStatusProvider')]
    public function repeated_release_on_non_clean_file_dispatches_zero_jobs(string $status): void
    {
        Queue::fake();

        $file = $this->file($status);

        // 25 attempts is well past any realistic retry budget; pick a small
        // upper bound that still exercises "many calls".
        for ($i = 0; $i < 25; $i++) {
            $this->assertFalse(
                $this->service->release($file),
                "release() attempt #{$i} on status='{$status}' must return false"
            );
        }

        Queue::assertNothingPushed();
        $this->assertSame(
            $status,
            $file->scan_status,
            "scan_status must remain '{$status}' after repeated refused release() calls"
        );
    }

    /**
     * Property 14 (late-infected revocation, Req 15.1, 15.4):
     * If a file is flagged infected after passing clean, a subsequent release()
     * refuses. The withholding invariant cannot be bypassed by a stale clean
     * result that has since been overridden.
     */
    #[Test]
    public function flag_infected_after_clean_prevents_release(): void
    {
        Queue::fake();

        $file = $this->file(ShootFile::SCAN_STATUS_CLEAN);

        $this->service->flagInfected($file, ClamAvScanResult::infected('Eicar-Test-Signature'));

        // Status flipped to infected and the verdict was recorded.
        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $file->scan_status);
        $this->assertSame('Eicar-Test-Signature', $file->scan_result);
        $this->assertNotNull($file->scanned_at);

        // release() now refuses and dispatches no job.
        $this->assertFalse($this->service->release($file));
        Queue::assertNothingPushed();
    }

    /**
     * Property 14 (verdict-driven release succeeds, Req 14.4, 15.3, 15.4):
     * recordResult('clean') promotes the file to clean and records the result;
     * a subsequent release() succeeds and dispatches exactly one ProcessImageJob.
     */
    #[Test]
    public function release_after_record_result_clean_succeeds(): void
    {
        Queue::fake();

        $file = $this->file(ShootFile::SCAN_STATUS_QUARANTINED);

        $this->service->recordResult($file, ClamAvScanResult::clean());

        $this->assertSame(ShootFile::SCAN_STATUS_CLEAN, $file->scan_status);
        $this->assertNotNull($file->scan_result, 'scan_result must be recorded (Req 15.3)');
        $this->assertNotNull($file->scanned_at, 'scanned_at must be recorded (Req 15.3)');

        $this->assertTrue($this->service->release($file));
        Queue::assertPushed(ProcessImageJob::class, 1);
    }

    /**
     * Property 14 (verdict-driven release refuses, Req 15.1, 15.3, 15.4):
     * recordResult('infected') flags the file infected and records the
     * signature; a subsequent release() refuses and dispatches no job.
     */
    #[Test]
    public function release_after_record_result_infected_refuses(): void
    {
        Queue::fake();

        $file = $this->file(ShootFile::SCAN_STATUS_QUARANTINED);

        $this->service->recordResult($file, ClamAvScanResult::infected('Win.Test.EICAR'));

        $this->assertSame(ShootFile::SCAN_STATUS_INFECTED, $file->scan_status);
        $this->assertSame('Win.Test.EICAR', $file->scan_result);
        $this->assertNotNull($file->scanned_at);

        $this->assertFalse($this->service->release($file));
        Queue::assertNothingPushed();
    }

    /**
     * Seeded PRNG generator producing randomized operation sequences plus a
     * fixed table of deterministic edge cases. Each case is:
     *   [string $label, string $startStatus, list<string> $ops]
     *
     * Operations are drawn from the alphabet:
     *   - "rec_clean"     → recordResult(clean)
     *   - "rec_infected"  → recordResult(infected)
     *   - "flag_infected" → flagInfected()
     *   - "release"       → release()
     *
     * Run via {@see test_release_invariant_holds_under_random_interleavings}.
     *
     * @return iterable<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function operationSequenceProvider(): iterable
    {
        // Deterministic edge cases — each one exercises a boundary the
        // invariant must hold over (Req 14.4, 15.1, 15.2, 15.3, 15.4).
        $edges = [
            'edge: empty sequence on quarantined' => [
                ShootFile::SCAN_STATUS_QUARANTINED,
                [],
            ],
            'edge: release-only on quarantined (gate holds)' => [
                ShootFile::SCAN_STATUS_QUARANTINED,
                ['release', 'release', 'release'],
            ],
            'edge: clean → release → release (single dispatch per release)' => [
                ShootFile::SCAN_STATUS_QUARANTINED,
                ['rec_clean', 'release', 'release'],
            ],
            'edge: clean → flag_infected → release (revoked)' => [
                ShootFile::SCAN_STATUS_QUARANTINED,
                ['rec_clean', 'flag_infected', 'release'],
            ],
            'edge: infected → rec_clean → release (re-promoted by re-scan)' => [
                ShootFile::SCAN_STATUS_QUARANTINED,
                ['rec_infected', 'rec_clean', 'release'],
            ],
            'edge: alternating verdicts then release' => [
                ShootFile::SCAN_STATUS_QUARANTINED,
                ['rec_clean', 'rec_infected', 'rec_clean', 'rec_infected', 'release'],
            ],
            'edge: starting clean, never re-verdicted' => [
                ShootFile::SCAN_STATUS_CLEAN,
                ['release', 'release', 'release'],
            ],
            'edge: starting infected, attempts only' => [
                ShootFile::SCAN_STATUS_INFECTED,
                ['release', 'release', 'release'],
            ],
            'edge: starting failed, then clean re-scan' => [
                ShootFile::SCAN_STATUS_FAILED,
                ['rec_clean', 'release'],
            ],
            'edge: many releases interleaved with verdicts' => [
                ShootFile::SCAN_STATUS_QUARANTINED,
                [
                    'release', 'rec_clean', 'release', 'release',
                    'flag_infected', 'release', 'rec_clean', 'release',
                ],
            ],
        ];

        foreach ($edges as $label => [$start, $ops]) {
            yield $label => [$label, $start, $ops];
        }

        // Seeded PRNG so the generator is reproducible across runs. mt_srand
        // applies process-wide state, but each iteration is fully determined
        // by the seed and the case index.
        mt_srand(20260613);

        $alphabet = ['rec_clean', 'rec_infected', 'flag_infected', 'release'];
        $startPool = [
            ShootFile::SCAN_STATUS_QUARANTINED,
            ShootFile::SCAN_STATUS_CLEAN,
            ShootFile::SCAN_STATUS_INFECTED,
            ShootFile::SCAN_STATUS_FAILED,
        ];

        // ≥100 iterations so the property test meets the design's PBT bar
        // (≥100 randomized iterations per property; see design.md §Testing
        // Strategy).
        $randomCases = 120;
        for ($i = 0; $i < $randomCases; $i++) {
            $start = $startPool[mt_rand(0, count($startPool) - 1)];
            $length = mt_rand(0, 12);
            $ops = [];
            for ($j = 0; $j < $length; $j++) {
                $ops[] = $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            yield "random: case {$i} (start={$start}, n={$length})" => [
                "random: case {$i}",
                $start,
                $ops,
            ];
        }
    }

    /**
     * Property 14 — universal release-iff-clean invariant under arbitrary
     * interleavings of recordResult / flagInfected / release.
     *
     * For every operation step:
     *   - The dispatch count equals the number of release() calls that have so
     *     far been invoked while scan_status === clean (Req 14.4, 15.4).
     *   - Whenever a verdict is observed (recordResult / flagInfected),
     *     scan_result and scanned_at are recorded on the file (Req 15.3).
     *   - scan_status is always one of the four declared values (totality).
     *
     * Validates: Requirements 14.4, 15.2, 15.3, 15.4
     *
     * @param  list<string>  $ops
     */
    #[Test]
    #[DataProvider('operationSequenceProvider')]
    public function release_invariant_holds_under_random_interleavings(string $label, string $startStatus, array $ops): void
    {
        Queue::fake();

        $file = $this->file($startStatus);

        $expectedDispatches = 0;
        foreach ($ops as $step => $op) {
            switch ($op) {
                case 'rec_clean':
                    $this->service->recordResult($file, ClamAvScanResult::clean());
                    $this->assertSame(
                        ShootFile::SCAN_STATUS_CLEAN,
                        $file->scan_status,
                        "[{$label}] step {$step}: recordResult(clean) must transition to clean (Req 14.4)"
                    );
                    $this->assertNotNull(
                        $file->scan_result,
                        "[{$label}] step {$step}: recordResult must record scan_result (Req 15.3)"
                    );
                    $this->assertNotNull(
                        $file->scanned_at,
                        "[{$label}] step {$step}: recordResult must record scanned_at (Req 15.3)"
                    );
                    break;

                case 'rec_infected':
                    $this->service->recordResult($file, ClamAvScanResult::infected('Test.Signature.' . $step));
                    $this->assertSame(
                        ShootFile::SCAN_STATUS_INFECTED,
                        $file->scan_status,
                        "[{$label}] step {$step}: recordResult(infected) must transition to infected (Req 15.1)"
                    );
                    $this->assertSame(
                        'Test.Signature.' . $step,
                        $file->scan_result,
                        "[{$label}] step {$step}: infected verdict must record signature (Req 15.3)"
                    );
                    $this->assertNotNull(
                        $file->scanned_at,
                        "[{$label}] step {$step}: recordResult must record scanned_at (Req 15.3)"
                    );
                    break;

                case 'flag_infected':
                    $this->service->flagInfected($file, ClamAvScanResult::infected('Flag.Signature.' . $step));
                    $this->assertSame(
                        ShootFile::SCAN_STATUS_INFECTED,
                        $file->scan_status,
                        "[{$label}] step {$step}: flagInfected must transition to infected (Req 15.1)"
                    );
                    $this->assertNotNull(
                        $file->scan_result,
                        "[{$label}] step {$step}: flagInfected must record scan_result (Req 15.3)"
                    );
                    break;

                case 'release':
                    // Capture the status as observed BEFORE the release() call
                    // — that is the value the biconditional is over.
                    $statusAtCall = $file->scan_status;
                    $released = $this->service->release($file);

                    if ($statusAtCall === ShootFile::SCAN_STATUS_CLEAN) {
                        $this->assertTrue(
                            $released,
                            "[{$label}] step {$step}: release() on clean must return true (Req 14.4)"
                        );
                        $expectedDispatches++;
                    } else {
                        $this->assertFalse(
                            $released,
                            "[{$label}] step {$step}: release() on '{$statusAtCall}' must return false (Req 15.4)"
                        );
                    }

                    // release() never mutates scan_status — only verdict ops
                    // ever transition state.
                    $this->assertSame(
                        $statusAtCall,
                        $file->scan_status,
                        "[{$label}] step {$step}: release() must not change scan_status"
                    );

                    // The cumulative dispatch count must equal the number of
                    // release() calls invoked while clean — at every step.
                    Queue::assertPushed(ProcessImageJob::class, $expectedDispatches);
                    break;

                default:
                    $this->fail("Unknown op '{$op}' in case [{$label}]");
            }

            // Totality: scan_status is always one of the four declared values.
            $this->assertContains(
                $file->scan_status,
                [
                    ShootFile::SCAN_STATUS_QUARANTINED,
                    ShootFile::SCAN_STATUS_CLEAN,
                    ShootFile::SCAN_STATUS_INFECTED,
                    ShootFile::SCAN_STATUS_FAILED,
                ],
                "[{$label}] step {$step}: scan_status must be one of the four declared values"
            );
        }

        // Final check: total dispatches over the whole sequence equals the
        // number of release() calls made while clean.
        Queue::assertPushed(ProcessImageJob::class, $expectedDispatches);
    }
}
