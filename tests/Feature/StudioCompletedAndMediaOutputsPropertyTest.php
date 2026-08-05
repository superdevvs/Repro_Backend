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
 * Feature: ai-editing-studio-revamp, Property 24: Completed and media-output counts match window filters.
 *
 * For every generated dataset of photo/video jobs and persisted project media,
 * `aiJobsCompleted` must equal the authorized jobs that entered a successful
 * terminal status inside the inclusive 30-day Measurement_Window, and
 * `mediaOutputs` must equal the authorized persisted output-kind media records
 * created inside that window for projects with such a successful job.
 *
 * **Validates: Requirements 8.3, 8.6**
 *
 * PHPUnit has no PBT library configured, so a fixed seed drives 24 reproducible
 * datasets. Forced cases guarantee coverage of both job tables, all terminal and
 * non-terminal statuses, both window boundaries, out-of-window records, source
 * vs output media kinds, other-team records, and editor ownership rather than
 * leaving those dimensions to chance. Identities are compared structurally
 * (ids as integers/uuids), never by substring matching.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('ai-editing-studio-revamp')]
class StudioCompletedAndMediaOutputsPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/metrics/summary';
    private const ITERATIONS = 24;
    private const SEED = 20260731;
    private const TEAM_A = 44;
    private const TEAM_B = 99;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_property_24_completed_and_media_output_counts_match_window_filters(): void
    {
        Carbon::setTestNow('2026-07-31T09:00:00+00:00');
        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subDays(30);

        $admin = $this->user('admin', self::TEAM_A);
        $editor = $this->user('editor', self::TEAM_A);
        $teammate = $this->user('editor', self::TEAM_A);
        $outsider = $this->user('editor', self::TEAM_B);

        $projects = [
            $this->project($editor, self::TEAM_A, 'editor-1'),
            $this->project($editor, self::TEAM_A, 'editor-2'),
            $this->project($teammate, self::TEAM_A, 'teammate-1'),
            $this->project($outsider, self::TEAM_B, 'outsider-1'),
        ];

        mt_srand(self::SEED);
        $coverage = array_fill_keys([
            'photoCompleted', 'videoCompleted', 'photoFailed', 'videoFailed',
            'cancelled', 'nonTerminal', 'nullCompletion',
            'windowStartBoundary', 'windowEndBoundary', 'outsideWindow',
            'outputMedia', 'sourceMedia', 'mediaBoundary', 'mediaOutsideWindow',
            'otherTeamExcluded', 'editorOwnershipExcluded',
        ], false);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            DB::table('project_media')->delete();
            AiEditingJob::query()->delete();
            AiListingVideoJob::query()->delete();

            $actor = $iteration % 2 === 0 ? $admin : $editor;
            $jobs = $this->generateJobs($iteration, $projects, $windowStart, $windowEnd);
            $media = $this->generateMedia($iteration, $projects, $windowStart, $windowEnd);

            $expectedCompleted = 0;
            $successfulProjectIds = [];

            foreach ($jobs as $job) {
                $this->persistJob($job);
                $coverage[$job['statusLabel']] = true;
                $coverage[$job['timeLabel']] = true;

                $inWindow = $job['completedAt'] !== null
                    && $job['completedAt']->betweenIncluded($windowStart, $windowEnd);
                $sameTeam = (int) $job['project']->team_id === self::TEAM_A;
                $ownedWhenEditor = $actor->role !== 'editor'
                    || (int) $job['owner']->id === (int) $actor->id;
                $successful = $job['status'] === AiEditingJob::STATUS_COMPLETED;

                if (!$sameTeam && $inWindow && $successful) {
                    $coverage['otherTeamExcluded'] = true;
                }
                if ($sameTeam && $inWindow && $successful && $actor->role === 'editor' && !$ownedWhenEditor) {
                    $coverage['editorOwnershipExcluded'] = true;
                }

                if ($inWindow && $sameTeam && $ownedWhenEditor && $successful) {
                    $expectedCompleted++;
                    $successfulProjectIds[$job['project']->id] = true;
                }
            }

            $expectedOutputs = 0;
            foreach ($media as $record) {
                $this->persistMedia($record);
                $coverage[$record['kindLabel']] = true;
                $coverage[$record['timeLabel']] = true;

                $inWindow = $record['createdAt']->betweenIncluded($windowStart, $windowEnd);
                $sameTeam = (int) $record['project']->team_id === self::TEAM_A;
                $projectHasSuccess = isset($successfulProjectIds[$record['project']->id]);

                if ($inWindow && $sameTeam && $projectHasSuccess && $record['kind'] === 'output') {
                    $expectedOutputs++;
                }
            }

            Sanctum::actingAs($actor);
            $response = $this->getJson(self::URL)->assertOk();
            $actualCompleted = (int) $response->json('data.aiJobsCompleted');
            $actualOutputs = (int) $response->json('data.mediaOutputs');

            $counterexample = sprintf(
                'seed=%d iteration=%d actor=%s:%d jobs=%s media=%s expectedCompleted=%d actualCompleted=%d expectedOutputs=%d actualOutputs=%d',
                self::SEED,
                $iteration,
                $actor->role,
                $actor->id,
                json_encode($this->describeJobs($jobs), JSON_THROW_ON_ERROR),
                json_encode($this->describeMedia($media), JSON_THROW_ON_ERROR),
                $expectedCompleted,
                $actualCompleted,
                $expectedOutputs,
                $actualOutputs
            );

            $this->assertSame($expectedCompleted, $actualCompleted, $counterexample);
            $this->assertSame($expectedOutputs, $actualOutputs, $counterexample);
        }

        foreach ($coverage as $dimension => $seen) {
            $this->assertTrue($seen, "Generator did not cover {$dimension}.");
        }
    }

    /** @param list<Project> $projects @return list<array<string, mixed>> */
    private function generateJobs(int $iteration, array $projects, Carbon $start, Carbon $end): array
    {
        // Forced cases: both tables, every status, both boundaries, out-of-window,
        // other-team and non-owned records.
        if ($iteration === 0) {
            return [
                $this->job('photo', AiEditingJob::STATUS_COMPLETED, $projects[0], 0, $start, $end),
                $this->job('video', AiListingVideoJob::STATUS_COMPLETED, $projects[0], 1, $start, $end),
                $this->job('photo', AiEditingJob::STATUS_FAILED, $projects[1], 4, $start, $end),
                $this->job('video', AiListingVideoJob::STATUS_FAILED, $projects[1], 4, $start, $end),
                $this->job('photo', AiEditingJob::STATUS_CANCELLED, $projects[2], 4, $start, $end),
                $this->job('photo', AiEditingJob::STATUS_COMPLETED, $projects[3], 4, $start, $end),
            ];
        }

        if ($iteration === 1) {
            return [
                $this->job('photo', AiEditingJob::STATUS_COMPLETED, $projects[0], 4, $start, $end),
                $this->job('video', AiListingVideoJob::STATUS_COMPLETED, $projects[2], 4, $start, $end),
                $this->job('photo', AiEditingJob::STATUS_COMPLETED, $projects[3], 4, $start, $end),
                $this->job('photo', AiEditingJob::STATUS_COMPLETED, $projects[1], 2, $start, $end),
                $this->job('video', AiListingVideoJob::STATUS_COMPLETED, $projects[1], 3, $start, $end),
                $this->job('photo', AiEditingJob::STATUS_PROCESSING, $projects[0], 5, $start, $end),
                $this->job('video', AiListingVideoJob::STATUS_COMPLETED, $projects[0], 5, $start, $end),
            ];
        }

        if ($iteration === 2) {
            return [];
        }

        $jobs = [];
        foreach ($projects as $project) {
            $count = mt_rand(0, 3);
            for ($index = 0; $index < $count; $index++) {
                $type = mt_rand(0, 1) === 0 ? 'photo' : 'video';
                $jobs[] = $this->job(
                    $type,
                    $this->randomStatus($type),
                    $project,
                    mt_rand(0, 5),
                    $start,
                    $end
                );
            }
        }

        return $jobs;
    }

    /** @param list<Project> $projects @return list<array<string, mixed>> */
    private function generateMedia(int $iteration, array $projects, Carbon $start, Carbon $end): array
    {
        if ($iteration === 0) {
            return [
                $this->mediaRecord($projects[0], 'output', 0, $start, $end),
                $this->mediaRecord($projects[0], 'output', 1, $start, $end),
                $this->mediaRecord($projects[0], 'source', 4, $start, $end),
                $this->mediaRecord($projects[0], 'output', 2, $start, $end),
                $this->mediaRecord($projects[1], 'output', 4, $start, $end),
                $this->mediaRecord($projects[3], 'output', 4, $start, $end),
            ];
        }

        if ($iteration === 1) {
            return [
                $this->mediaRecord($projects[0], 'output', 4, $start, $end),
                $this->mediaRecord($projects[2], 'output', 4, $start, $end),
                $this->mediaRecord($projects[3], 'output', 4, $start, $end),
                $this->mediaRecord($projects[0], 'output', 3, $start, $end),
                $this->mediaRecord($projects[0], 'source', 1, $start, $end),
            ];
        }

        $media = [];
        foreach ($projects as $project) {
            $count = mt_rand(0, 3);
            for ($index = 0; $index < $count; $index++) {
                $media[] = $this->mediaRecord(
                    $project,
                    mt_rand(0, 1) === 0 ? 'output' : 'source',
                    mt_rand(0, 4),
                    $start,
                    $end
                );
            }
        }

        return $media;
    }

    private function randomStatus(string $type): string
    {
        $statuses = $type === 'photo'
            ? [
                AiEditingJob::STATUS_COMPLETED,
                AiEditingJob::STATUS_FAILED,
                AiEditingJob::STATUS_CANCELLED,
                AiEditingJob::STATUS_PROCESSING,
            ]
            : [
                AiListingVideoJob::STATUS_COMPLETED,
                AiListingVideoJob::STATUS_FAILED,
                AiListingVideoJob::STATUS_CANCELLED,
                AiListingVideoJob::STATUS_QUEUED,
            ];

        return $statuses[mt_rand(0, count($statuses) - 1)];
    }

    /** @return array<string, mixed> */
    private function job(
        string $type,
        string $status,
        Project $project,
        int $timeMode,
        Carbon $start,
        Carbon $end
    ): array {
        $terminal = in_array($status, ['completed', 'failed', 'cancelled'], true);
        if (!$terminal) {
            $timeMode = 5; // Non-terminal jobs never carry a completion timestamp.
        }

        [$createdAt, $completedAt, $timeLabel] = $this->timestamps($timeMode, $start, $end);

        return [
            'type' => $type,
            'status' => $status,
            'statusLabel' => $this->statusLabel($type, $status),
            'project' => $project,
            'owner' => $project->createdBy,
            'createdAt' => $createdAt,
            'completedAt' => $completedAt,
            'timeLabel' => $timeLabel,
        ];
    }

    private function statusLabel(string $type, string $status): string
    {
        return match ($status) {
            'completed' => $type === 'photo' ? 'photoCompleted' : 'videoCompleted',
            'failed' => $type === 'photo' ? 'photoFailed' : 'videoFailed',
            'cancelled' => 'cancelled',
            default => 'nonTerminal',
        };
    }

    /** @return array{Carbon, ?Carbon, string} */
    private function timestamps(int $mode, Carbon $start, Carbon $end): array
    {
        $inside = $start->copy()->addSeconds(mt_rand(1, 30 * 24 * 60 * 60 - 1));

        return match ($mode) {
            0 => [$start->copy(), $start->copy(), 'windowStartBoundary'],
            1 => [$end->copy(), $end->copy(), 'windowEndBoundary'],
            2 => [$start->copy()->subSecond(), $start->copy()->subSecond(), 'outsideWindow'],
            3 => [$end->copy()->addSecond(), $end->copy()->addSecond(), 'outsideWindow'],
            5 => [$inside->copy(), null, 'nullCompletion'],
            default => [$inside->copy(), $inside->copy(), 'insideWindow'],
        };
    }

    /** @return array<string, mixed> */
    private function mediaRecord(Project $project, string $kind, int $timeMode, Carbon $start, Carbon $end): array
    {
        [$createdAt, $timeLabel] = $this->mediaTimestamp($timeMode, $start, $end);

        return [
            'project' => $project,
            'owner' => $project->createdBy,
            'kind' => $kind,
            'kindLabel' => $kind === 'output' ? 'outputMedia' : 'sourceMedia',
            'createdAt' => $createdAt,
            'timeLabel' => $timeLabel,
        ];
    }

    /** @return array{Carbon, string} */
    private function mediaTimestamp(int $mode, Carbon $start, Carbon $end): array
    {
        return match ($mode) {
            0 => [$start->copy(), 'mediaBoundary'],
            1 => [$end->copy(), 'mediaBoundary'],
            2 => [$start->copy()->subSecond(), 'mediaOutsideWindow'],
            3 => [$end->copy()->addSecond(), 'mediaOutsideWindow'],
            default => [$start->copy()->addSeconds(mt_rand(1, 30 * 24 * 60 * 60 - 1)), 'mediaInsideWindow'],
        };
    }

    /** @param array<string, mixed> $job */
    private function persistJob(array $job): void
    {
        $timestamps = [
            'created_at' => $job['createdAt'],
            'updated_at' => $job['createdAt'],
            'completed_at' => $job['completedAt'],
        ];

        if ($job['type'] === 'photo') {
            DB::table('ai_editing_jobs')->insert($timestamps + [
                'project_id' => $job['project']->id,
                'shoot_id' => $job['project']->shoot_id,
                'user_id' => $job['owner']->id,
                'status' => $job['status'],
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
            'status' => $job['status'],
        ]);
    }

    /** @param array<string, mixed> $record */
    private function persistMedia(array $record): void
    {
        DB::table('project_media')->insert([
            'project_id' => $record['project']->id,
            'team_id' => $record['project']->team_id,
            'created_by' => $record['owner']->id,
            'media_ref' => 'studio/' . $record['kind'] . '-' . uniqid('', true) . '.jpg',
            'kind' => $record['kind'],
            'version' => 1,
            'created_at' => $record['createdAt'],
            'updated_at' => $record['createdAt'],
        ]);
    }

    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private function describeJobs(array $jobs): array
    {
        return array_map(static fn (array $job): array => [
            'type' => $job['type'],
            'status' => $job['status'],
            'project' => $job['project']->id,
            'team' => (int) $job['project']->team_id,
            'owner' => (int) $job['owner']->id,
            'createdAt' => $job['createdAt']->toIso8601String(),
            'completedAt' => $job['completedAt']?->toIso8601String(),
        ], $jobs);
    }

    /** @param list<array<string, mixed>> $media @return list<array<string, mixed>> */
    private function describeMedia(array $media): array
    {
        return array_map(static fn (array $record): array => [
            'kind' => $record['kind'],
            'project' => $record['project']->id,
            'team' => (int) $record['project']->team_id,
            'owner' => (int) $record['owner']->id,
            'createdAt' => $record['createdAt']->toIso8601String(),
        ], $media);
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
