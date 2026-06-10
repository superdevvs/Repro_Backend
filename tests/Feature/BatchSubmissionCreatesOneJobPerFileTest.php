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
 * Property-based test for batch AI editing submission creating one job per file.
 *
 * Feature: ai-editing-default-page
 * Property 11: Batch submission creates one job per selected file.
 *   For any valid batch submission of N distinct files (1 <= N <= 100) with a
 *   chosen enhancement mode, exactly N photo editing jobs are created, each
 *   using the chosen enhancement mode, and the reported submitted-count equals N.
 *
 * Validates: Requirements 11.2, 11.3
 *
 * The backend has no dedicated property-testing library, so this test uses a
 * deterministic, seeded loop-based generator running a minimum of 100 iterations
 * over randomized batch sizes (1..100) and randomized enhancement modes.
 *
 * Mirrors the conventions of BatchSizeBoundTest: Queue::fake() so processing
 * jobs are never dispatched, a pool of valid existing ShootFile rows so the
 * only outcome under test is the per-file job creation, and a reproducible seed.
 *
 * @group ai-editing-default-page
 */
class BatchSubmissionCreatesOneJobPerFileTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum number of randomized iterations required for the property. */
    private const ITERATIONS = 100;

    /** The enforced maximum batch size (Requirement 11.5). */
    private const MAX_BATCH = 100;

    /** Fixed seed so any counterexample is reproducible. */
    private const SEED = 20251212;

    /** The enhancement modes a batch submission may choose. */
    private const ENHANCEMENT_MODES = [
        'enhance',
        'sky_replace',
        'vertical_correction',
        'window_pull',
    ];

    /**
     * Property 11: a valid batch of N distinct files (1..100) with a chosen
     * enhancement mode creates exactly N AiEditingJob rows, each carrying the
     * chosen editing_type, and the response reports exactly N submitted jobs.
     */
    public function test_property_11_valid_batch_creates_one_job_per_file(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        // A pool of valid, existing files at the bound so any 1..100 selection
        // is drawn from genuinely existing, shoot-owned files (the only thing
        // under test is one-job-per-file creation, not validation failures).
        $fileIds = $this->createShootFiles($shoot, self::MAX_BATCH, $admin)->pluck('id')->all();

        mt_srand(self::SEED);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Guarantee the boundary (exactly 100) and the minimum (1) are
            // exercised; otherwise pick a random in-bound size.
            $n = match ($i) {
                0 => self::MAX_BATCH, // exactly 100 distinct files
                1 => 1,               // minimum valid batch
                default => mt_rand(1, self::MAX_BATCH),
            };

            // N distinct file ids (the pool is already distinct).
            $selected = array_slice($fileIds, 0, $n);

            // Random enhancement mode for this submission.
            $mode = self::ENHANCEMENT_MODES[mt_rand(0, count(self::ENHANCEMENT_MODES) - 1)];

            $counterexample = sprintf(
                'Property 11 violated: valid batch N=%d mode=%s (seed=%d, iteration=%d) '
                . 'should create exactly N jobs each with the chosen editing_type and report N submitted.',
                $n,
                $mode,
                self::SEED,
                $i
            );

            $response = $this->postJson('/api/autoenhance/edit', [
                'shoot_id' => $shoot->id,
                'file_ids' => $selected,
                'editing_type' => $mode,
            ]);

            $response->assertCreated();

            // Exactly N photo editing jobs are created for this submission.
            $this->assertSame($n, AiEditingJob::count(), $counterexample);

            // Each created job uses the chosen enhancement mode.
            $this->assertSame(
                0,
                AiEditingJob::where('editing_type', '!=', $mode)->count(),
                $counterexample . ' Found jobs with a different editing_type.'
            );

            // The response reports exactly N submitted jobs (one entry per file).
            $data = $response->json('data');
            $this->assertIsArray($data, $counterexample);
            $this->assertCount($n, $data, $counterexample . ' Response data count mismatch.');
            foreach ($data as $job) {
                $this->assertSame($mode, $job['editing_type'] ?? null, $counterexample);
            }

            // Reset so the per-submission "exactly N jobs" assertion stays exact.
            AiEditingJob::query()->delete();
        }
    }

    /**
     * Build $count valid ShootFile rows belonging to $shoot. Mirrors the helper
     * used by BatchSizeBoundTest / ListingVideoControllerTest. A resolvable path
     * is set so the controller can derive an image URL and create a job per file.
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
