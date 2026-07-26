<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 26: Recent Projects are unique and activity-ordered.
 *
 * For any set of Project activity, `GET /api/studio/projects` lists each authorized
 * Project exactly once and orders entries by the server-side activity timestamp
 * (the later of the Project update time and its latest AI_Job update time) in
 * descending order.
 *
 * **Validates: Requirements 9.1, 9.2, 16.7**
 *
 * No PBT library is configured for PHPUnit, so a fixed seed drives 24 reproducible
 * datasets (4 forced cases followed by 20 randomized ones). Forced cases guarantee
 * coverage of multiple jobs per project, mixed photo/video/reel activity, fully equal
 * activity timestamps, projects without jobs, job activity older than project activity,
 * out-of-team projects, and editor ownership scoping.
 *
 * @group ai-editing-studio-revamp
 */
class StudioRecentProjectsOrderingPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/projects';
    private const ITERATIONS = 24;
    private const SEED = 20260801;
    private const TEAM_A = 61;
    private const TEAM_B = 62;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_property_26_recent_projects_are_unique_and_activity_ordered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00 UTC'));

        $admin = $this->teamUser('admin', self::TEAM_A);
        $editorOne = $this->teamUser('editor', self::TEAM_A);
        $editorTwo = $this->teamUser('editor', self::TEAM_A);
        $outsider = $this->teamUser('editor', self::TEAM_B);
        $shoot = Shoot::factory()->create(['address' => '9 Ordering Way', 'hero_image' => '/shoot/hero.jpg']);

        $projects = [
            $this->project($editorOne, self::TEAM_A, $shoot, 'editor-one-a'),
            $this->project($editorOne, self::TEAM_A, $shoot, 'editor-one-b'),
            $this->project($editorTwo, self::TEAM_A, $shoot, 'editor-two-a'),
            $this->project($editorTwo, self::TEAM_A, $shoot, 'editor-two-b'),
            $this->project($outsider, self::TEAM_B, $shoot, 'outsider-a'),
            $this->project($outsider, self::TEAM_B, $shoot, 'outsider-b'),
        ];
        $actors = [$admin, $editorOne, $editorTwo];

        mt_srand(self::SEED);
        $coverage = array_fill_keys([
            'photo', 'video', 'reel', 'multipleJobsPerProject', 'noJobs',
            'equalActivityTimestamps', 'projectActivityDominant', 'jobActivityDominant',
            'otherTeamExcluded', 'editorOwnershipExcluded',
        ], false);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $this->clearJobs();
            $actor = $actors[$iteration % count($actors)];
            $dataset = $this->generateDataset($iteration, $projects, $shoot);

            $expected = [];
            $equalCandidates = [];

            foreach ($dataset as $entry) {
                /** @var Project $project */
                $project = $entry['project'];
                $this->setUpdatedAt('projects', $project->id, $entry['projectUpdatedAt']);

                $latestJobAt = null;
                foreach ($entry['jobs'] as $job) {
                    $this->persistJob($project, $shoot, $job);
                    $coverage[$job['type']] = true;
                    if ($latestJobAt === null || $job['updatedAt']->greaterThan($latestJobAt)) {
                        $latestJobAt = $job['updatedAt']->copy();
                    }
                }

                if (count($entry['jobs']) > 1) {
                    $coverage['multipleJobsPerProject'] = true;
                }
                if ($entry['jobs'] === []) {
                    $coverage['noJobs'] = true;
                }

                $activityAt = $latestJobAt !== null && $latestJobAt->greaterThan($entry['projectUpdatedAt'])
                    ? $latestJobAt
                    : $entry['projectUpdatedAt']->copy();

                if ($latestJobAt !== null) {
                    $coverage[$latestJobAt->greaterThan($entry['projectUpdatedAt'])
                        ? 'jobActivityDominant'
                        : 'projectActivityDominant'] = true;
                }

                $inTeam = (int) $project->team_id === self::TEAM_A;
                $ownedWhenEditor = $actor->role !== 'editor'
                    || (int) $project->created_by === (int) $actor->id;

                if ($inTeam && $ownedWhenEditor) {
                    $expected[(string) $project->id] = $activityAt->toISOString();
                    $equalCandidates[] = $activityAt->toISOString();
                } elseif (!$inTeam) {
                    $coverage['otherTeamExcluded'] = true;
                } else {
                    $coverage['editorOwnershipExcluded'] = true;
                }
            }

            if (count($equalCandidates) > 1 && count(array_unique($equalCandidates)) === 1) {
                $coverage['equalActivityTimestamps'] = true;
            }

            Sanctum::actingAs($actor);
            $data = $this->getJson(self::URL)->assertOk()->json('data');

            $returnedIds = array_map(static fn (array $row): string => (string) $row['id'], $data);
            $returnedActivity = [];
            foreach ($data as $row) {
                $returnedActivity[(string) $row['id']] = (string) $row['lastActivityAt'];
            }

            $counterexample = sprintf(
                'seed=%d iteration=%d actor=%s:%d dataset=%s expected=%s returned=%s',
                self::SEED,
                $iteration,
                $actor->role,
                $actor->id,
                json_encode($this->describeDataset($dataset), JSON_THROW_ON_ERROR),
                json_encode($expected, JSON_THROW_ON_ERROR),
                json_encode($returnedActivity, JSON_THROW_ON_ERROR)
            );

            // Each authorized Project appears exactly once (Req 9.2).
            $this->assertSame(
                count($returnedIds),
                count(array_unique($returnedIds)),
                'Duplicate project entries. '.$counterexample
            );

            // Identity comparison is structural: same authorized id set, order-independent.
            $this->assertEqualsCanonicalizing(
                array_keys($expected),
                $returnedIds,
                'Authorized project identities differ. '.$counterexample
            );

            // Server-side activity timestamps are reported per project (Req 16.7).
            foreach ($expected as $projectId => $activityAt) {
                $this->assertSame(
                    $activityAt,
                    $returnedActivity[$projectId] ?? null,
                    "Activity timestamp mismatch for project {$projectId}. ".$counterexample
                );
            }

            // Results are ordered by activity timestamp descending (Req 9.1).
            $orderedActivity = array_map(
                static fn (array $row): string => (string) $row['lastActivityAt'],
                $data
            );
            for ($index = 1; $index < count($orderedActivity); $index++) {
                $this->assertTrue(
                    strcmp($orderedActivity[$index - 1], $orderedActivity[$index]) >= 0,
                    'Activity ordering is not descending. '.$counterexample
                );
            }
        }

        foreach ($coverage as $dimension => $seen) {
            $this->assertTrue($seen, "Generator did not cover {$dimension}.");
        }
    }

    /**
     * @param list<Project> $projects
     * @return list<array{project: Project, projectUpdatedAt: Carbon, jobs: list<array<string, mixed>>}>
     */
    private function generateDataset(int $iteration, array $projects, Shoot $shoot): array
    {
        $base = now()->copy()->subDays(10);
        $dataset = [];

        // Forced case: every project and job shares one activity timestamp.
        if ($iteration === 0) {
            foreach ($projects as $project) {
                $dataset[] = [
                    'project' => $project,
                    'projectUpdatedAt' => $base->copy(),
                    'jobs' => [
                        $this->job('photo', $base->copy()),
                        $this->job('video', $base->copy()),
                        $this->job('reel', $base->copy()),
                    ],
                ];
            }

            return $dataset;
        }

        // Forced case: multiple mixed-type jobs per project with distinct activity.
        if ($iteration === 1) {
            foreach ($projects as $offset => $project) {
                $dataset[] = [
                    'project' => $project,
                    'projectUpdatedAt' => $base->copy()->addHours($offset),
                    'jobs' => [
                        $this->job('photo', $base->copy()->addHours($offset)->addMinutes(5)),
                        $this->job('video', $base->copy()->addHours($offset)->addMinutes(11)),
                        $this->job('reel', $base->copy()->addHours($offset)->addMinutes(2)),
                    ],
                ];
            }

            return $dataset;
        }

        // Forced case: no job activity anywhere, project update time only.
        if ($iteration === 2) {
            foreach ($projects as $offset => $project) {
                $dataset[] = [
                    'project' => $project,
                    'projectUpdatedAt' => $base->copy()->addDays($offset % 3),
                    'jobs' => [],
                ];
            }

            return $dataset;
        }

        // Forced case: job activity strictly older than the project update time.
        if ($iteration === 3) {
            foreach ($projects as $offset => $project) {
                $dataset[] = [
                    'project' => $project,
                    'projectUpdatedAt' => $base->copy()->addHours(12 + $offset),
                    'jobs' => [$this->job('photo', $base->copy()->addHours($offset))],
                ];
            }

            return $dataset;
        }

        $slots = [0, 60, 60, 3600, 3600, 7200, 86400, 172800];
        foreach ($projects as $project) {
            $jobCount = mt_rand(0, 3);
            $jobs = [];
            for ($index = 0; $index < $jobCount; $index++) {
                $jobs[] = $this->job(
                    ['photo', 'video', 'reel'][mt_rand(0, 2)],
                    $base->copy()->addSeconds($slots[mt_rand(0, count($slots) - 1)])
                );
            }

            $dataset[] = [
                'project' => $project,
                'projectUpdatedAt' => $base->copy()->addSeconds($slots[mt_rand(0, count($slots) - 1)]),
                'jobs' => $jobs,
            ];
        }

        return $dataset;
    }

    /** @return array<string, mixed> */
    private function job(string $type, Carbon $updatedAt): array
    {
        return ['type' => $type, 'updatedAt' => $updatedAt];
    }

    /** @param array<string, mixed> $job */
    private function persistJob(Project $project, Shoot $shoot, array $job): void
    {
        $model = match ($job['type']) {
            'photo' => AiEditingJob::create([
                'project_id' => $project->id,
                'shoot_id' => $shoot->id,
                'user_id' => $project->created_by,
                'status' => AiEditingJob::STATUS_PROCESSING,
                'editing_type' => AiEditingJob::TYPE_ENHANCE,
                'original_image_url' => '/media/original.jpg',
            ]),
            'video' => AiListingVideoJob::create([
                'project_id' => $project->id,
                'shoot_id' => $shoot->id,
                'user_id' => $project->created_by,
                'provider' => 'fal',
                'selected_file_ids' => [1],
                'target_seconds' => 30,
                'status' => AiListingVideoJob::STATUS_QUEUED,
            ]),
            default => AiReelJob::create([
                'project_id' => $project->id,
                'shoot_id' => $shoot->id,
                'user_id' => $project->created_by,
                'provider' => 'fal',
                'selected_file_ids' => [1],
                'status' => AiReelJob::STATUS_QUEUED,
            ]),
        };

        $this->setUpdatedAt($model->getTable(), $model->getKey(), $job['updatedAt']);
    }

    private function setUpdatedAt(string $table, int|string $id, Carbon $at): void
    {
        DB::table($table)->where('id', $id)->update(['updated_at' => $at]);
    }

    private function clearJobs(): void
    {
        AiEditingJob::query()->delete();
        AiListingVideoJob::query()->delete();
        AiReelJob::query()->delete();
    }

    /**
     * @param list<array{project: Project, projectUpdatedAt: Carbon, jobs: list<array<string, mixed>>}> $dataset
     * @return list<array<string, mixed>>
     */
    private function describeDataset(array $dataset): array
    {
        return array_map(static fn (array $entry): array => [
            'project' => (string) $entry['project']->id,
            'team' => (int) $entry['project']->team_id,
            'owner' => (int) $entry['project']->created_by,
            'projectUpdatedAt' => $entry['projectUpdatedAt']->toIso8601String(),
            'jobs' => array_map(static fn (array $job): array => [
                'type' => $job['type'],
                'updatedAt' => $job['updatedAt']->toIso8601String(),
            ], $entry['jobs']),
        ], $dataset);
    }

    private function teamUser(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function project(User $owner, int $teamId, Shoot $shoot, string $name): Project
    {
        return Project::create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'shoot_id' => $shoot->id,
            'name' => $name,
            'address' => $shoot->address,
            'source_type' => 'shoot',
            'workflow_id' => 'photo-enhancement',
            'status' => 'submitted',
        ]);
    }
}
