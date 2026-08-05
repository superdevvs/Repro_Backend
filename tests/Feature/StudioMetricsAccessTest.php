<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Example/unit tests for the Studio Metrics endpoint role middleware.
 *
 * Feature: ai-editing-default-page
 *
 * Covers the access-control behavior of the three aggregated read endpoints,
 * which are gated by the `role:admin,superadmin,editing_manager,editor`
 * middleware (see routes/api.php):
 *
 *   GET /api/studio/metrics/hero
 *   GET /api/studio/metrics/recent-projects
 *   GET /api/studio/metrics/active-queue
 *
 * Assertions:
 *  - 401 for unauthenticated requests on all three endpoints.
 *  - 403 for an authenticated but unauthorized role (e.g. `client`,
 *    `photographer`) on all three endpoints.
 *  - 200 with the expected JSON shape for each authorized role
 *    (`admin`, `superadmin`, `editing_manager`, `editor`).
 *  - Editor self-scoping: an editor's hero, recent-projects, and active-queue
 *    aggregations include only the editor's own jobs and never another user's.
 *
 * Validates: Requirements 9.5, 12.3
 *
 */
#[\PHPUnit\Framework\Attributes\Group('ai-editing-default-page')]
class StudioMetricsAccessTest extends TestCase
{
    use RefreshDatabase;

    private const HERO_URL = '/api/studio/metrics/hero';
    private const RECENT_URL = '/api/studio/metrics/recent-projects';
    private const ACTIVE_QUEUE_URL = '/api/studio/metrics/active-queue';

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'hero'            => [self::HERO_URL],
            'recent-projects' => [self::RECENT_URL],
            'active-queue'    => [self::ACTIVE_QUEUE_URL],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function authorizedRoleProvider(): array
    {
        return [
            'admin'           => ['admin'],
            'superadmin'      => ['superadmin'],
            'editing_manager' => ['editing_manager'],
            'editor'          => ['editor'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unauthorizedRoleProvider(): array
    {
        return [
            'client'       => ['client'],
            'photographer' => ['photographer'],
        ];
    }

    /**
     * Unauthenticated requests are rejected with 401 on every endpoint.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('endpointProvider')]
    public function test_unauthenticated_request_is_rejected_with_401(string $url): void
    {
        $this->getJson($url)->assertStatus(401);
    }

    /**
     * An authenticated user whose role is not in the allowed set is rejected
     * with 403 on every endpoint.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('endpointProvider')]
    public function test_unauthorized_client_role_is_rejected_with_403(string $url): void
    {
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);

        $this->getJson($url)->assertStatus(403);
    }

    /**
     * Several non-allowed roles are rejected with 403 across all endpoints.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unauthorizedRoleProvider')]
    public function test_each_unauthorized_role_is_rejected_with_403(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        $this->getJson(self::HERO_URL)->assertStatus(403);
        $this->getJson(self::RECENT_URL)->assertStatus(403);
        $this->getJson(self::ACTIVE_QUEUE_URL)->assertStatus(403);
    }

    /**
     * Each authorized role gets 200 with the expected hero JSON shape.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('authorizedRoleProvider')]
    public function test_authorized_role_hero_returns_expected_shape(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        $this->getJson(self::HERO_URL)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'projects_count',
                    'ai_jobs_completed',
                    'success_rate',
                ],
            ]);
    }

    /**
     * Each authorized role gets 200 with the expected recent-projects shape.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('authorizedRoleProvider')]
    public function test_authorized_role_recent_projects_returns_expected_shape(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $shoot = Shoot::factory()->create();
        $this->createPhotoJob($shoot, $user, AiEditingJob::STATUS_COMPLETED);
        Sanctum::actingAs($user);

        $this->getJson(self::RECENT_URL)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'shoot_id',
                        'address',
                        'last_activity_at',
                        'latest_status',
                        'latest_job_type',
                    ],
                ],
            ]);
    }

    /**
     * Each authorized role gets 200 with the expected active-queue shape.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('authorizedRoleProvider')]
    public function test_authorized_role_active_queue_returns_expected_shape(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $shoot = Shoot::factory()->create();
        $this->createPhotoJob($shoot, $user, AiEditingJob::STATUS_PROCESSING);
        Sanctum::actingAs($user);

        $this->getJson(self::ACTIVE_QUEUE_URL)
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'job_type',
                        'shoot_id',
                        'shoot_address',
                        'status',
                    ],
                ],
            ]);
    }

    /**
     * Editor self-scoping on the hero endpoint: an editor's hero counts only
     * the editor's own jobs, never another user's. A privileged role (admin)
     * sees the full set.
     */
    public function test_editor_hero_is_scoped_to_own_jobs(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create();

        // Editor: 2 completed photo + 1 failed video = 3 terminal, 2 completed.
        $this->createPhotoJob($shoot, $editor, AiEditingJob::STATUS_COMPLETED);
        $this->createPhotoJob($shoot, $editor, AiEditingJob::STATUS_COMPLETED);
        $this->createVideoJob($shoot, $editor, AiListingVideoJob::STATUS_FAILED);

        // Other user's jobs must NOT be visible to the editor.
        $this->createPhotoJob($shoot, $other, AiEditingJob::STATUS_COMPLETED);
        $this->createVideoJob($shoot, $other, AiListingVideoJob::STATUS_COMPLETED);

        Sanctum::actingAs($editor);

        $this->getJson(self::HERO_URL)
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'projects_count'    => 1, // single shoot, counted once
                    'ai_jobs_completed' => 2, // only the editor's two completed jobs
                    'success_rate'      => 66.7, // 2 completed / 3 terminal * 100
                ],
            ]);

        // Admin sees everything: editor's 2 completed + other's 2 completed = 4
        // completed, plus the editor's 1 failed video = 5 terminal jobs.
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson(self::HERO_URL)
            ->assertOk()
            ->assertJson([
                'data' => [
                    'projects_count'    => 1,
                    'ai_jobs_completed' => 4,
                    'success_rate'      => 80.0, // 4 completed / 5 terminal * 100
                ],
            ]);
    }

    /**
     * Editor self-scoping on the recent-projects endpoint: an editor only sees
     * shoots from their own jobs.
     */
    public function test_editor_recent_projects_is_scoped_to_own_jobs(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);

        $editorShoot = Shoot::factory()->create();
        $otherShoot = Shoot::factory()->create();

        $this->createPhotoJob($editorShoot, $editor, AiEditingJob::STATUS_COMPLETED);
        $this->createPhotoJob($otherShoot, $other, AiEditingJob::STATUS_COMPLETED);

        Sanctum::actingAs($editor);

        $response = $this->getJson(self::RECENT_URL)->assertOk();

        $shootIds = array_map(fn ($p) => (int) $p['shoot_id'], $response->json('data'));

        $this->assertSame([$editorShoot->id], $shootIds, 'Editor saw a project that is not their own.');
        $this->assertNotContains($otherShoot->id, $shootIds, "Editor saw another user's shoot.");
    }

    /**
     * Editor self-scoping on the active-queue endpoint: an editor only sees
     * their own active jobs.
     */
    public function test_editor_active_queue_is_scoped_to_own_jobs(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create();

        $editorJob = $this->createPhotoJob($shoot, $editor, AiEditingJob::STATUS_PROCESSING);
        $this->createPhotoJob($shoot, $other, AiEditingJob::STATUS_PROCESSING);

        Sanctum::actingAs($editor);

        $response = $this->getJson(self::ACTIVE_QUEUE_URL)->assertOk();

        $ids = array_map(fn ($job) => $job['id'], $response->json('data'));

        $this->assertSame(['photo-' . $editorJob->id], $ids, 'Active queue is not scoped to the editor.');
    }

    private function createPhotoJob(Shoot $shoot, User $user, string $status): AiEditingJob
    {
        return AiEditingJob::create([
            'shoot_id'           => $shoot->id,
            'user_id'            => $user->id,
            'status'             => $status,
            'editing_type'       => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => 'https://example.test/photo.jpg',
        ]);
    }

    private function createVideoJob(Shoot $shoot, User $user, string $status): AiListingVideoJob
    {
        return AiListingVideoJob::create([
            'shoot_id'          => $shoot->id,
            'user_id'           => $user->id,
            'provider'          => 'fal',
            'selected_file_ids' => [1, 2, 3, 4, 5, 6],
            'target_seconds'    => 30,
            'status'            => $status,
        ]);
    }
}
