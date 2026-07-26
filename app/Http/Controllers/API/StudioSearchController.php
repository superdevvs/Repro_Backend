<?php

namespace App\Http\Controllers\API;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\Template;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StudioSearchController extends StudioController
{
    private const RESULT_LIMIT = 20;

    private const WORKFLOWS = [
        [
            'id' => 'photo-enhancement',
            'title' => 'Photo Enhancement',
            'description' => 'Polish property photos with AI-powered corrections.',
            'mediaType' => 'Photo',
            'destination' => 'photo-enhancement',
        ],
        [
            'id' => 'twilight',
            'title' => 'Twilight',
            'description' => 'Transform daylight exteriors into twilight scenes.',
            'mediaType' => 'Photo',
            'destination' => 'twilight',
        ],
        [
            'id' => 'video-cleanup',
            'title' => 'Video Cleanup',
            'description' => 'Improve and clean up property video footage.',
            'mediaType' => 'Video',
            'destination' => 'video-cleanup',
        ],
        [
            'id' => 'listing-video',
            'title' => 'Listing Video',
            'description' => 'Create a polished listing video from property media.',
            'mediaType' => 'Video',
            'destination' => 'listing-video',
        ],
        [
            'id' => 'reel-generator',
            'title' => 'Reel Generator',
            'description' => 'Generate a social-ready property reel.',
            'mediaType' => 'Video',
            'destination' => 'reel-generator',
        ],
        [
            'id' => 'batch-ai-jobs',
            'title' => 'Batch AI Jobs',
            'description' => 'Process a batch of property photos with AI.',
            'mediaType' => 'Photo',
            'destination' => 'batch-ai-jobs',
        ],
    ];

    private const PHOTO_WORKFLOW_TITLES = [
        AiEditingJob::TYPE_ENHANCE => 'Photo Enhancement',
        AiEditingJob::TYPE_SKY_REPLACE => 'Twilight',
        AiEditingJob::TYPE_HDR_MERGE => 'Photo Enhancement',
        AiEditingJob::TYPE_VERTICAL_CORRECTION => 'Photo Enhancement',
        AiEditingJob::TYPE_WINDOW_PULL => 'Photo Enhancement',
        AiEditingJob::TYPE_REMOVE_OBJECT => 'Photo Enhancement',
        AiEditingJob::TYPE_COLOR_CORRECTION => 'Photo Enhancement',
        AiEditingJob::TYPE_EXPOSURE_FIX => 'Photo Enhancement',
        AiEditingJob::TYPE_WHITE_BALANCE => 'Photo Enhancement',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'view');

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $query = trim((string) ($validated['q'] ?? ''));

        if ($query === '') {
            return $this->searchResponse($query, collect());
        }

        $user = $request->user();
        $groups = collect([
            $this->group('project', 'Projects', $this->projects($user, $query)),
            $this->group('shoot', 'Shoots', $this->shoots($user, $query)),
            $this->group('template', 'Templates', $this->templates($user, $query)),
            $this->group('workflow', 'Workflows', $this->workflows($query)),
            $this->group('ai_job', 'AI Jobs', $this->aiJobs($user, $query)),
        ])->filter(fn (array $group): bool => $group['results']->isNotEmpty())
            ->map(function (array $group): array {
                $group['results'] = $group['results']->values();

                return $group;
            })
            ->values();

        return $this->searchResponse($query, $groups);
    }

    private function projects(Authenticatable $user, string $query): Collection
    {
        $like = "%{$query}%";
        $projects = $this->scopeStudioQuery(Project::query(), $user)
            ->where(function (Builder $builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhere('workflow_id', 'like', $like)
                    ->orWhere('status', 'like', $like);
            })
            ->latest('updated_at')
            ->limit(self::RESULT_LIMIT)
            ->get();

        return $projects->map(fn (Project $project): array => $this->result(
            'project',
            (string) $project->id,
            $project->name,
            $this->joinContext([$project->address, $this->workflowTitle($project->workflow_id), $project->status]),
            'projects'
        ));
    }

    private function shoots(Authenticatable $user, string $query): Collection
    {
        $like = "%{$query}%";
        $shoots = $this->scopeShootQuery(Shoot::query(), $user)
            ->where(function (Builder $builder) use ($query, $like): void {
                $builder->where('address', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('state', 'like', $like)
                    ->orWhere('zip', 'like', $like)
                    ->orWhere('property_slug', 'like', $like);

                if (ctype_digit($query)) {
                    $builder->orWhereKey((int) $query);
                }
            })
            ->latest('updated_at')
            ->limit(self::RESULT_LIMIT)
            ->get();

        return $shoots->map(fn (Shoot $shoot): array => $this->result(
            'shoot',
            (string) $shoot->id,
            $shoot->address ?: "Shoot #{$shoot->id}",
            $this->joinContext([
                "Shoot #{$shoot->id}",
                $shoot->property_slug,
                $this->joinContext([$shoot->city, $shoot->state, $shoot->zip], ', '),
            ]),
            'command-center'
        ));
    }

    private function templates(Authenticatable $user, string $query): Collection
    {
        $like = "%{$query}%";
        $templates = $this->scopeStudioQuery(Template::query(), $user)
            ->where(function (Builder $builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('workflow_id', 'like', $like);
            })
            ->latest('updated_at')
            ->limit(self::RESULT_LIMIT)
            ->get();

        return $templates->map(fn (Template $template): array => $this->result(
            'template',
            (string) $template->id,
            $template->name,
            $this->workflowTitle($template->workflow_id) . ' workflow template',
            'templates'
        ));
    }

    private function workflows(string $query): Collection
    {
        return collect(self::WORKFLOWS)
            ->filter(fn (array $workflow): bool => $this->matches(
                $query,
                $workflow['id'],
                $workflow['title'],
                $workflow['description'],
                $workflow['mediaType']
            ))
            ->take(self::RESULT_LIMIT)
            ->map(fn (array $workflow): array => $this->result(
                'workflow',
                $workflow['id'],
                $workflow['title'],
                $workflow['mediaType'] . ' · ' . $workflow['description'],
                $workflow['destination']
            ));
    }

    private function aiJobs(Authenticatable $user, string $query): Collection
    {
        return $this->photoJobs($user, $query)
            ->concat($this->listingVideoJobs($user, $query))
            ->concat($this->reelJobs($user, $query))
            ->take(self::RESULT_LIMIT)
            ->values();
    }

    private function photoJobs(Authenticatable $user, string $query): Collection
    {
        $like = "%{$query}%";
        $matchingTypes = collect(self::PHOTO_WORKFLOW_TITLES)
            ->filter(fn (string $title, string $type): bool => $this->matches($query, $type, $title))
            ->keys()
            ->all();
        $matchesGenericTitle = $this->matches($query, 'photo ai job');

        $jobs = $this->scopeJobQuery(AiEditingJob::query(), $user)
            ->with(['project:id,name,address', 'shoot:id,address,city,state,zip'])
            ->where(function (Builder $builder) use ($query, $like, $matchingTypes, $matchesGenericTitle): void {
                $builder->where('status', 'like', $like)
                    ->orWhere('editing_type', 'like', $like)
                    ->orWhereHas('project', fn (Builder $project) => $project
                        ->where('name', 'like', $like)
                        ->orWhere('address', 'like', $like))
                    ->orWhereHas('shoot', fn (Builder $shoot) => $this->whereShootMatches($shoot, $like));

                if ($matchingTypes !== []) {
                    $builder->orWhereIn('editing_type', $matchingTypes);
                }
                if ($matchesGenericTitle) {
                    $builder->orWhereNotNull('id');
                }
                if (ctype_digit($query)) {
                    $builder->orWhereKey((int) $query);
                }
            })
            ->latest('updated_at')
            ->limit(self::RESULT_LIMIT * 3)
            ->get();

        return $jobs->map(function (AiEditingJob $job): array {
            $workflow = $this->workflowTitleForPhotoJob($job);

            return $this->result(
                'ai_job',
                'photo-' . $job->id,
                "{$workflow} AI Job #{$job->id}",
                $this->jobContext($job->status, $job->project, $job->shoot),
                'queue'
            );
        })->filter(fn (array $result): bool => $this->matchesResult($query, $result));
    }

    private function listingVideoJobs(Authenticatable $user, string $query): Collection
    {
        $jobs = $this->videoLikeJobs(
            AiListingVideoJob::query(),
            $user,
            $query,
            'Listing Video AI Job'
        )->with(['project:id,name,address', 'shoot:id,address,city,state,zip'])->get();

        return $jobs->map(fn (AiListingVideoJob $job): array => $this->result(
            'ai_job',
            'video-' . $job->id,
            "Listing Video AI Job #{$job->id}",
            $this->jobContext($job->status, $job->project, $job->shoot),
            'queue'
        ))->filter(fn (array $result): bool => $this->matchesResult($query, $result));
    }

    private function reelJobs(Authenticatable $user, string $query): Collection
    {
        $jobs = $this->videoLikeJobs(
            AiReelJob::query(),
            $user,
            $query,
            'Reel Generator AI Job'
        )->with('shoot:id,address,city,state,zip')->get();

        return $jobs->map(fn (AiReelJob $job): array => $this->result(
            'ai_job',
            'reel-' . $job->id,
            "Reel Generator AI Job #{$job->id}",
            $this->jobContext($job->status, null, $job->shoot),
            'queue'
        ))->filter(fn (array $result): bool => $this->matchesResult($query, $result));
    }

    private function videoLikeJobs(
        Builder $builder,
        Authenticatable $user,
        string $query,
        string $title
    ): Builder {
        $like = "%{$query}%";
        $matchesTitle = $this->matches($query, $title);

        return $this->scopeJobQuery($builder, $user)
            ->where(function (Builder $job) use ($query, $like, $matchesTitle): void {
                $job->where('status', 'like', $like)
                    ->orWhere('provider', 'like', $like)
                    ->orWhereHas('shoot', fn (Builder $shoot) => $this->whereShootMatches($shoot, $like));

                if ($matchesTitle) {
                    $job->orWhereNotNull('id');
                }
                if (ctype_digit($query)) {
                    $job->orWhereKey((int) $query);
                }
            })
            ->latest('updated_at')
            ->limit(self::RESULT_LIMIT * 3);
    }

    private function scopeJobQuery(Builder $query, Authenticatable $user): Builder
    {
        return $query->whereIn('user_id', $this->authorizedUserIds($user));
    }

    private function scopeShootQuery(Builder $query, Authenticatable $user): Builder
    {
        $userIds = $this->authorizedUserIds($user);

        return $query->where(function (Builder $shoot) use ($userIds): void {
            $shoot->whereIn('client_id', $userIds)
                ->orWhereIn('photographer_id', $userIds)
                ->orWhereIn('editor_id', $userIds)
                ->orWhereIn('rep_id', $userIds)
                ->orWhereIn('created_by', $userIds)
                ->orWhereHas('services', fn (Builder $service) => $service
                    ->whereIn('shoot_service.editor_id', $userIds));
        });
    }

    private function authorizedUserIds(Authenticatable $user): array
    {
        if ($this->scopeUserId($user) !== null) {
            return [(int) $user->getAuthIdentifier()];
        }

        $teamId = $this->scopeTeamId($user);

        return User::query()
            ->where(function (Builder $candidate) use ($teamId): void {
                $candidate->whereKey($teamId)
                    ->orWhere('metadata->team_id', $teamId);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function whereShootMatches(Builder $shoot, string $like): Builder
    {
        return $shoot->where('address', 'like', $like)
            ->orWhere('city', 'like', $like)
            ->orWhere('state', 'like', $like)
            ->orWhere('zip', 'like', $like)
            ->orWhere('property_slug', 'like', $like);
    }

    private function workflowTitleForPhotoJob(AiEditingJob $job): string
    {
        return self::PHOTO_WORKFLOW_TITLES[$job->editing_type]
            ?? Str::of((string) $job->editing_type)->replace('_', ' ')->title()->toString();
    }

    private function workflowTitle(?string $workflowId): string
    {
        $workflow = collect(self::WORKFLOWS)->firstWhere('id', $workflowId);

        return $workflow['title'] ?? Str::of((string) $workflowId)->replace(['-', '_'], ' ')->title()->toString();
    }

    private function jobContext(string $status, mixed $project, mixed $shoot): string
    {
        return $this->joinContext([
            Str::of($status)->replace('_', ' ')->title()->toString(),
            $project?->name,
            $project?->address,
            $shoot?->address,
        ]);
    }

    private function result(
        string $recordType,
        string $recordId,
        string $title,
        string $context,
        string $destination
    ): array {
        return [
            'recordType' => $recordType,
            'recordId' => $recordId,
            'title' => $title,
            'context' => $context,
            'deepLink' => [
                'destination' => $destination,
                'recordType' => $recordType,
                'recordId' => $recordId,
            ],
        ];
    }

    private function group(string $recordType, string $label, Collection $results): array
    {
        return compact('recordType', 'label', 'results');
    }

    private function matchesResult(string $query, array $result): bool
    {
        return $this->matches($query, $result['recordId'], $result['title'], $result['context']);
    }

    private function matches(string $query, ?string ...$values): bool
    {
        $needle = Str::lower($query);

        return collect($values)->contains(
            fn (?string $value): bool => $value !== null && Str::contains(Str::lower($value), $needle)
        );
    }

    private function joinContext(array $parts, string $separator = ' · '): string
    {
        return collect($parts)
            ->filter(fn ($part): bool => is_scalar($part) && trim((string) $part) !== '')
            ->map(fn ($part): string => trim((string) $part))
            ->unique()
            ->implode($separator);
    }

    private function searchResponse(string $query, Collection $groups): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $groups->values(),
            'meta' => [
                'query' => $query,
                'total' => $groups->sum(fn (array $group): int => $group['results']->count()),
            ],
        ]);
    }
}
