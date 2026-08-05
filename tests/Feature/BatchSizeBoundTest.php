<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Property-based tests for the batch AI editing submission size guardrail.
 *
 * Feature: ai-editing-default-page
 * Property 12: Batch size is bounded at 100.
 *   For any batch submission whose selected file count exceeds 100, the
 *   submission is rejected with a validation message and no jobs are created;
 *   a submission of exactly 100 files (and any 1..100) is accepted.
 *
 * Validates: Requirements 11.5
 *
 * The backend has no dedicated property-testing library, so these tests use a
 * deterministic, seeded loop-based generator running a minimum of 100 iterations
 * over randomized batch sizes within and beyond the bound.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('ai-editing-default-page')]
class BatchSizeBoundTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum number of randomized iterations required for the property. */
    private const ITERATIONS = 100;

    /** The enforced maximum batch size (Requirement 11.5). */
    private const MAX_BATCH = 100;

    /** Fixed seed so any counterexample is reproducible. */
    private const SEED = 20251212;

    /**
     * Property 12 (upper bound): any selection exceeding 100 files is rejected
     * with a 422 validation error and creates no AiEditingJob rows.
     */
    public function test_property_12_oversized_batches_are_rejected_with_no_jobs(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        // A pool of valid, existing files larger than the bound so that the ONLY
        // possible validation failure is the size cap (not a missing-file error).
        $poolSize = 205;
        $fileIds = $this->createShootFiles($shoot, $poolSize, $admin)->pluck('id')->all();

        mt_srand(self::SEED);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Always strictly greater than the bound: 101..205.
            $n = mt_rand(self::MAX_BATCH + 1, $poolSize);
            $selected = array_slice($fileIds, 0, $n);

            $counterexample = sprintf(
                'Property 12 violated: oversized batch N=%d (seed=%d, iteration=%d) should be rejected with no jobs created.',
                $n,
                self::SEED,
                $i
            );

            $response = $this->postJson('/api/autoenhance/edit', [
                'shoot_id' => $shoot->id,
                'file_ids' => $selected,
                'editing_type' => 'enhance',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['file_ids']);
            $this->assertSame(0, AiEditingJob::count(), $counterexample);
        }

        // No processing jobs should have been dispatched for any rejected batch.
        Queue::assertNothingPushed();
    }

    /**
     * Property 12 (within bound): any selection of 1..100 files is accepted
     * (never rejected by the size cap) and creates exactly one job per file.
     * The boundary value of exactly 100 is always exercised.
     */
    public function test_property_12_batches_within_bound_are_accepted(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $fileIds = $this->createShootFiles($shoot, self::MAX_BATCH, $admin)->pluck('id')->all();

        mt_srand(self::SEED);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Guarantee the boundary (exactly 100) and the minimum (1) are tested,
            // otherwise pick a random in-bound size.
            $n = match ($i) {
                0 => self::MAX_BATCH, // exactly 100 files is accepted
                1 => 1,               // minimum valid batch
                default => mt_rand(1, self::MAX_BATCH),
            };
            $selected = array_slice($fileIds, 0, $n);

            $counterexample = sprintf(
                'Property 12 violated: in-bound batch N=%d (seed=%d, iteration=%d) should be accepted and create exactly N jobs.',
                $n,
                self::SEED,
                $i
            );

            $response = $this->postJson('/api/autoenhance/edit', [
                'shoot_id' => $shoot->id,
                'file_ids' => $selected,
                'editing_type' => 'enhance',
            ]);

            // Accepted == not rejected by the size bound (not a 422 validation error).
            $this->assertNotSame(422, $response->status(), $counterexample . ' Got 422.');
            $response->assertCreated();
            $this->assertSame($n, AiEditingJob::count(), $counterexample);

            // Reset so the per-submission "exactly N jobs" assertion stays exact.
            AiEditingJob::query()->delete();
        }
    }

    /**
     * Build $count valid ShootFile rows belonging to $shoot. Mirrors the helper
     * used by ListingVideoControllerTest. A resolvable path is set so the
     * controller can derive an image URL and create a job per file.
     */
    private function createShootFiles(Shoot $shoot, int $count, User $uploadedBy)
    {
        return collect(range(1, $count))->map(fn (int $index) => ShootFile::create([
            'shoot_id' => $shoot->id,
            'filename' => "photo-{$index}.jpg",
            'stored_filename' => "photo-{$index}.jpg",
            'path' => "shoots/{$shoot->id}/photo-{$index}.jpg",
            'storage_path' => "shoots/{$shoot->id}/photo-{$index}.jpg",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 123456,
            'uploaded_by' => $uploadedBy->id,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]));
    }
}
