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
 * Feature: ai-editing-studio-revamp, Property 25: Success Rate formula and bounds.
 *
 * For every generated photo/video job set, the reported Success Rate must equal
 * completed / (completed + failed) over jobs that entered a terminal state inside
 * the 30-day window, must stay within 0..100, and must be exactly 0 when the
 * denominator is 0. Cancelled jobs are excluded from the denominator.
 *
 * **Validates: Requirements 8.4, 8.5**
 *
 * PHPUnit has no PBT library configured, so a fixed seed drives 24 reproducible
 * datasets (9 forced cases plus 15 randomised ones). Forced cases guarantee
 * all-success, all-failure, mixed, cancelled-only, empty, boundary, out-of-window,
 * out-of-scope, and non-terminal datasets rather than leaving them to chance.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('ai-editing-studio-revamp')]
class StudioSuccessRatePropertyTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/metrics/summary';
    private const ITERATIONS = 24;
    private const SEED = 20260731;
    private const TEAM_A = 61;
    private const TEAM_B = 77;

    /** @var list<string> */
    private const STATUSES = [
        AiEditingJob::STATUS_COMPLETED,
        AiEditingJob::STATUS_FAILED,
        AiEditingJob::STATUS_CANCELLED,
        AiEditingJob::STATUS_PROCESSING,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_property_25_success_rate_matches_formula_and_stays_in_bounds(): void
    {
        Carbon::setTestNow('2026-07-31T09:00:00+00:00');
        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subDays(30);

        $admin = $this->user('admin', self::TEAM_A);
        $owner = $this->user('editor', self::TEAM_A);
        $outsider = $this->user('editor', self::TEAM_B);

        $inScopeProject = $this->project($owner, self::TEAM_A, 'team-a');
        $outOfScopeProject = $this->project($outsider, self::TEAM_B, 'team-b');

        mt_srand(self::SEED);
        $coverage = array_fill_keys([
            'photo', 'video', 'allSuccess', 'allFailure', 'mixed', 'cancelledOnly',
            'empty', 'zeroDenominator', 'windowStart', 'windowEnd', 'outsideWindow',
            'noCompletedAt', 'nonTerminalStatus', 'outOfScope', 'fractionalRate',
        ], false);

        Sanctum::actingAs($admin);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            AiEditingJob::query()->delete();
            AiListingVideoJob::query()->delete();

            $jobs = $this->generateJobs($iteration, $inScopeProject, $outOfScopeProject, $windowStart, $windowEnd);
            $completed = 0;
            $failed = 0;
            $cancelledOnly = $jobs !== [];

            foreach ($jobs as $job) {
                $this->persistJob($job);
                $coverage[$job['type']] = true;
                $coverage[$job['timeLabel']] = true;

                if ($job['status'] === AiEditingJob::STATUS_PROCESSING) {
                    $coverage['nonTerminalStatus'] = true;
                }
                if ((int) $job['project']->team_id !== self::TEAM_A) {
                    $coverage['outOfScope'] = true;
                    $cancelledOnly = false;
                    continue;
                }
                if ($job['completedAt'] === null || !$job['completedAt']->betweenIncluded($windowStart, $windowEnd)) {
                    $cancelledOnly = false;
                    continue;
                }
                if ($job['status'] === AiEditingJob::STATUS_COMPLETED) {
                    $completed++;
                    $cancelledOnly = false;
                } elseif ($job['status'] === AiEditingJob::STATUS_FAILED) {
                    $failed++;
                    $cancelledOnly = false;
                } elseif ($job['status'] !== AiEditingJob::STATUS_CANCELLED) {
                    $cancelledOnly = false;
                }
            }

            $denominator = $completed + $failed;
            $expected = $denominator > 0 ? round($completed / $denominator * 100, 1) : 0.0;

            if ($jobs === []) {
                $coverage['empty'] = true;
            }
            if ($cancelledOnly) {
                $coverage['cancelledOnly'] = true;
            }
            if ($denominator === 0) {
                $coverage['zeroDenominator'] = true;
            } elseif ($completed === $denominator) {
                $coverage['allSuccess'] = true;
            } elseif ($completed === 0) {
                $coverage['allFailure'] = true;
            } else {
                $coverage['mixed'] = true;
            }
            if ($expected !== round($expected)) {
                $coverage['fractionalRate'] = true;
            }

            $raw = $this->getJson(self::URL)->assertOk()->json('data.successRate');
            $actual = (float) $raw;
            $counterexample = sprintf(
                'seed=%d iteration=%d completed=%d failed=%d expected=%s actual=%s dataset=%s',
                self::SEED,
                $iteration,
                $completed,
                $failed,
                var_export($expected, true),
                var_export($raw, true),
                json_encode($this->describeJobs($jobs), JSON_THROW_ON_ERROR)
            );

            // Formula: completed / (completed + failed) as implemented (percentage, 1dp).
            $this->assertEqualsWithDelta($expected, $actual, 0.0001, $counterexample);
            // Bounds: always within 0..100.
            $this->assertGreaterThanOrEqual(0.0, $actual, $counterexample);
            $this->assertLessThanOrEqual(100.0, $actual, $counterexample);
            // Zero denominator yields exactly zero.
            if ($denominator === 0) {
                $this->assertSame(0.0, $actual, $counterexample);
            }
        }

        foreach ($coverage as $dimension => $seen) {
            $this->assertTrue($seen, "Generator did not cover {$dimension}.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function generateJobs(
        int $iteration,
        Project $inScope,
        Project $outOfScope,
        Carbon $start,
        Carbon $end
    ): array {
        return match ($iteration) {
            // Empty dataset -> zero denominator.
            0 => [],
            // All success across both job types.
            1 => [
                $this->job('photo', $inScope, AiEditingJob::STATUS_COMPLETED, 4, $start, $end),
                $this->job('video', $inScope, AiEditingJob::STATUS_COMPLETED, 4, $start, $end),
                $this->job('photo', $inScope, AiEditingJob::STATUS_COMPLETED, 0, $start, $end),
            ],
            // All failure across both job types.
            2 => [
                $this->job('photo', $inScope, AiEditingJob::STATUS_FAILED, 4, $start, $end),
                $this->job('video', $inScope, AiEditingJob::STATUS_FAILED, 1, $start, $end),
            ],
            // Cancelled only -> excluded from denominator -> zero.
            3 => [
                $this->job('photo', $inScope, AiEditingJob::STATUS_CANCELLED, 4, $start, $end),
                $this->job('video', $inScope, AiEditingJob::STATUS_CANCELLED, 4, $start, $end),
            ],
            // Mixed with a fractional rate (1/3 -> 33.3) plus an ignored cancellation.
            4 => [
                $this->job('photo', $inScope, AiEditingJob::STATUS_COMPLETED, 4, $start, $end),
                $this->job('video', $inScope, AiEditingJob::STATUS_FAILED, 4, $start, $end),
                $this->job('photo', $inScope, AiEditingJob::STATUS_FAILED, 4, $start, $end),
                $this->job('video', $inScope, AiEditingJob::STATUS_CANCELLED, 4, $start, $end),
            ],
            // Inclusive window boundaries.
            5 => [
                $this->job('photo', $inScope, AiEditingJob::STATUS_COMPLETED, 0, $start, $end),
                $this->job('video', $inScope, AiEditingJob::STATUS_FAILED, 1, $start, $end),
            ],
            // Terminal states outside the window -> zero denominator.
            6 => [
                $this->job('photo', $inScope, AiEditingJob::STATUS_COMPLETED, 2, $start, $end),
                $this->job('video', $inScope, AiEditingJob::STATUS_FAILED, 3, $start, $end),
            ],
            // Out-of-scope team jobs must not influence the rate.
            7 => [
                $this->job('photo', $outOfScope, AiEditingJob::STATUS_COMPLETED, 4, $start, $end),
                $this->job('video', $outOfScope, AiEditingJob::STATUS_FAILED, 4, $start, $end),
                $this->job('photo', $inScope, AiEditingJob::STATUS_COMPLETED, 4, $start, $end),
            ],
            // Non-terminal jobs and jobs without completed_at are ignored entirely.
            8 => [
                $this->job('photo', $inScope, AiEditingJob::STATUS_PROCESSING, 4, $start, $end),
                $this->job('video', $inScope, AiEditingJob::STATUS_COMPLETED, 5, $start, $end),
                $this->job('photo', $inScope, AiEditingJob::STATUS_FAILED, 5, $start, $end),
            ],
            default => $this->randomJobs($inScope, $outOfScope, $start, $end),
        };
    }

    /** @return list<array<string, mixed>> */
    private function randomJobs(Project $inScope, Project $outOfScope, Carbon $start, Carbon $end): array
    {
        $jobs = [];
        $count = mt_rand(0, 8);

        for ($index = 0; $index < $count; $index++) {
            $jobs[] = $this->job(
                mt_rand(0, 1) === 0 ? 'photo' : 'video',
                mt_rand(0, 9) === 0 ? $outOfScope : $inScope,
                self::STATUSES[mt_rand(0, count(self::STATUSES) - 1)],
                mt_rand(0, 5),
                $start,
                $end
            );
        }

        return $jobs;
    }

    /** @return array<string, mixed> */
    private function job(
        string $type,
        Project $project,
        string $status,
        int $timeMode,
        Carbon $start,
        Carbon $end
    ): array {
        [$completedAt, $label] = $this->completionTimestamp($timeMode, $start, $end);
        $activity = $completedAt ?? $start->copy()->addDay();

        return [
            'type' => $type,
            'project' => $project,
            'owner' => $project->createdBy,
            'status' => $status,
            'completedAt' => $completedAt,
            'activityAt' => $activity,
            'timeLabel' => $label,
        ];
    }

    /** @return array{?Carbon, string} */
    private function completionTimestamp(int $mode, Carbon $start, Carbon $end): array
    {
        $inside = $start->copy()->addSeconds(mt_rand(1, 30 * 24 * 60 * 60 - 1));

        return match ($mode) {
            0 => [$start->copy(), 'windowStart'],
            1 => [$end->copy(), 'windowEnd'],
            2 => [$start->copy()->subSecond(), 'outsideWindow'],
            3 => [$end->copy()->addSecond(), 'outsideWindow'],
            5 => [null, 'noCompletedAt'],
            default => [$inside, 'insideWindow'],
        };
    }

    /** @param array<string, mixed> $job */
    private function persistJob(array $job): void
    {
        $timestamps = [
            'created_at' => $job['activityAt'],
            'updated_at' => $job['activityAt'],
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

    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private function describeJobs(array $jobs): array
    {
        return array_map(static fn (array $job): array => [
            'type' => $job['type'],
            'team' => $job['project']->team_id,
            'status' => $job['status'],
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
