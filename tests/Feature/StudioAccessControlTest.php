<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiReelJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Property-based tests for Studio access control across the gated endpoints.
 *
 * Feature: ai-editing-default-page
 * Property 9: Access control rejects unauthenticated and unauthorized roles.
 *   For any request to a Studio_Metrics_Controller endpoint, a
 *   Reel_Generator_Feature endpoint, or the batch-submission endpoint made by
 *   a user who is unauthenticated, or whose role is not one of
 *   admin/superadmin/editing_manager/editor, the request is rejected with an
 *   authentication (401) or authorization (403) error and no job is created
 *   (no AiReelJob and no AiEditingJob rows).
 *
 * Validates: Requirements 9.5, 10.6, 11.6, 12.1
 *
 * The backend has no dedicated property-testing library, so these tests use a
 * deterministic, seeded loop-based generator running a minimum of 100 iterations
 * over the cross product of the gated endpoints and a variety of rejected
 * actors (unauthenticated, plus several unauthorized roles).
 *
 */
#[\PHPUnit\Framework\Attributes\Group('ai-editing-default-page')]
class StudioAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum number of randomized iterations required for the property. */
    private const ITERATIONS = 100;

    /** Fixed seed so any counterexample is reproducible. */
    private const SEED = 20251212;

    /**
     * The set of gated endpoints under test: metrics GETs, reel generate POST,
     * and the batch-submission POST. Each entry is [method, url].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function gatedEndpoints(): array
    {
        return [
            ['GET', '/api/studio/metrics/hero'],
            ['GET', '/api/studio/metrics/recent-projects'],
            ['GET', '/api/studio/metrics/active-queue'],
            ['POST', '/api/reels/generate'],
            ['POST', '/api/autoenhance/edit'],
        ];
    }

    /**
     * Roles that are NOT permitted to access the Studio capabilities. The
     * allowed set is admin/superadmin/editing_manager/editor; everything else
     * must be rejected with 403.
     *
     * @return array<int, string>
     */
    private function unauthorizedRoles(): array
    {
        return [
            'client',
            'photographer',
            'editor_assistant',
            'sales',
            'accountant',
            'guest',
            'viewer',
        ];
    }

    /**
     * Property 9: every request to a gated endpoint by an unauthenticated user
     * or an unauthorized role is rejected with 401/403 and creates no jobs.
     */
    public function test_property_9_unauthorized_access_is_rejected_and_creates_no_jobs(): void
    {
        Queue::fake();

        // Pre-existing data so the POST endpoints would have everything they
        // need to succeed IF access control were (incorrectly) bypassed. This
        // ensures a rejection is attributable to auth, and that "no job
        // created" is a meaningful assertion.
        $shoot = Shoot::factory()->create();
        $owner = User::factory()->admin()->create();
        $fileIds = $this->createShootFiles($shoot, 6, $owner)->pluck('id')->all();

        $endpoints = $this->gatedEndpoints();
        $unauthorizedRoles = $this->unauthorizedRoles();

        mt_srand(self::SEED);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Reset any previously-resolved guard so that an "unauthenticated"
            // iteration is genuinely unauthenticated (Sanctum::actingAs is
            // otherwise sticky across requests within a single test method).
            $this->app['auth']->forgetGuards();

            [$method, $url] = $endpoints[mt_rand(0, count($endpoints) - 1)];

            // Roughly a third of iterations are unauthenticated; the rest use a
            // randomly chosen unauthorized role.
            $unauthenticated = (mt_rand(0, 2) === 0);

            if ($unauthenticated) {
                $actorDescription = 'unauthenticated';
                $expectedStatuses = [401];
            } else {
                $role = $unauthorizedRoles[mt_rand(0, count($unauthorizedRoles) - 1)];
                $actor = User::factory()->create(['role' => $role]);
                Sanctum::actingAs($actor);
                $actorDescription = "unauthorized role '{$role}'";
                $expectedStatuses = [403];
            }

            // A payload that would be valid for the POST endpoints, so the only
            // possible reason for rejection is access control, not validation.
            $payload = $method === 'POST'
                ? [
                    'shoot_id' => $shoot->id,
                    'selected_file_ids' => $fileIds,
                    'file_ids' => $fileIds,
                    'editing_type' => 'enhance',
                    'target_seconds' => 30,
                ]
                : [];

            $counterexample = sprintf(
                'Property 9 violated: %s %s by %s (seed=%d, iteration=%d) should be rejected with %s and create no jobs.',
                $method,
                $url,
                $actorDescription,
                self::SEED,
                $i,
                implode('/', $expectedStatuses)
            );

            $response = $method === 'GET'
                ? $this->getJson($url)
                : $this->postJson($url, $payload);

            $this->assertContains($response->status(), $expectedStatuses, $counterexample);

            // No job of any kind may be created by a rejected request.
            $this->assertSame(0, AiReelJob::count(), $counterexample);
            $this->assertSame(0, AiEditingJob::count(), $counterexample);
        }

        // Across the whole run, nothing should have been created or dispatched.
        Queue::assertNothingPushed();
        $this->assertSame(0, AiReelJob::count());
        $this->assertSame(0, AiEditingJob::count());
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
