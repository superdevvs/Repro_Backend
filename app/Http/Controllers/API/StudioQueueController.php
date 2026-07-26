<?php

namespace App\Http\Controllers\API;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StudioQueueController extends StudioController
{
    private const TERMINAL_RETENTION_HOURS = 24;

    private const PHOTO_ACTIVE_STATUSES = [
        AiEditingJob::STATUS_PENDING,
        AiEditingJob::STATUS_PROCESSING,
    ];

    private const VIDEO_ACTIVE_STATUSES = [
        AiListingVideoJob::STATUS_QUEUED,
        AiListingVideoJob::STATUS_PROCESSING,
        AiListingVideoJob::STATUS_STITCHING,
    ];

    private const TERMINAL_STATUSES = [
        AiEditingJob::STATUS_COMPLETED,
        AiEditingJob::STATUS_FAILED,
        AiEditingJob::STATUS_CANCELLED,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'view');

        $calculatedAt = now();
        $retentionCutoff = $calculatedAt->copy()->subHours(self::TERMINAL_RETENTION_HOURS);

        $photoJobs = $this->queueQuery(AiEditingJob::class, $user)
            ->where(fn (Builder $query) => $this->applyMembershipScope(
                $query,
                self::PHOTO_ACTIVE_STATUSES,
                $retentionCutoff
            ))
            ->get();

        $videoJobs = $this->queueQuery(AiListingVideoJob::class, $user)
            ->where(fn (Builder $query) => $this->applyMembershipScope(
                $query,
                self::VIDEO_ACTIVE_STATUSES,
                $retentionCutoff
            ))
            ->get();

        $data = $photoJobs->map(fn (AiEditingJob $job) => $this->presentJob($job, 'photo', $calculatedAt))
            ->concat($videoJobs->map(fn (AiListingVideoJob $job) => $this->presentJob($job, 'video', $calculatedAt)))
            ->sortByDesc('sortTimestamp')
            ->map(function (array $record): array {
                unset($record['sortTimestamp']);

                return $record;
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'retentionHours' => self::TERMINAL_RETENTION_HOURS,
                'calculatedAt' => $calculatedAt->toISOString(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'view');

        [$jobType, $jobId] = $this->parseQueueId($id);
        $modelClass = $jobType === 'photo' ? AiEditingJob::class : AiListingVideoJob::class;
        $job = $this->baseJobQuery($modelClass)->find($jobId);

        if ($job === null) {
            abort(404, 'Queue record not found.');
        }

        if (!$this->jobIsInScope($job, $user)) {
            throw new AuthorizationException('This action is not authorized.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentJob($job, $jobType, now(), false),
        ]);
    }

    private function queueQuery(string $modelClass, Authenticatable $user): Builder
    {
        $query = $this->baseJobQuery($modelClass);

        if (($userId = $this->scopeUserId($user)) !== null) {
            return $query->where('user_id', $userId);
        }

        $teamId = $this->scopeTeamId($user);

        return $query->where(function (Builder $scope) use ($teamId): void {
            $scope->whereHas('project', fn (Builder $project) => $project->where('team_id', $teamId))
                ->orWhere(function (Builder $legacyJob) use ($teamId): void {
                    $legacyJob->whereNull('project_id')
                        ->whereHas('user', function (Builder $owner) use ($teamId): void {
                            $owner->where(function (Builder $teamMember) use ($teamId): void {
                                $teamMember->whereKey($teamId)
                                    ->orWhere('metadata->team_id', $teamId);
                            });
                        });
                });
        });
    }

    private function baseJobQuery(string $modelClass): Builder
    {
        return $modelClass::query()->with([
            'project:id,team_id,created_by,shoot_id,name,address,workflow_id',
            'project.media' => fn ($query) => $query
                ->where('kind', 'source')
                ->orderBy('id'),
            'shoot:id,address,hero_image',
            'user:id,metadata',
        ]);
    }

    private function applyMembershipScope(Builder $query, array $activeStatuses, Carbon $cutoff): void
    {
        $query->whereIn('status', $activeStatuses)
            ->orWhere(function (Builder $terminal) use ($cutoff): void {
                $terminal->whereIn('status', self::TERMINAL_STATUSES)
                    ->where(function (Builder $recent) use ($cutoff): void {
                        $recent->where('completed_at', '>=', $cutoff)
                            ->orWhere(function (Builder $fallback) use ($cutoff): void {
                                $fallback->whereNull('completed_at')
                                    ->where('updated_at', '>=', $cutoff);
                            });
                    });
            });
    }

    private function jobIsInScope(Model $job, Authenticatable $user): bool
    {
        if (($userId = $this->scopeUserId($user)) !== null) {
            return (int) $job->getAttribute('user_id') === $userId;
        }

        $project = $job->getRelation('project');
        if ($project !== null) {
            return (int) $project->team_id === $this->scopeTeamId($user);
        }

        $owner = $job->getRelation('user');

        return $owner !== null && $this->scopeTeamId($owner) === $this->scopeTeamId($user);
    }

    private function parseQueueId(string $id): array
    {
        if (!preg_match('/^(photo|video)-([1-9][0-9]*)$/', $id, $matches)) {
            abort(404, 'Queue record not found.');
        }

        return [$matches[1], (int) $matches[2]];
    }

    private function presentJob(Model $job, string $jobType, Carbon $calculatedAt, bool $withSort = true): array
    {
        $progress = $jobType === 'photo'
            ? $this->photoProgress($job)
            : $this->videoProgress($job);
        $context = $this->jobContext($job);
        $terminalAt = $this->terminalAt($job);
        $record = [
            'id' => $jobType . '-' . $job->getKey(),
            'aiJobId' => (string) $job->getKey(),
            'jobType' => $jobType,
            'workflowTitle' => $this->workflowTitle($job, $jobType),
            'context' => $context,
            'contextLabel' => $context['label'] ?? null,
            'thumbnailUrl' => $this->thumbnailUrl($job, $jobType),
            'status' => (string) $job->status,
            'progress' => $progress,
            'eta' => $this->eta($job, $progress, $calculatedAt),
            'failureReason' => $job->status === AiEditingJob::STATUS_FAILED
                ? ($job->error_message ?: null)
                : null,
            'terminalAt' => $terminalAt?->toISOString(),
            'version' => ($job->updated_at ?? $job->created_at)?->toISOString(),
            'deepLink' => [
                'destination' => 'queue',
                'recordType' => 'ai_job',
                'recordId' => $jobType . '-' . $job->getKey(),
            ],
        ];

        if ($withSort) {
            $record['sortTimestamp'] = ($job->updated_at ?? $job->created_at)?->getTimestamp() ?? 0;
        }

        return $record;
    }

    private function jobContext(Model $job): ?array
    {
        if ($job->project !== null) {
            return [
                'type' => 'project',
                'id' => (string) $job->project->id,
                'label' => $job->project->name ?: $job->project->address,
            ];
        }

        if ($job->shoot !== null) {
            return [
                'type' => 'shoot',
                'id' => (string) $job->shoot->id,
                'label' => $job->shoot->address,
            ];
        }

        return null;
    }

    private function workflowTitle(Model $job, string $jobType): string
    {
        $workflow = $job->project?->workflow_id;
        $workflow ??= $jobType === 'photo' ? $job->editing_type : 'listing-video';

        return match ((string) $workflow) {
            'enhance', 'photo-enhancement' => 'Photo Enhancement',
            'sky_replace', 'twilight' => 'Twilight',
            'video-cleanup' => 'Video Cleanup',
            'listing-video' => 'Listing Video',
            'reel-generator' => 'Reel Generator',
            'batch-ai-jobs' => 'Batch AI Jobs',
            default => str((string) $workflow)->replace(['_', '-'], ' ')->title()->toString(),
        };
    }

    private function thumbnailUrl(Model $job, string $jobType): ?string
    {
        if ($jobType === 'photo' && filled($job->original_image_url)) {
            return (string) $job->original_image_url;
        }

        if (filled($job->shoot?->hero_image)) {
            return (string) $job->shoot->hero_image;
        }

        return $job->project?->media?->first()?->media_ref;
    }

    private function photoProgress(Model $job): ?int
    {
        if ($job->status === AiEditingJob::STATUS_COMPLETED) {
            return 100;
        }

        foreach ([$job->provider_result, $job->provider_payload] as $metadata) {
            $progress = $this->findProgress(is_array($metadata) ? $metadata : []);
            if ($progress !== null) {
                return $this->clampProgress($progress);
            }
        }

        return null;
    }

    private function findProgress(array $metadata): int|float|null
    {
        foreach (['progress', 'progress_percent', 'progressPercentage', 'percentage'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                return (float) $metadata[$key];
            }
        }

        foreach (['data', 'result', 'status', 'job'] as $key) {
            if (isset($metadata[$key]) && is_array($metadata[$key])) {
                $progress = $this->findProgress($metadata[$key]);
                if ($progress !== null) {
                    return $progress;
                }
            }
        }

        return null;
    }

    private function videoProgress(Model $job): ?int
    {
        if ($job->status === AiListingVideoJob::STATUS_COMPLETED) {
            return 100;
        }

        $total = (int) $job->total_clips;
        if ($total < 1) {
            return null;
        }

        return $this->clampProgress(((int) $job->completed_clips / $total) * 100);
    }

    private function clampProgress(int|float $progress): int
    {
        return (int) round(max(0, min(100, $progress)));
    }

    private function eta(Model $job, ?int $progress, Carbon $calculatedAt): ?array
    {
        if (in_array($job->status, self::TERMINAL_STATUSES, true)
            || $progress === null
            || $progress <= 0
            || $progress >= 100
            || $job->started_at === null) {
            return null;
        }

        $elapsedSeconds = (int) floor($job->started_at->diffInSeconds($calculatedAt, false));
        if ($elapsedSeconds <= 0) {
            return null;
        }

        return [
            'estimateSeconds' => (int) ceil($elapsedSeconds * (100 - $progress) / $progress),
            'calculatedAt' => $calculatedAt->toISOString(),
        ];
    }

    private function terminalAt(Model $job): ?Carbon
    {
        if (!in_array($job->status, self::TERMINAL_STATUSES, true)) {
            return null;
        }

        return $job->completed_at ?? $job->updated_at;
    }
}
