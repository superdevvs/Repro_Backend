<?php

namespace Tests\Feature;

use App\Models\BrandState;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 28: Mutations carry a version or update timestamp
 *
 * **Validates: Requirements 10.10, 16.8**
 *
 * A seeded generator drives 21 reproducible mutation cases across the real
 * Studio mutation endpoints (template create/update/delete, brand update,
 * project create). Every successful mutation response must carry a
 * server-managed version identifier or update timestamp, and repeated
 * mutations of the same record must advance it: the version strictly
 * increases and the update timestamp never moves backwards.
 *
 * "Server-managed" is checked by submitting attacker-supplied `version`,
 * `updatedAt`, `team_id`, and `created_by` fields and asserting the committed
 * values come from the server instead of the request body.
 *
 * Identity checks are structural: response ids, deep-link record ids, and the
 * persisted primary keys are compared as whole values (never as substrings of
 * a serialized payload), because short numeric ids appear incidentally inside
 * UUIDs and ISO timestamps.
 */
class StudioMutationVersioningPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 21;
    private const SEED = 28_10_10;

    private const TEMPLATES_URL = '/api/studio/templates';
    private const BRAND_URL = '/api/studio/brand';
    private const PROJECTS_URL = '/api/studio/projects';

    private const STUDIO_ROLES = ['admin', 'superadmin', 'editing_manager', 'editor'];
    private const WORKFLOW_IDS = [
        'photo-enhancement',
        'twilight',
        'video-cleanup',
        'listing-video',
        'reel-generator',
        'batch-ai-jobs',
    ];
    private const PROJECT_WORKFLOWS = [
        'photo-enhancement',
        'twilight',
        'batch-ai-jobs',
        'reel-generator',
        'listing-video',
    ];
    private const MUTATIONS = [
        'template.create',
        'template.update',
        'template.delete',
        'brand.update',
        'project.create',
    ];

    /** Values a client may try to inject where the server owns the field. */
    private const CLIENT_SUPPLIED_OVERRIDES = [
        'version' => 9_999,
        'updatedAt' => '1999-01-01T00:00:00.000000Z',
        'updated_at' => '1999-01-01T00:00:00.000000Z',
        'createdAt' => '1999-01-01T00:00:00.000000Z',
        'team_id' => 424_242,
        'created_by' => 424_242,
    ];

    public function test_property_28_mutations_carry_a_version_or_update_timestamp(): void
    {
        mt_srand(self::SEED);
        Queue::fake();

        $coveredMutations = [];
        $cases = 0;

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            match ($iteration % 3) {
                0 => $this->runTemplateLifecycleCase($iteration, $coveredMutations),
                1 => $this->runBrandUpdateCase($iteration, $coveredMutations),
                default => $this->runProjectCreateCase($iteration, $coveredMutations),
            };
            $cases++;
        }

        $this->assertSame(self::ITERATIONS, $cases, 'Every generated case must execute.');
        $this->assertGreaterThanOrEqual(21, $cases, 'The property needs at least 21 reproducible cases.');
        $this->assertEqualsCanonicalizing(
            self::MUTATIONS,
            array_keys($coveredMutations),
            'Every Studio mutation endpoint must be exercised.'
        );
    }

    /**
     * Template create → repeated updates → delete on one record.
     *
     * @param array<string, true> $coveredMutations
     */
    private function runTemplateLifecycleCase(int $iteration, array &$coveredMutations): void
    {
        $teamId = 280_000 + $iteration;
        $role = self::STUDIO_ROLES[mt_rand(0, count(self::STUDIO_ROLES) - 1)];
        $actor = $this->actor($role, $teamId);
        $context = $this->context($iteration, "template lifecycle, role={$role}, team={$teamId}");

        Sanctum::actingAs($actor);

        $createResponse = $this->postJson(self::TEMPLATES_URL, [
            ...self::CLIENT_SUPPLIED_OVERRIDES,
            'name' => $this->generatedName('Template', $iteration),
            'workflowId' => self::WORKFLOW_IDS[mt_rand(0, count(self::WORKFLOW_IDS) - 1)],
            'config' => $this->generatedConfig(),
        ])->assertCreated();
        $createResponse->assertJsonPath('success', true);

        $created = $createResponse->json('data');
        $stamp = $this->assertVersionedMutation($created, "{$context}, mutation=template.create");
        $coveredMutations['template.create'] = true;

        $templateId = (string) $created['id'];
        $record = Template::query()->findOrFail($templateId);
        $this->assertSame((string) $record->id, $templateId, "Created template identity mismatch ({$context}).");
        $this->assertSame(1, $stamp['version'], "Server must own the initial template version ({$context}).");
        $this->assertSame($teamId, (int) $record->team_id, "Server must own the template team scope ({$context}).");
        $this->assertSame(
            (int) $actor->getAuthIdentifier(),
            (int) $record->created_by,
            "Server must own the template owner ({$context})."
        );

        // Two updates keep repeated-mutation version advancement (1 → 2 → 3)
        // exercised on every template record.
        $updates = 2;
        for ($update = 0; $update < $updates; $update++) {
            $this->advanceTime();
            $updateResponse = $this->putJson(self::TEMPLATES_URL . '/' . $templateId, [
                ...self::CLIENT_SUPPLIED_OVERRIDES,
                'name' => $this->generatedName('Template', $iteration) . " v{$update}",
                'workflowId' => self::WORKFLOW_IDS[mt_rand(0, count(self::WORKFLOW_IDS) - 1)],
                'config' => $this->generatedConfig(),
                'version' => $stamp['version'],
            ])->assertOk();
            $updateResponse->assertJsonPath('success', true);

            $updated = $updateResponse->json('data');
            $this->assertSame($templateId, (string) $updated['id'], "Update returned another record ({$context}).");

            $next = $this->assertVersionedMutation($updated, "{$context}, mutation=template.update#{$update}");
            $this->assertAdvances($stamp, $next, "{$context}, mutation=template.update#{$update}");
            $this->assertSame(
                (int) Template::query()->findOrFail($templateId)->version,
                $next['version'],
                "Returned version must be the committed version ({$context})."
            );
            $stamp = $next;
            $coveredMutations['template.update'] = true;
        }

        $this->advanceTime();
        $deleteResponse = $this->deleteJson(self::TEMPLATES_URL . '/' . $templateId, [
            'version' => $stamp['version'],
        ])->assertOk();
        $deleteResponse->assertJsonPath('success', true);

        $deleted = $deleteResponse->json('data');
        $this->assertSame($templateId, (string) $deleted['id'], "Delete returned another record ({$context}).");
        $this->assertTrue($deleted['deleted'] ?? false, "Delete must confirm removal ({$context}).");
        $deleteStamp = $this->assertVersionedMutation($deleted, "{$context}, mutation=template.delete");
        $this->assertSame(
            $stamp['version'],
            $deleteStamp['version'],
            "Delete must report the version it removed ({$context})."
        );
        $this->assertNotNull($deleteStamp['timestamp'], "Delete must carry a server timestamp ({$context}).");
        $this->assertGreaterThanOrEqual(
            $stamp['timestamp'] ?? 0,
            $deleteStamp['timestamp'],
            "Delete timestamp moved backwards ({$context})."
        );
        $this->assertNull(Template::query()->find($templateId), "Template must be removed ({$context}).");
        $coveredMutations['template.delete'] = true;
    }

    /**
     * Repeated brand updates against one team-scoped record.
     *
     * @param array<string, true> $coveredMutations
     */
    private function runBrandUpdateCase(int $iteration, array &$coveredMutations): void
    {
        $teamId = 380_000 + $iteration;
        $role = self::STUDIO_ROLES[mt_rand(0, count(self::STUDIO_ROLES) - 1)];
        $actor = $this->actor($role, $teamId);
        $context = $this->context($iteration, "brand update, role={$role}, team={$teamId}");

        Sanctum::actingAs($actor);

        $stamp = ['version' => 0, 'timestamp' => null];
        // Three updates advance the same brand record twice after its first commit.
        $updates = 3;

        for ($update = 0; $update < $updates; $update++) {
            $this->advanceTime();
            $response = $this->putJson(self::BRAND_URL, [
                'version' => $stamp['version'],
                'settings' => $this->generatedBrandSettings($iteration, $update),
            ])->assertOk();
            $response->assertJsonPath('success', true);

            $data = $response->json('data');
            $this->assertSame($teamId, (int) $data['teamId'], "Brand state escaped its team scope ({$context}).");

            $next = $this->assertVersionedMutation($data, "{$context}, mutation=brand.update#{$update}");
            $this->assertAdvances($stamp, $next, "{$context}, mutation=brand.update#{$update}");
            $this->assertSame(
                (int) BrandState::query()->findOrFail($teamId)->version,
                $next['version'],
                "Returned brand version must be the committed version ({$context})."
            );
            $this->assertSame(
                (int) $actor->getAuthIdentifier(),
                (int) $data['updatedBy'],
                "Server must own the brand updater ({$context})."
            );
            $stamp = $next;
            $coveredMutations['brand.update'] = true;
        }
    }

    /**
     * Project create, its idempotent replay, and the persisted record it returns.
     *
     * @param array<string, true> $coveredMutations
     */
    private function runProjectCreateCase(int $iteration, array &$coveredMutations): void
    {
        $teamId = 480_000 + $iteration;
        $actor = $this->actor('admin', $teamId);
        $workflow = self::PROJECT_WORKFLOWS[mt_rand(0, count(self::PROJECT_WORKFLOWS) - 1)];
        $fileCount = $workflow === 'listing-video' ? mt_rand(6, 10) : mt_rand(1, 2);
        $context = $this->context($iteration, "project create, workflow={$workflow}, files={$fileCount}");

        $shoot = Shoot::factory()->create([
            'created_by' => $actor->id,
            'client_id' => $actor->id,
            'address' => $this->generatedName('Studio Way', $iteration),
        ]);
        $files = $this->shootFiles($shoot, $actor, $fileCount);
        $requestId = sprintf('p28-%03d-%s', $iteration, $workflow);

        Sanctum::actingAs($actor);
        $this->advanceTime();

        $payload = [
            ...self::CLIENT_SUPPLIED_OVERRIDES,
            'request_id' => $requestId,
            'workflow_id' => $workflow,
            'source_type' => 'shoot',
            'shoot_id' => $shoot->id,
            'file_ids' => $files->pluck('id')->all(),
            'workflow_config' => $this->generatedConfig(),
        ];

        $response = $this->postJson(self::PROJECTS_URL, $payload)->assertCreated();
        $response->assertJsonPath('success', true);

        $data = $response->json('data');
        $stamp = $this->assertVersionedMutation($data, "{$context}, mutation=project.create");
        $coveredMutations['project.create'] = true;

        $projectId = (string) $data['projectId'];
        $project = Project::query()->findOrFail($projectId);
        $this->assertSame((string) $project->id, $projectId, "Created project identity mismatch ({$context}).");
        $this->assertSame(
            $projectId,
            (string) ($data['deepLink']['recordId'] ?? ''),
            "Deep-link record identity mismatch ({$context})."
        );
        $this->assertSame(1, $stamp['version'], "Server must own the initial project version ({$context}).");
        $this->assertSame((int) $project->version, $stamp['version'], "Returned project version is not committed ({$context}).");
        $this->assertSame($teamId, (int) $project->team_id, "Server must own the project team scope ({$context}).");

        // Reading the created record back must expose the same server-managed
        // version plus an update timestamp for the mutable record.
        $detail = $this->getJson(self::PROJECTS_URL . '/' . $projectId)->assertOk()->json('data');
        $this->assertSame($projectId, (string) $detail['id'], "Project detail identity mismatch ({$context}).");
        $detailStamp = $this->assertVersionedMutation($detail, "{$context}, mutation=project.create (read-back)");
        $this->assertSame($stamp['version'], $detailStamp['version'], "Read-back version drifted ({$context}).");
        $this->assertNotNull($detailStamp['timestamp'], "Mutable project must expose an update timestamp ({$context}).");

        // An idempotent replay commits nothing, so the version must not advance.
        $this->advanceTime();
        $replay = $this->postJson(self::PROJECTS_URL, $payload)->assertOk();
        $replayData = $replay->json('data');
        $this->assertSame($projectId, (string) $replayData['projectId'], "Idempotent replay returned another project ({$context}).");
        $replayStamp = $this->assertVersionedMutation($replayData, "{$context}, mutation=project.create (replay)");
        $this->assertSame(
            $stamp['version'],
            $replayStamp['version'],
            "Idempotent replay must return the original committed version ({$context})."
        );
        $this->assertSame(1, Project::query()->where('request_id', $requestId)->count(), "Replay duplicated a project ({$context}).");
    }

    /**
     * A successful mutation payload must carry a server-managed version
     * identifier or update timestamp, and any present value must be well formed.
     *
     * @param array<string, mixed> $data
     * @return array{version: ?int, timestamp: ?int}
     */
    private function assertVersionedMutation(array $data, string $context): array
    {
        $version = null;
        if (array_key_exists('version', $data) && $data['version'] !== null) {
            $this->assertIsInt($data['version'], "Version must be an integer identifier ({$context}).");
            $this->assertGreaterThanOrEqual(1, $data['version'], "Version must be positive ({$context}).");
            $version = (int) $data['version'];
        }

        $timestamp = null;
        foreach (['updatedAt', 'deletedAt', 'lastActivityAt'] as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            $this->assertIsString($data[$key], "Timestamp {$key} must be a string ({$context}).");
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/',
                $data[$key],
                "Timestamp {$key} must be ISO 8601 ({$context})."
            );
            $timestamp = Carbon::parse($data[$key])->getTimestamp();
            break;
        }

        $this->assertTrue(
            $version !== null || $timestamp !== null,
            "Mutation response carried neither a version nor an update timestamp ({$context})."
        );

        return ['version' => $version, 'timestamp' => $timestamp];
    }

    /**
     * Repeated mutations of the same record advance its version strictly and
     * never move its update timestamp backwards.
     *
     * @param array{version: ?int, timestamp: ?int} $previous
     * @param array{version: ?int, timestamp: ?int} $next
     */
    private function assertAdvances(array $previous, array $next, string $context): void
    {
        if ($previous['version'] !== null && $next['version'] !== null) {
            $this->assertGreaterThan(
                $previous['version'],
                $next['version'],
                "Version did not advance for a repeated mutation ({$context})."
            );
        }

        if ($previous['timestamp'] !== null && $next['timestamp'] !== null) {
            $this->assertGreaterThanOrEqual(
                $previous['timestamp'],
                $next['timestamp'],
                "Update timestamp moved backwards for a repeated mutation ({$context})."
            );
        }

        $this->assertTrue(
            $next['version'] !== null || $next['timestamp'] !== null,
            "Repeated mutation lost its version and timestamp ({$context})."
        );
    }

    private function advanceTime(): void
    {
        $this->travel(mt_rand(1, 3))->seconds();
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    /** @return Collection<int, ShootFile> */
    private function shootFiles(Shoot $shoot, User $user, int $count): Collection
    {
        return collect(range(1, $count))->map(fn (int $index): ShootFile => ShootFile::query()->create([
            'shoot_id' => $shoot->id,
            'filename' => "p28-{$shoot->id}-{$index}.jpg",
            'stored_filename' => "p28-{$shoot->id}-{$index}.jpg",
            'path' => "shoots/{$shoot->id}/p28-{$index}.jpg",
            'storage_path' => "shoots/{$shoot->id}/p28-{$index}.jpg",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024 * mt_rand(1, 8),
            'uploaded_by' => $user->id,
            'media_type' => 'raw',
            'workflow_stage' => ShootFile::STAGE_TODO,
        ]));
    }

    private function generatedName(string $prefix, int $iteration): string
    {
        return sprintf('%s %03d-%02d', $prefix, $iteration, mt_rand(0, 99));
    }

    /** @return array<string, mixed> */
    private function generatedConfig(): array
    {
        return [
            'strength' => mt_rand(0, 100),
            'preset' => ['warm', 'neutral', 'cool'][mt_rand(0, 2)],
            'include_branding' => mt_rand(0, 1) === 1,
        ];
    }

    /** @return array<string, mixed> */
    private function generatedBrandSettings(int $iteration, int $update): array
    {
        $settings = [
            'logo' => sprintf('brands/%03d-%d.svg', $iteration, $update),
            'primary_color' => sprintf('#%06X', mt_rand(0, 0xFFFFFF)),
        ];

        if (mt_rand(0, 1) === 1) {
            $settings['font_family'] = ['Inter', 'Roboto', 'Poppins'][mt_rand(0, 2)];
        }
        if (mt_rand(0, 1) === 1) {
            $settings['include_logo'] = mt_rand(0, 1) === 1;
        }
        if (mt_rand(0, 1) === 1) {
            $settings['output_naming'] = sprintf('listing-%03d-%d', $iteration, $update);
        }

        return $settings;
    }

    private function context(int $iteration, string $detail): string
    {
        return 'seed=' . self::SEED . ", iteration={$iteration}, {$detail}";
    }
}
