<?php

namespace App\Http\Controllers\API;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Services\Studio\StudioProjectSubmissionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StudioProjectController extends StudioController
{
    private const WORKFLOW_TITLES = [
        'photo-enhancement' => 'Photo Enhancement',
        'twilight' => 'Twilight',
        'video-cleanup' => 'Video Cleanup',
        'listing-video' => 'Listing Video',
        'reel-generator' => 'Reel Generator',
        'batch-ai-jobs' => 'Batch AI Jobs',
    ];

    public function __construct(private StudioProjectSubmissionService $submissions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'view');

        $projects = $this->projectQuery($user)->get()
            ->map(fn (Project $project): array => $this->presentProject($project))
            ->sort(function (array $left, array $right): int {
                $activityOrder = strcmp($right['_activitySort'], $left['_activitySort']);

                return $activityOrder !== 0
                    ? $activityOrder
                    : strcmp((string) $left['id'], (string) $right['id']);
            })
            ->values()
            ->map(fn (array $project): array => $this->withoutSortField($project));

        return response()->json([
            'success' => true,
            'data' => $projects,
            'meta' => ['count' => $projects->count()],
        ]);
    }

    public function show(Request $request, string $project): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'view');

        $record = $this->baseProjectQuery()->find($project);
        if ($record === null) {
            abort(404, 'Project not found.');
        }

        $this->authorizeStudioAction($user, 'view', $record);

        return response()->json([
            'success' => true,
            'data' => $this->withoutSortField($this->presentProject($record, true)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'create');

        return $this->submissions->submit(
            $request,
            $this->scopeTeamId($user),
            $this->scopeUserId($user) === null
        );
    }

    private function projectQuery(Authenticatable $user)
    {
        return $this->scopeStudioQuery($this->baseProjectQuery(), $user);
    }

    private function baseProjectQuery()
    {
        return Project::query()->with([
            'media',
            'shoot:id,address,hero_image',
            'aiEditingJobs',
            'aiListingVideoJobs',
            'aiReelJobs',
        ]);
    }

    private function presentProject(Project $project, bool $withDetails = false): array
    {
        $latestJob = $this->latestJob($project);
        $workflowId = $this->workflowId($project, $latestJob);
        $activityAt = $this->activityAt($project);

        $presented = [
            'id' => (string) $project->id,
            'name' => (string) $project->name,
            'address' => $project->address,
            'sourceType' => (string) $project->source_type,
            'shootId' => $project->shoot_id === null ? null : (int) $project->shoot_id,
            'workflowId' => (string) $project->workflow_id,
            'status' => (string) $project->status,
            'thumbnailRef' => $this->thumbnailRef($project),
            'latestWorkflowId' => $workflowId,
            'latestWorkflow' => $this->workflowTitle($workflowId),
            'latestStatus' => $latestJob === null
                ? (string) $project->status
                : (string) $latestJob['job']->getAttribute('status'),
            'lastActivityAt' => $activityAt->toISOString(),
            'mediaCount' => $project->media->count(),
            'version' => (int) $project->version,
            'createdAt' => $project->created_at?->toISOString(),
            'updatedAt' => $project->updated_at?->toISOString(),
            'deepLink' => [
                'destination' => 'projects',
                'recordType' => 'project',
                'recordId' => (string) $project->id,
                'workflowId' => $workflowId,
            ],
            '_activitySort' => $activityAt->format('Y-m-d H:i:s.u'),
        ];

        if ($withDetails) {
            $presented['media'] = $project->media
                ->sortByDesc(fn (ProjectMedia $media): string => $media->created_at?->format('Y-m-d H:i:s.u') ?? '')
                ->values()
                ->map(fn (ProjectMedia $media): array => [
                    'id' => (int) $media->id,
                    'mediaRef' => (string) $media->media_ref,
                    'kind' => (string) $media->kind,
                    'version' => (int) $media->version,
                    'createdAt' => $media->created_at?->toISOString(),
                    'updatedAt' => $media->updated_at?->toISOString(),
                ]);
            $presented['jobs'] = $this->presentJobs($project);
        }

        return $presented;
    }

    private function latestJob(Project $project): ?array
    {
        return $this->allJobs($project)
            ->sortByDesc(fn (array $entry): string => $entry['job']->updated_at?->format('Y-m-d H:i:s.u') ?? '')
            ->first();
    }

    private function allJobs(Project $project): Collection
    {
        $photo = $project->aiEditingJobs->map(fn (AiEditingJob $job): array => [
            'type' => 'photo',
            'job' => $job,
        ]);
        $video = $project->aiListingVideoJobs->map(fn (AiListingVideoJob $job): array => [
            'type' => 'video',
            'job' => $job,
        ]);
        $reels = $project->aiReelJobs->map(fn (AiReelJob $job): array => [
            'type' => 'reel',
            'job' => $job,
        ]);

        return $photo->concat($video)->concat($reels);
    }

    private function activityAt(Project $project): Carbon
    {
        $latestJob = $this->latestJob($project);
        $jobActivity = $latestJob['job']->updated_at ?? null;

        return $jobActivity !== null && $jobActivity->greaterThan($project->updated_at)
            ? $jobActivity
            : $project->updated_at;
    }

    private function workflowId(Project $project, ?array $latestJob): string
    {
        if ($latestJob === null) {
            return (string) $project->workflow_id;
        }

        if ($latestJob['type'] === 'video') {
            return 'listing-video';
        }
        if ($latestJob['type'] === 'reel') {
            return 'reel-generator';
        }

        /** @var AiEditingJob $job */
        $job = $latestJob['job'];
        if ($job->editing_type === AiEditingJob::TYPE_SKY_REPLACE) {
            return 'twilight';
        }

        return in_array($project->workflow_id, ['photo-enhancement', 'batch-ai-jobs'], true)
            ? (string) $project->workflow_id
            : 'photo-enhancement';
    }

    private function workflowTitle(string $workflowId): string
    {
        return self::WORKFLOW_TITLES[$workflowId]
            ?? Str::of($workflowId)->replace(['-', '_'], ' ')->title()->toString();
    }

    private function thumbnailRef(Project $project): ?string
    {
        $media = $project->media->sortByDesc(
            fn (ProjectMedia $item): string => $item->created_at?->format('Y-m-d H:i:s.u') ?? ''
        );
        $thumbnail = $media->firstWhere('kind', 'output') ?? $media->firstWhere('kind', 'source');
        if ($thumbnail !== null) {
            return (string) $thumbnail->media_ref;
        }

        $photoJob = $project->aiEditingJobs
            ->sortByDesc(fn (AiEditingJob $job): string => $job->updated_at?->format('Y-m-d H:i:s.u') ?? '')
            ->first();

        return $photoJob?->edited_image_url
            ?? $photoJob?->original_image_url
            ?? $project->shoot?->hero_image;
    }

    private function presentJobs(Project $project): Collection
    {
        return $this->allJobs($project)
            ->sortByDesc(fn (array $entry): string => $entry['job']->updated_at?->format('Y-m-d H:i:s.u') ?? '')
            ->values()
            ->map(function (array $entry) use ($project): array {
                /** @var Model $job */
                $job = $entry['job'];
                $workflowId = $this->workflowId($project, $entry);

                return [
                    'id' => $entry['type'].'-'.$job->getKey(),
                    'aiJobId' => (string) $job->getKey(),
                    'jobType' => $entry['type'],
                    'workflowId' => $workflowId,
                    'workflowTitle' => $this->workflowTitle($workflowId),
                    'status' => (string) $job->getAttribute('status'),
                    'updatedAt' => $job->updated_at?->toISOString(),
                    'completedAt' => $job->getAttribute('completed_at')?->toISOString(),
                ];
            });
    }

    private function withoutSortField(array $project): array
    {
        unset($project['_activitySort']);

        return $project;
    }
}
