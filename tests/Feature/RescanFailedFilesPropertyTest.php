<?php

namespace Tests\Feature;

use App\Jobs\ScanShootFileJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 27: Failed scans are re-scannable
 *
 * **Validates: Requirements 15.8**
 *
 * Universal invariant under test, across randomized starting scan states of a
 * {@see ShootFile}, when the retry-scan control
 * (`POST /api/shoots/{shoot}/files/{file}/rescan`,
 * {@see \App\Http\Controllers\API\FileScanController::rescan()}) is invoked:
 *
 *   ∀ ShootFile f.
 *     f.scan_status == 'failed'
 *       ⇒ rescan(f) responds 200,
 *         re-enqueues EXACTLY ONE {@see ScanShootFileJob} for f, and
 *         returns f to 'quarantined' (scanning) with scan_result/scanned_at
 *         cleared — WITHOUT releasing it (Req 15.8).
 *
 *     f.scan_status ∈ {'quarantined','clean','infected'}
 *       ⇒ rescan(f) responds 409 Conflict,
 *         leaves f.scan_status UNCHANGED, and
 *         enqueues NO {@see ScanShootFileJob}.
 *
 * Only the recoverable terminal `failed` state is re-scannable from the UI;
 * release-on-clean / withhold-on-infected verdicts (and an in-progress
 * `quarantined` scan) must never be silently overwritten by a retry.
 *
 * No property-based testing library is configured for the backend, so this
 * test follows the same deterministic-generator approach used by
 * {@see \Tests\Unit\Scanning\Properties\WithholdingPropertyTest} and
 * {@see \Tests\Feature\UploadQuarantineEnqueuedPropertyTest}: a fixed table of
 * edge cases (one fixture per scan_status) plus a seeded PRNG that produces
 * 25 randomized starting states drawn uniformly from the four-state scan
 * domain, so every branch of the invariant is exercised many times. The job
 * queue is captured with {@see Queue::fake()} so re-enqueue is asserted
 * without running ClamAV.
 */
class RescanFailedFilesPropertyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'rescan-prop-admin-' . uniqid() . '@test.com',
        ]);
    }

    private function createShoot(): Shoot
    {
        return Shoot::factory()->create();
    }

    private function createShootFile(Shoot $shoot, string $scanStatus): ShootFile
    {
        $isFailed = $scanStatus === ShootFile::SCAN_STATUS_FAILED;
        $isDetermined = in_array(
            $scanStatus,
            [ShootFile::SCAN_STATUS_CLEAN, ShootFile::SCAN_STATUS_INFECTED, ShootFile::SCAN_STATUS_FAILED],
            true,
        );

        return ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => 'rescan-prop-' . uniqid() . '.jpg',
            'stored_filename' => 'rescan-prop.jpg',
            'path' => 'shoots/' . $shoot->id . '/raw/rescan-prop.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => 1024,
            'media_type' => 'raw',
            'uploaded_by' => $this->admin->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
            'sort_order' => 0,
            'scan_status' => $scanStatus,
            'scan_result' => $isFailed
                ? 'scan_unavailable'
                : ($scanStatus === ShootFile::SCAN_STATUS_INFECTED ? 'Eicar-Test-Signature' : null),
            'scanned_at' => $isDetermined ? now() : null,
        ]);
    }

    /**
     * Deterministic generator covering every scan_status value (the four-state
     * domain of the invariant) plus a seeded PRNG producing 25 randomized
     * starting states. The randomized branch draws scan_status uniformly from
     * the four valid values so the re-scannable (`failed`) branch and each
     * rejected branch get repeated coverage.
     *
     * Each case yields `[string $label, string $scanStatus]`.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function startingScanStateProvider(): iterable
    {
        $edges = [
            'edge: failed (re-scannable)' => ShootFile::SCAN_STATUS_FAILED,
            'edge: quarantined (in-progress, rejected)' => ShootFile::SCAN_STATUS_QUARANTINED,
            'edge: clean (terminal, rejected)' => ShootFile::SCAN_STATUS_CLEAN,
            'edge: infected (terminal, rejected)' => ShootFile::SCAN_STATUS_INFECTED,
        ];

        foreach ($edges as $label => $status) {
            yield $label => [$label, $status];
        }

        // Seeded PRNG so the generated starting states are reproducible across
        // runs (same approach as the withholding/enqueue property tests).
        mt_srand(20260627);

        $statuses = [
            ShootFile::SCAN_STATUS_QUARANTINED,
            ShootFile::SCAN_STATUS_CLEAN,
            ShootFile::SCAN_STATUS_INFECTED,
            ShootFile::SCAN_STATUS_FAILED,
        ];

        $randomCases = 25;
        for ($i = 0; $i < $randomCases; $i++) {
            $status = $statuses[mt_rand(0, count($statuses) - 1)];

            yield "random: case {$i} ({$status})" => ["random: case {$i}", $status];
        }
    }

    /**
     * Property 27: Failed scans are re-scannable.
     *
     * For every randomized starting state, asserts the full branch behaviour of
     * the retry-scan control in one place so the invariant's truth value is
     * checked at every scan-status corner.
     */
    #[Test]
    #[DataProvider('startingScanStateProvider')]
    public function rescan_re_enqueues_and_quarantines_failed_files_and_rejects_all_others(
        string $label,
        string $scanStatus,
    ): void {
        Queue::fake();
        Sanctum::actingAs($this->admin);

        $shoot = $this->createShoot();
        $file = $this->createShootFile($shoot, $scanStatus);

        $response = $this->postJson(
            '/api/shoots/' . $shoot->id . '/files/' . $file->id . '/rescan'
        );

        if ($scanStatus === ShootFile::SCAN_STATUS_FAILED) {
            // Re-scannable: 200, reset to quarantined (scanning), verdict cleared,
            // and exactly one scan job re-enqueued for this file — never released.
            $response->assertOk();
            $response->assertJson(['scan_status' => ShootFile::SCAN_STATUS_QUARANTINED]);

            $file->refresh();
            $this->assertSame(
                ShootFile::SCAN_STATUS_QUARANTINED,
                $file->scan_status,
                "[{$label}] a failed file must return to quarantined after rescan"
            );
            $this->assertNull(
                $file->scan_result,
                "[{$label}] the stale failed verdict must be cleared on rescan"
            );
            $this->assertNull(
                $file->scanned_at,
                "[{$label}] scanned_at must be cleared on rescan"
            );

            Queue::assertPushed(
                ScanShootFileJob::class,
                fn (ScanShootFileJob $job) => $job->shootFileId === $file->id,
            );
            Queue::assertPushed(
                ScanShootFileJob::class,
                1,
                "[{$label}] exactly one ScanShootFileJob must be re-enqueued for a failed file"
            );

            return;
        }

        // Every non-failed starting state is rejected with 409, the status is
        // left untouched, and no scan job is enqueued.
        $response->assertStatus(409);
        $response->assertJson(['scan_status' => $scanStatus]);

        $file->refresh();
        $this->assertSame(
            $scanStatus,
            $file->scan_status,
            "[{$label}] a non-failed file's scan_status must be unchanged by a rejected rescan"
        );

        Queue::assertNotPushed(
            ScanShootFileJob::class,
            "[{$label}] no ScanShootFileJob may be enqueued for scan_status='{$scanStatus}'"
        );
    }
}
