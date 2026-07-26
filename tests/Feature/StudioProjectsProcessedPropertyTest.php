<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature: ai-editing-studio-revamp, Property 23: Projects Processed counts each project once.
 *
 * For every generated photo/video job set, Projects Processed must equal the
 * distinct authorized projects having activity in the inclusive 30-day window.
 *
 * **Validates: Requirements 8.2, 16.6**
 *
 * PHPUnit has no PBT library configured, so a fixed seed drives 24 reproducible
 * datasets. Forced cases ensure duplicate cross-table jobs, both boundaries,
 * other teams, and editor ownership are covered rather than left to chance.
 *
 * @group ai-editing-studio-revamp
 */
class StudioProjectsProcessedPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/metrics/summary';
    private const ITERATIONS = 24;
    private const SEED = 20260730;
    private const TEAM_A = 44;
    private const TEAM_B = 99;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_property_23_projects_processed_counts_each_authorized_project_once(): void
    {
        Carbon::setTestNow('2026-07-30T12:00:00+00:00');
        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subDays(30);

        $admin = $this->user('admin', self::TEAM_A);
        $editor = $this->user('editor', self::TEAM_A);
        $teammate = $this->user('editor', self::TEAM_A);
        $outsider = $this->user('editor', self::TEAM_B);

        $projects = [
            $this->project($editor, self::TEAM_A, 'editor-a-1'),
            $this->project($editor, self::TEAM_A, 'editor-a-2'),
            $this->project($teammate, self::TEAM_A, 'teammate-1'),
            $this->project($teammate, self::TEAM_A, 'teammate-2'),
            $this->project($outsider, self::TEAM_B, 'outsider-1'),
            $this->project($outsider, self::TEAM_B, 'outsider-2'),
        ];

        mt_srand(self::SEED);
        $coverage = array_fill_keys([
            'photo', 'video', 'duplicate', 'windowStart', 'windowEnd',
            'outsideWindow', 'otherTeam', 'editorOwnership',
        ], false);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            AiEditingJob::query()->delete();
            AiListingVideoJob::query()->delete();

            $actor = $iteration % 2 === 0 ? $admin : $editor;
            $jobs = $this->generateJobs($iteration, $projects, $windowStart, $windowEnd);
            $expectedProjects = [];
            $jobsPerProject = [];

            foreach ($jobs as $job) {
                $this->persistJob($job);
                $coverage[$job['type']] = true;
                $coverage[$job['timeLabel']] = true;
                $jobsPerProject[$job['project']->id] = ($jobsPerProject[$job['project']->id] ?? 0) + 1;

                $inWindow = $this->hasActivityInWindow($job, $windowStart, $windowEnd);
                $sameTeam = (int) $job['project']->team_id === self::TEAM_A;
                $ownedWhenEditor = $actor->role !== 'editor' || (int) $job['owner']->id === (int) $actor->id;

                if ($inWindow && $sameTeam && $ownedWhenEditor) {
                    $expectedProjects[$job['project']->id] = true;
                }
                if ($inWindow && !$sameTeam) {
                    $coverage['otherTeam'] = true;
                }
                if ($inWindow && $sameTeam && $actor->role === 'editor' && !$ownedWhenEditor) {
                    $coverage['editorOwnership'] = true;
                }
            }

            if (max($jobsPerProject ?: [0]) > 1) {
                $coverage['duplicate'] = true;
            }

            Sanctum::actingAs($actor);
            $response = $this->getJson(self::URL)->assertOk();
            $actual = (int) $response->json('data.projectsProcessed');
            $counterexample = sprintf(
                'seed=%d iteration=%d actor=%s:%d jobs=%s expected=%d actual=%d',
                self::SEED,
                $iteration,
                $actor->role,
                $actor->id,
                json_encode($this->describeJobs($jobs), JSON_THROW_ON_ERROR),
                count($expectedProjects),
                $actual
            );

            $this->assertSame(count($expectedProjects), $actual, $counterexample);
        }

        foreach ($coverage as $dimension => $seen) {
            $this->assertTrue($seen, "Generator did not cover {$dimension}.");
        }
    }

    /** @param list<Project> $projects @return list<array<string, mixed>> */
    private function generateJobs(int $iteration, array $projects, Carbon $start, Carbon $end): array
    {
        if ($iteration === 0) {
            return [
                $this->job('photo', $projects[0], 0, $start, $end),
                $this->job('video', $projects[0], 1, $start, $end),
                $this->job('photo', $projects[0], 7, $start, $end),
                $this->job('video', $projects[2], 7, $start, $end),
                $this->job('photo', $projects[4], 7, $start, $end),
                $this->job('video', $projects[1], 2, $start, $end),
            ];
        }

        if ($iteration === 1) {
            return [
                $this->job('photo', $projects[0], 7, $start, $end),
                $this->job('video', $projects[2], 7, $start, $end),
                $this->job('photo', $projects[4], 7, $start, $end),
                $this->job('video', $projects[1], 3, $start, $end),
            ];
        }

        if ($iteration === 2) {
            return [];
        }

        $jobs = [];
        foreach ($projects as $project) {
            $count = mt_rand(0, 5);
            for ($index = 0; $index < $count; $index++) {
                $jobs[] = $this->job(
                    mt_rand(0, 1) === 0 ? 'photo' : 'video',
                    $project,
                    mt_rand(0, 7),
                    $start,
                    $end
                );
            }
        }

        return $jobs;
    }

    /** @return array<string, mixed> */
    private function job(string $type, Project $project, int $timeMode, Carbon $start, Carbon $end): array
    {
        [$createdAt, $updatedAt, $completedAt, $label] = $this->timestamps($timeMode, $start, $end);

        return [
            'type' => $type,
            'project' => $project,
            'owner' => $project->createdBy,
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt,
            'completedAt' => $completedAt,
            'timeLabel' => $label,
        ];
    }

    /** @return array{Carbon, Carbon, ?Carbon, string} */
    private function timestamps(int $mode, Carbon $start, Carbon $end): array
    {
        $inside = $start->copy()->addSeconds(mt_rand(1, 30 * 24 * 60 * 60 - 1));

        return match ($mode) {
            0 => [$start->copy(), $start->copy(), $start->copy(), 'windowStart'],
            1 => [$end->copy(), $end->copy(), $end->copy(), 'windowEnd'],
            2 => [$start->copy()->subSecond(), $start->copy()->subSecond(), null, 'outsideWindow'],
            3 => [$end->copy()->addSecond(), $end->copy()->addSecond(), null, 'outsideWindow'],
            4 => [$inside->copy(), $inside->copy(), null, 'insideWindow'],
            5 => [$start->copy()->subDay(), $inside->copy(), null, 'insideWindow'],
            6 => [$start->copy()->subDay(), $inside->copy(), $inside->copy(), 'insideWindow'],
            default => [$inside->copy(), $inside->copy(), $inside->copy(), 'insideWindow'],
        };
    }

    /** @param array<string, mixed> $job */
    private function persistJob(array $job): void
    {
        $timestamps = [
            'created_at' => $job['createdAt'],
            'updated_at' => $job['updatedAt'],
            'completed_at' => $job['completedAt'],
        ];

        if ($job['type'] === 'photo') {
            DB::table('ai_editing_jobs')->insert($timestamps + [
                'project_id' => $job['project']->id,
                'shoot_id' => $job['project']->shoot_id,
                'user_id' => $job['owner']->id,
                'status' => $job['completedAt'] ? AiEditingJob::STATUS_COMPLETED : AiEditingJob::STATUS_PROCESSING,
                'editing_type' => AiEditingJob::TYPE_ENHANCE,
                'original_image_url' => 'https://example.test/photo.jpg',
            ]);

            return;
        }

        DB::table('ai_listing_video_jobs')->insert($timestamps + [
            'project_id' => $job['project']->id,
            'shoot_id' => $job['project']->shoot_id,
            'user_id' => $job['owner']->id,
            'provider' => 'fal',
            'selected_file_ids' => json_encode([1], JSON_THROW_ON_ERROR),
            'target_seconds' => 30,
            'status' => $job['completedAt'] ? AiListingVideoJob::STATUS_COMPLETED : AiListingVideoJob::STATUS_PROCESSING,
        ]);
    }

    /** @param array<string, mixed> $job */
    private function hasActivityInWindow(array $job, Carbon $start, Carbon $end): bool
    {
        foreach ([$job['createdAt'], $job['updatedAt'], $job['completedAt']] as $timestamp) {
            if ($timestamp !== null && $timestamp->betweenIncluded($start, $end)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private function describeJobs(array $jobs): array
    {
        return array_map(static fn (array $job): array => [
            'type' => $job['type'],
            'project' => $job['project']->id,
            'team' => $job['project']->team_id,
            'owner' => $job['owner']->id,
            'createdAt' => $job['createdAt']->toIso8601String(),
            'updatedAt' => $job['updatedAt']->toIso8601String(),
            'completedAt' => $job['completedAt']?->toIso8601String(),
        ], $jobs);
    }

    private function user(string $role, int $teamId): User
    {
        return User::factory()->create(['role' => $role, 'metadata' => ['team_id' => $teamId]]);
    }

    private function project(User $owner, int $teamId, string $name): Project
    {
        return Project::create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'shoot_id' => Shoot::factory()->create()->id,
            'name' => $name,
            'source_type' => 'shoot',
            'workflow_id' => 'photo-enhancement',
            'status' => 'submitted',
        ]);
    }
}
