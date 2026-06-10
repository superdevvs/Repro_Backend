<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * StudioMetricsController
 *
 * Exposes aggregated, read-only studio metrics that combine photo editing jobs
 * (AiEditingJob) and listing video jobs (AiListingVideoJob) into a single view
 * for the AI Real Estate Media Studio landing page (hero stats, recent projects,
 * and the active job queue).
 *
 * Access control is enforced by the `role:admin,superadmin,editing_manager,editor`
 * route middleware (see api routes). Within that allowed set, privileged roles
 * (admin/superadmin/editing_manager) see all jobs while editors are scoped to
 * their own jobs, mirroring ListingVideoController.
 *
 * Endpoint methods (hero/recentProjects/activeQueue) are implemented in
 * subsequent tasks (1.2-1.4); this class provides the shared scoping and
 * status-bucket helpers they build on.
 */
class StudioMetricsController extends Controller
{
    /**
     * Roles privileged to see every job regardless of ownership.
     */
    private const PRIVILEGED_ROLES = ['admin', 'superadmin', 'editing_manager'];

    /**
     * Photo (AiEditingJob) statuses that count as active / non-terminal.
     *
     * @var string[]
     */
    private const PHOTO_ACTIVE_STATUSES = [
        AiEditingJob::STATUS_PENDING,
        AiEditingJob::STATUS_PROCESSING,
    ];

    /**
     * Video (AiListingVideoJob) statuses that count as active / non-terminal.
     *
     * @var string[]
     */
    private const VIDEO_ACTIVE_STATUSES = [
        AiListingVideoJob::STATUS_QUEUED,
        AiListingVideoJob::STATUS_PROCESSING,
        AiListingVideoJob::STATUS_STITCHING,
    ];

    /**
     * GET /studio/metrics/hero
     *
     * Returns the Hero_Stats for the Studio Landing, aggregated across both the
     * photo (AiEditingJob) and listing video (AiListingVideoJob) tables and
     * counting each job exactly once:
     *
     *  - projects_count    distinct shoot_id across both tables (a shoot with
     *                      both photo and video jobs counts once)
     *  - ai_jobs_completed total completed jobs across both tables
     *  - success_rate      completed / (completed + failed) * 100, rounded;
     *                      0 when the (completed + failed) denominator is 0
     *
     * Privileged roles see all jobs; editors are scoped to their own user_id.
     */
    public function hero(Request $request): JsonResponse
    {
        $scope = $this->scopeUserId($request->user()); // null = all, or user_id

        $photo = AiEditingJob::query()->when($scope, fn ($q) => $q->where('user_id', $scope));
        $video = AiListingVideoJob::query()->when($scope, fn ($q) => $q->where('user_id', $scope));

        $completed = (clone $photo)->where('status', AiEditingJob::STATUS_COMPLETED)->count()
                   + (clone $video)->where('status', AiListingVideoJob::STATUS_COMPLETED)->count();

        $failed = (clone $photo)->where('status', AiEditingJob::STATUS_FAILED)->count()
                + (clone $video)->where('status', AiListingVideoJob::STATUS_FAILED)->count();

        $terminal = $completed + $failed;
        $successRate = $terminal > 0 ? round($completed / $terminal * 100, 1) : 0; // Req 3.4 / 3.5

        // Distinct shoots that have at least one job in either table (count-once dedupe).
        $projectsCount = (clone $photo)->distinct()->pluck('shoot_id')
            ->merge((clone $video)->distinct()->pluck('shoot_id'))
            ->unique()
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'projects_count'    => $projectsCount,
                'ai_jobs_completed' => $completed,
                'success_rate'      => $successRate,
            ],
        ]);
    }

    /**
     * Default number of recent projects returned when no limit is supplied.
     */
    private const DEFAULT_RECENT_LIMIT = 8;

    /**
     * GET /studio/metrics/recent-projects
     *
     * Returns the most recently active projects for the Studio Landing, one
     * entry per shoot, ordered by most recent activity first. Recent jobs are
     * pulled from both the photo (AiEditingJob) and listing video
     * (AiListingVideoJob) tables, each tagged with its job_type, status, and
     * activity timestamp.
     *
     * Jobs are grouped by shoot_id; for each shoot the single most-recent job
     * supplies the project's latest_status, latest_job_type, and
     * last_activity_at. This guarantees each job contributes to at most one
     * project entry (count-once dedupe, Req 9.4) and each shoot appears at most
     * once (Req 5.2). Results are sorted by last_activity_at descending, joined
     * to Shoot for the address, and capped at the requested limit (default 8).
     *
     * Privileged roles see all jobs; editors are scoped to their own user_id.
     *
     * Query params:
     *  - limit  optional positive integer cap on returned projects (default 8)
     *
     * Each item: { shoot_id, address, last_activity_at, latest_status, latest_job_type }
     */
    public function recentProjects(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', self::DEFAULT_RECENT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_RECENT_LIMIT;
        }

        $scope = $this->scopeUserId($request->user()); // null = all, or user_id

        $photoJobs = AiEditingJob::query()
            ->when($scope, fn ($q) => $q->where('user_id', $scope))
            ->whereNotNull('shoot_id')
            ->get(['shoot_id', 'status', 'updated_at', 'created_at'])
            ->map(fn ($job) => [
                'shoot_id' => (int) $job->shoot_id,
                'status'   => (string) $job->status,
                'job_type' => 'photo',
                'activity' => $job->updated_at ?? $job->created_at, // Carbon|null
            ]);

        $videoJobs = AiListingVideoJob::query()
            ->when($scope, fn ($q) => $q->where('user_id', $scope))
            ->whereNotNull('shoot_id')
            ->get(['shoot_id', 'status', 'updated_at', 'created_at'])
            ->map(fn ($job) => [
                'shoot_id' => (int) $job->shoot_id,
                'status'   => (string) $job->status,
                'job_type' => 'video',
                'activity' => $job->updated_at ?? $job->created_at, // Carbon|null
            ]);

        // Group by shoot_id and keep the single most-recent job per shoot.
        $projects = $photoJobs->concat($videoJobs)
            ->groupBy('shoot_id')
            ->map(function ($jobs) {
                $latest = $jobs
                    ->sortByDesc(fn ($job) => $job['activity'] ? $job['activity']->getTimestamp() : 0)
                    ->first();

                return [
                    'shoot_id'        => $latest['shoot_id'],
                    'latest_status'   => $latest['status'],
                    'latest_job_type' => $latest['job_type'],
                    'activity'        => $latest['activity'],
                    'sort_key'        => $latest['activity'] ? $latest['activity']->getTimestamp() : 0,
                ];
            })
            ->sortByDesc('sort_key')
            ->take($limit)
            ->values();

        // Join Shoot for the address in a single query.
        $addresses = Shoot::query()
            ->whereIn('id', $projects->pluck('shoot_id')->all())
            ->pluck('address', 'id');

        $data = $projects->map(fn ($project) => [
            'shoot_id'         => $project['shoot_id'],
            'address'          => $addresses[$project['shoot_id']] ?? null,
            'last_activity_at' => $project['activity'] ? $project['activity']->toIso8601String() : null,
            'latest_status'    => $project['latest_status'],
            'latest_job_type'  => $project['latest_job_type'],
        ])->values();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /studio/metrics/active-queue
     *
     * Returns every Active_Job (non-terminal status) drawn from both the photo
     * (AiEditingJob) and listing video (AiListingVideoJob) tables for the
     * Studio Landing's AI Queue Status panel.
     *
     * Active statuses:
     *  - photo: pending, processing
     *  - video: queued, processing, stitching
     *
     * Each row is presented uniformly as
     * { id, job_type, shoot_id, shoot_address, status }. The numeric primary
     * keys of the two tables can collide, so each id is namespaced by job_type
     * (e.g. "photo-12", "video-12") to guarantee a globally unique id and that
     * each job appears exactly once (Req 9.4). The shoot address is joined from
     * the Shoot table.
     *
     * Privileged roles see all jobs; editors are scoped to their own user_id.
     *
     * Each item: { id, job_type, shoot_id, shoot_address, status }
     */
    public function activeQueue(Request $request): JsonResponse
    {
        $scope = $this->scopeUserId($request->user()); // null = all, or user_id

        $photoJobs = AiEditingJob::query()
            ->when($scope, fn ($q) => $q->where('user_id', $scope))
            ->whereIn('status', $this->photoActiveStatuses())
            ->get(['id', 'shoot_id', 'status'])
            ->map(fn ($job) => [
                'id'        => 'photo-' . $job->id,
                'job_type'  => 'photo',
                'shoot_id'  => $job->shoot_id !== null ? (int) $job->shoot_id : null,
                'status'    => (string) $job->status,
            ]);

        $videoJobs = AiListingVideoJob::query()
            ->when($scope, fn ($q) => $q->where('user_id', $scope))
            ->whereIn('status', $this->videoActiveStatuses())
            ->get(['id', 'shoot_id', 'status'])
            ->map(fn ($job) => [
                'id'        => 'video-' . $job->id,
                'job_type'  => 'video',
                'shoot_id'  => $job->shoot_id !== null ? (int) $job->shoot_id : null,
                'status'    => (string) $job->status,
            ]);

        $jobs = $photoJobs->concat($videoJobs);

        // Join Shoot for the address in a single query.
        $addresses = Shoot::query()
            ->whereIn('id', $jobs->pluck('shoot_id')->filter()->unique()->all())
            ->pluck('address', 'id');

        $data = $jobs->map(fn ($job) => [
            'id'            => $job['id'],
            'job_type'      => $job['job_type'],
            'shoot_id'      => $job['shoot_id'],
            'shoot_address' => $job['shoot_id'] !== null ? ($addresses[$job['shoot_id']] ?? null) : null,
            'status'        => $job['status'],
        ])->values();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Resolve the user_id scope for aggregation queries.
     *
     * Returns null for privileged roles (admin/superadmin/editing_manager) so
     * that all jobs are aggregated, and the user's own id for editors so the
     * aggregation is self-scoped. Mirrors the scoping in ListingVideoController.
     *
     * @param  \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return int|null  null = all jobs (no user_id filter); otherwise the user id to scope by
     */
    protected function scopeUserId($user): ?int
    {
        if (! $user) {
            return null;
        }

        if (in_array($user->role, self::PRIVILEGED_ROLES, true)) {
            return null;
        }

        return (int) $user->id;
    }

    /**
     * Whether the given photo or video job status is terminal-success.
     */
    protected function isTerminalSuccess(string $status): bool
    {
        return $status === AiEditingJob::STATUS_COMPLETED;
    }

    /**
     * Whether the given photo or video job status is terminal-failure.
     */
    protected function isTerminalFailure(string $status): bool
    {
        return $status === AiEditingJob::STATUS_FAILED;
    }

    /**
     * Whether the given status is excluded from the success-rate denominator.
     *
     * `cancelled` is neither success nor failure, so it is excluded from the
     * terminal set used to compute Success_Rate.
     */
    protected function isExcludedFromSuccessRate(string $status): bool
    {
        return $status === AiEditingJob::STATUS_CANCELLED;
    }

    /**
     * Whether the given photo (AiEditingJob) status is active / non-terminal.
     */
    protected function isPhotoActiveStatus(string $status): bool
    {
        return in_array($status, self::PHOTO_ACTIVE_STATUSES, true);
    }

    /**
     * Whether the given video (AiListingVideoJob) status is active / non-terminal.
     */
    protected function isVideoActiveStatus(string $status): bool
    {
        return in_array($status, self::VIDEO_ACTIVE_STATUSES, true);
    }

    /**
     * Active (non-terminal) photo status values.
     *
     * @return string[]
     */
    protected function photoActiveStatuses(): array
    {
        return self::PHOTO_ACTIVE_STATUSES;
    }

    /**
     * Active (non-terminal) video status values.
     *
     * @return string[]
     */
    protected function videoActiveStatuses(): array
    {
        return self::VIDEO_ACTIVE_STATUSES;
    }
}
