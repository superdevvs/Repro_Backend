<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 36: Workflow submission creates matching job types
 *
 * **Validates: Requirements 13.2, 13.3, 13.4, 13.5, 13.6**
 *
 * A seeded generator drives 20 reproducible submissions of `POST
 * /api/studio/projects` across Photo Enhancement, Twilight, Video Cleanup,
 * Listing Video, and Reel Generator. For every submission the created
 * server-side AI_Job(s) must have exactly the job type that corresponds to the
 * submitted Workflow — photo enhancement (`enhance`), sky replacement
 * (`sky_replace`), video cleanup (`video-cleanup`), listing video, or reel
 * generation — and no job of any other type may be created, either for that
 * project or anywhere else in the database.
 *
 * Identity checks are structural: returned AI_Job ids and project ids are
 * compared as whole values against persisted primary keys (never as substrings
 * of a serialized payload), because short numeric ids appear incidentally
 * inside UUIDs and ISO timestamps.
 */
class StudioWorkflowJobTypePropertyTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 20;
    private const SEED = 36_13_02;

    private const PROJECTS_URL = '/api/studio/projects';

    private const STUDIO_ROLES = ['admin', 'superadmin', 'editing_manager', 'editor'];

    /** Workflow → expected server-side job identity. */
    private const EXPECTATIONS = [
        'photo-enhancement' => ['model' => 'photo', 'editingType' => AiEditingJob::TYPE_ENHANCE, 'responseType' => 'photo'],
        'twilight' => ['model' => 'photo', 'editingType' => AiEditingJob::TYPE_SKY_REPLACE, 'responseType' => 'photo'],
        'video-cleanup' => ['model' => 'photo', 'editingType' => 'video-cleanup', 'responseType' => 'photo'],
        'listing-video' => ['model' => 'listing-video', 'editingType' => null, 'responseType' => 'listing-video'],
        'reel-generator' => ['model' => 'reel', 'editingType' => null, 'responseType' => 'reel'],
    ];

    private const WORKFLOWS = [
        'photo-enhancement',
        'twilight',
        'video-cleanup',
        'listing-video',
        'reel-generator',
    ];

    public function test_property_36_workflow_submission_creates_matching_job_types(): void
    {
        mt_srand(self::SEED);
        Queue::fake();

        $coveredWorkflows = [];
        $cases = 0;

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $workflow = self::WORKFLOWS[$iteration % count(self::WORKFLOWS)];
            $this->runWorkflowCase($iteration, $workflow);
            $coveredWorkflows[$workflow] = true;
            $cases++;
        }

        $this->assertSame(self::ITERATIONS, $cases, 'Every generated case must execute.');
        $this->assertGreaterThanOrEqual(20, $cases, 'The property needs at least 20 reproducible cases.');
        $this->assertEqualsCanonicalizing(
            self::WORKFLOWS,
            array_keys($coveredWorkflows),
            'Every workflow in Requirements 13.2-13.6 must be exercised.'
        );
    }

    private function runWorkflowCase(int $iteration, string $workflow): void
    {
        $teamId = 360_000 + $iteration;
        $role = self::STUDIO_ROLES[mt_rand(0, count(self::STUDIO_ROLES) - 1)];
        $actor = $this->actor($role, $teamId);
        $sourceCount = $this->sourceCount($workflow);
        $context = $this->context($iteration, "workflow={$workflow}, role={$role}, sources={$sourceCount}");
        $expectation = self::EXPECTATIONS[$workflow];

        $shoot = Shoot::factory()->create([
            'created_by' => $actor->id,
            'client_id' => $actor->id,
            // Assigned to the actor so every generated role (including editor)
            // is authorized for the shoot media; authorization itself is
            // covered by its own property.
            'editor_id' => $actor->id,
            'address' => sprintf('%d Studio Way %03d', mt_rand(1, 999), $iteration),
        ]);
        $files = $workflow === 'video-cleanup'
            ? $this->videoFiles($shoot, $actor, $sourceCount, $iteration)
            : $this->imageFiles($shoot, $actor, $sourceCount, $iteration);

        $before = $this->jobCounts();

        Sanctum::actingAs($actor);
        $response = $this->postJson(self::PROJECTS_URL, [
            'request_id' => sprintf('p36-%03d-%s', $iteration, $workflow),
            'workflow_id' => $workflow,
            'source_type' => 'shoot',
            'shoot_id' => $shoot->id,
            'file_ids' => $files->pluck('id')->all(),
            'workflow_config' => $this->generatedConfig(),
        ])->assertCreated();
        $response->assertJsonPath('success', true);

        $data = $response->json('data');
        $projectId = (string) $data['projectId'];
        $project = Project::query()->findOrFail($projectId);
        $this->assertSame((string) $project->id, $projectId, "Created project identity mismatch ({$context}).");
        $this->assertSame($workflow, (string) $project->workflow_id, "Project workflow mismatch ({$context}).");

        $expectedJobCount = $expectation['model'] === 'photo' ? $sourceCount : 1;
        $persisted = $this->projectJobIds($projectId);

        // The expected job family holds exactly the expected jobs...
        $this->assertCount(
            $expectedJobCount,
            $persisted[$expectation['model']],
            "Workflow {$workflow} must create {$expectedJobCount} job(s) of its own type ({$context})."
        );

        // ...and no other job family received anything for this project.
        foreach ($persisted as $model => $ids) {
            if ($model === $expectation['model']) {
                continue;
            }
            $this->assertSame(
                [],
                $ids,
                "Workflow {$workflow} created an unexpected {$model} job ({$context})."
            );
        }

        // Nor anywhere else in the database.
        $after = $this->jobCounts();
        foreach ($after as $model => $count) {
            $delta = $count - $before[$model];
            $this->assertSame(
                $model === $expectation['model'] ? $expectedJobCount : 0,
                $delta,
                "Workflow {$workflow} changed the {$model} job total unexpectedly ({$context})."
            );
        }

        if ($expectation['editingType'] !== null) {
            foreach (AiEditingJob::query()->where('project_id', $projectId)->get() as $job) {
                $this->assertSame(
                    $expectation['editingType'],
                    (string) $job->editing_type,
                    "Workflow {$workflow} produced the wrong editing type ({$context})."
                );
            }
        }

        // The response identifies exactly the persisted jobs, structurally.
        $this->assertEqualsCanonicalizing(
            $persisted[$expectation['model']],
            array_map(static fn ($id): string => (string) $id, (array) $data['aiJobIds']),
            "Returned AI_Job ids do not match the persisted jobs ({$context})."
        );
        foreach ((array) $data['jobs'] as $job) {
            $this->assertSame(
                $expectation['responseType'],
                (string) $job['type'],
                "Returned job type does not match the workflow ({$context})."
            );
            $this->assertContains(
                (string) $job['id'],
                $persisted[$expectation['model']],
                "Returned job id is not a persisted job of the expected type ({$context})."
            );
        }
    }

    /** @return array{photo: list<string>, 'listing-video': list<string>, reel: list<string>} */
    private function projectJobIds(string $projectId): array
    {
        return [
            'photo' => AiEditingJob::query()->where('project_id', $projectId)->orderBy('id')
                ->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            'listing-video' => AiListingVideoJob::query()->where('project_id', $projectId)->orderBy('id')
                ->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            'reel' => AiReelJob::query()->where('project_id', $projectId)->orderBy('id')
                ->pluck('id')->map(fn ($id): string => (string) $id)->all(),
        ];
    }

    /** @return array{photo: int, 'listing-video': int, reel: int} */
    private function jobCounts(): array
    {
        return [
            'photo' => AiEditingJob::query()->count(),
            'listing-video' => AiListingVideoJob::query()->count(),
            'reel' => AiReelJob::query()->count(),
        ];
    }

    private function sourceCount(string $workflow): int
    {
        return match ($workflow) {
            // Listing video keeps its valid 6..10 window; the other workflows
            // create one job per source file, so smaller counts cut runtime
            // without weakening the property.
            'listing-video' => mt_rand(6, 7),
            'reel-generator' => mt_rand(1, 3),
            default => mt_rand(1, 2),
        };
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    /** @return Collection<int, ShootFile> */
    private function imageFiles(Shoot $shoot, User $user, int $count, int $iteration): Collection
    {
        return collect(range(1, $count))->map(fn (int $index): ShootFile => $this->shootFile($shoot, $user, [
            'filename' => "p36-{$iteration}-{$index}.jpg",
            'stored_filename' => "p36-{$iteration}-{$index}.jpg",
            'path' => "shoots/{$shoot->id}/p36-{$index}.jpg",
            'storage_path' => "shoots/{$shoot->id}/p36-{$index}.jpg",
            'file_type' => 'image/jpeg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'raw',
        ]));
    }

    /** @return Collection<int, ShootFile> */
    private function videoFiles(Shoot $shoot, User $user, int $count, int $iteration): Collection
    {
        return collect(range(1, $count))->map(fn (int $index): ShootFile => $this->shootFile($shoot, $user, [
            'filename' => "p36-{$iteration}-{$index}.mp4",
            'stored_filename' => "p36-{$iteration}-{$index}.mp4",
            'path' => "shoots/{$shoot->id}/p36-{$index}.mp4",
            'storage_path' => "shoots/{$shoot->id}/p36-{$index}.mp4",
            'file_type' => 'video/mp4',
            'mime_type' => 'video/mp4',
            'media_type' => 'video',
        ]));
    }

    /** @param array<string, mixed> $attributes */
    private function shootFile(Shoot $shoot, User $user, array $attributes): ShootFile
    {
        return ShootFile::query()->create([
            'shoot_id' => $shoot->id,
            'file_size' => 1024 * mt_rand(1, 8),
            'uploaded_by' => $user->id,
            'workflow_stage' => ShootFile::STAGE_TODO,
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function generatedConfig(): array
    {
        return [
            'strength' => mt_rand(0, 100),
            'preset' => ['warm', 'neutral', 'cool'][mt_rand(0, 2)],
        ];
    }

    private function context(int $iteration, string $detail): string
    {
        return 'seed=' . self::SEED . ", iteration={$iteration}, {$detail}";
    }
}
