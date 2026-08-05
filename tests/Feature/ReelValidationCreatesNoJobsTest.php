<?php

namespace Tests\Feature;

use App\Models\AiReelJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Property-based tests for reel generation validation.
 *
 * Feature: ai-editing-default-page
 * Property 10: Validation failures create no jobs.
 *   For any reel generation request that fails validation (invalid shoot,
 *   empty selection, non-existent files, files from a different shoot, or a
 *   selection beyond the maximum of 20), the request is rejected with a
 *   validation error (422) and the number of stored AiReelJob rows is
 *   unchanged (remains 0).
 *
 * Validates: Requirements 10.4, 11.4
 *
 * The backend has no dedicated property-testing library, so these tests use a
 * deterministic, seeded loop-based generator running a minimum of 100 iterations
 * over a variety of invalid request shapes.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('ai-editing-default-page')]
class ReelValidationCreatesNoJobsTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum number of randomized iterations required for the property. */
    private const ITERATIONS = 100;

    /** The enforced maximum reel selection size (selected_file_ids max:20). */
    private const MAX_SELECTION = 20;

    /** Fixed seed so any counterexample is reproducible. */
    private const SEED = 20251212;

    /**
     * Property 10: any invalid reel generation request is rejected with a 422
     * validation error and creates no AiReelJob rows.
     */
    public function test_property_10_invalid_reel_requests_create_no_jobs(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create();
        $otherShoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        // A pool of valid, existing files belonging to the target shoot, large
        // enough to build oversized selections beyond the max:20 bound.
        $poolSize = 40;
        $validFileIds = $this->createShootFiles($shoot, $poolSize, $admin)->pluck('id')->all();

        // A file belonging to a *different* shoot, used to build cross-shoot
        // selections that must be rejected.
        $otherShootFileId = $this->createShootFiles($otherShoot, 1, $admin)->first()->id;

        // An id guaranteed not to exist in shoot_files.
        $nonExistentFileId = 9_999_999;
        $nonExistentShootId = 9_999_999;

        mt_srand(self::SEED);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Cycle through the distinct invalid-request shapes so each is
            // exercised, with randomized parameters within each shape.
            $shape = $i % 6;

            [$payload, $description] = match ($shape) {
                // Empty selection (violates min:1).
                0 => [
                    [
                        'shoot_id' => $shoot->id,
                        'selected_file_ids' => [],
                    ],
                    'empty selected_file_ids',
                ],
                // Missing shoot_id (violates required).
                1 => [
                    [
                        'selected_file_ids' => array_slice($validFileIds, 0, mt_rand(1, self::MAX_SELECTION)),
                    ],
                    'missing shoot_id',
                ],
                // Non-existent shoot_id (violates exists:shoots,id).
                2 => [
                    [
                        'shoot_id' => $nonExistentShootId,
                        'selected_file_ids' => array_slice($validFileIds, 0, mt_rand(1, self::MAX_SELECTION)),
                    ],
                    'non-existent shoot_id',
                ],
                // Selection containing a non-existent file id (violates exists).
                3 => [
                    [
                        'shoot_id' => $shoot->id,
                        'selected_file_ids' => array_merge(
                            array_slice($validFileIds, 0, mt_rand(0, self::MAX_SELECTION - 1)),
                            [$nonExistentFileId]
                        ),
                    ],
                    'non-existent file id in selection',
                ],
                // Selection containing a file from a different shoot (controller 422).
                4 => [
                    [
                        'shoot_id' => $shoot->id,
                        'selected_file_ids' => array_merge(
                            array_slice($validFileIds, 0, mt_rand(1, self::MAX_SELECTION - 1)),
                            [$otherShootFileId]
                        ),
                    ],
                    'file from a different shoot',
                ],
                // Oversized selection beyond max:20.
                default => [
                    [
                        'shoot_id' => $shoot->id,
                        'selected_file_ids' => array_slice($validFileIds, 0, mt_rand(self::MAX_SELECTION + 1, $poolSize)),
                    ],
                    'oversized selection beyond max:20',
                ],
            };

            $counterexample = sprintf(
                'Property 10 violated: invalid reel request (%s; seed=%d, iteration=%d) should be rejected with 422 and create no jobs.',
                $description,
                self::SEED,
                $i
            );

            $response = $this->postJson('/api/reels/generate', $payload);

            $response->assertStatus(422);
            $this->assertSame(0, AiReelJob::count(), $counterexample);
        }

        // No reel generation jobs should have been dispatched for any rejected request.
        Queue::assertNothingPushed();
        $this->assertSame(0, AiReelJob::count());
    }

    /**
     * Build $count valid ShootFile rows belonging to $shoot. Mirrors the helper
     * used by ReelControllerTest / BatchSizeBoundTest.
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
