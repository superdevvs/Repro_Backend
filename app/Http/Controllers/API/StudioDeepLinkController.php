<?php

namespace App\Http\Controllers\API;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\Template;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudioDeepLinkController extends StudioController
{
    private const DESTINATIONS = [
        'command-center',
        'projects',
        'queue',
        'metrics',
        'templates',
        'brand',
        'photo-enhancement',
        'twilight',
        'video-cleanup',
        'listing-video',
        'reel-generator',
        'batch-ai-jobs',
    ];

    private const RECORD_TYPES = ['project', 'shoot', 'template', 'workflow', 'ai_job'];

    private const WORKFLOWS = [
        'photo-enhancement' => [
            'title' => 'Photo Enhancement',
            'description' => 'Polish property photos with AI-powered corrections.',
            'mediaType' => 'Photo',
        ],
        'twilight' => [
            'title' => 'Twilight',
            'description' => 'Transform daylight exteriors into twilight scenes.',
            'mediaType' => 'Photo',
        ],
        'video-cleanup' => [
            'title' => 'Video Cleanup',
            'description' => 'Improve and clean up property video footage.',
            'mediaType' => 'Video',
        ],
        'listing-video' => [
            'title' => 'Listing Video',
            'description' => 'Create a polished listing video from property media.',
            'mediaType' => 'Video',
        ],
        'reel-generator' => [
            'title' => 'Reel Generator',
            'description' => 'Generate a social-ready property reel.',
            'mediaType' => 'Video',
        ],
        'batch-ai-jobs' => [
            'title' => 'Batch AI Jobs',
            'description' => 'Process a batch of property photos with AI.',
            'mediaType' => 'Photo',
        ],
    ];

    public function resolve(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'view');

        $validated = $request->validate([
            'destination' => ['required', 'string', Rule::in(self::DESTINATIONS)],
            'recordType' => ['nullable', 'required_with:recordId', 'string', Rule::in(self::RECORD_TYPES)],
            'recordId' => ['nullable', 'required_with:recordType', 'string', 'min:1', 'max:255'],
        ]);

        if (!isset($validated['recordType'])) {
            return $this->successResponse($validated['destination'], null);
        }

        $resolution = $this->resolveRecord($validated['recordType'], $validated['recordId'], $user);

        if ($resolution['status'] === 'missing') {
            return $this->recordError(404, 'studio_record_not_found', 'The requested Studio record was not found.');
        }

        if ($resolution['status'] === 'forbidden') {
            return $this->recordError(403, 'studio_record_forbidden', 'You are not authorized to access the requested Studio record.');
        }

        return $this->successResponse($validated['destination'], $resolution['record']);
    }

    private function resolveRecord(string $type, string $id, Authenticatable $user): array
    {
        return match ($type) {
            'project' => $this->resolveStudioModel(Project::query(), $id, $user, fn (Project $project) => $this->presentProject($project)),
            'shoot' => $this->resolveShoot($id, $user),
            'template' => $this->resolveStudioModel(Template::query(), $id, $user, fn (Template $template) => $this->presentTemplate($template)),
            'workflow' => $this->resolveWorkflow($id),
            'ai_job' => $this->resolveAiJob($id, $user),
        };
    }

    private function resolveStudioModel(Builder $query, string $id, Authenticatable $user, callable $present): array
    {
        $record = $query->find($id);
        if ($record === null) {
            return $this->missing();
        }

        try {
            $this->authorizeStudioAction($user, 'view', $record);
        } catch (AuthorizationException) {
            return $this->forbidden();
        }

        return $this->resolved($present($record));
    }

    private function resolveShoot(string $id, Authenticatable $user): array
    {
        if (!ctype_digit($id) || (int) $id < 1) {
            return $this->missing();
        }

        $shoot = Shoot::query()->find((int) $id);
        if ($shoot === null) {
            return $this->missing();
        }

        $authorized = Shoot::query()
            ->whereKey($shoot->id)
            ->where(fn (Builder $query) => $this->applyShootScope($query, $user))
            ->exists();

        return $authorized ? $this->resolved($this->presentShoot($shoot)) : $this->forbidden();
    }

    private function resolveWorkflow(string $id): array
    {
        $workflow = self::WORKFLOWS[$id] ?? null;
        if ($workflow === null) {
            return $this->missing();
        }

        return $this->resolved([
            'recordType' => 'workflow',
            'id' => $id,
            'title' => $workflow['title'],
            'description' => $workflow['description'],
            'mediaType' => $workflow['mediaType'],
            'destination' => $id,
            'available' => true,
            'availabilityReason' => null,
        ]);
    }

    private function resolveAiJob(string $id, Authenticatable $user): array
    {
        if (!preg_match('/^(photo|video|reel)-([1-9][0-9]*)$/', $id, $matches)) {
            return $this->missing();
        }

        $modelClass = match ($matches[1]) {
            'photo' => AiEditingJob::class,
            'video' => AiListingVideoJob::class,
            'reel' => AiReelJob::class,
        };
        $job = $modelClass::query()->find((int) $matches[2]);

        if ($job === null) {
            return $this->missing();
        }

        if (!$this->jobIsAuthorized($job, $user)) {
            return $this->forbidden();
        }

        return $this->resolved($this->presentAiJob($job, $matches[1]));
    }

    private function applyShootScope(Builder $query, Authenticatable $user): void
    {
        $userIds = $this->authorizedUserIds($user);

        $query->where(function (Builder $shoot) use ($userIds): void {
            $shoot->whereIn('client_id', $userIds)
                ->orWhereIn('photographer_id', $userIds)
                ->orWhereIn('editor_id', $userIds)
                ->orWhereIn('rep_id', $userIds)
                ->orWhereIn('created_by', $userIds)
                ->orWhereHas('services', fn (Builder $service) => $service
                    ->whereIn('shoot_service.editor_id', $userIds));
        });
    }

    private function jobIsAuthorized(Model $job, Authenticatable $user): bool
    {
        if (($userId = $this->scopeUserId($user)) !== null) {
            return (int) $job->getAttribute('user_id') === $userId;
        }

        $projectId = $job->getAttribute('project_id');
        if ($projectId !== null) {
            return Project::query()
                ->whereKey($projectId)
                ->where('team_id', $this->scopeTeamId($user))
                ->exists();
        }

        return in_array((int) $job->getAttribute('user_id'), $this->authorizedUserIds($user), true);
    }

    private function authorizedUserIds(Authenticatable $user): array
    {
        if ($this->scopeUserId($user) !== null) {
            return [(int) $user->getAuthIdentifier()];
        }

        $teamId = $this->scopeTeamId($user);

        return User::query()
            ->where(fn (Builder $candidate) => $candidate
                ->whereKey($teamId)
                ->orWhere('metadata->team_id', $teamId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function presentProject(Project $project): array
    {
        return [
            'recordType' => 'project',
            'id' => (string) $project->id,
            'name' => $project->name,
            'address' => $project->address,
            'shootId' => $project->shoot_id === null ? null : (string) $project->shoot_id,
            'workflowId' => $project->workflow_id,
            'status' => $project->status,
            'version' => (int) $project->version,
            'updatedAt' => $project->updated_at?->toISOString(),
        ];
    }

    private function presentShoot(Shoot $shoot): array
    {
        return [
            'recordType' => 'shoot',
            'id' => (string) $shoot->id,
            'address' => $shoot->address,
            'city' => $shoot->city,
            'state' => $shoot->state,
            'zip' => $shoot->zip,
            'propertySlug' => $shoot->property_slug,
            'status' => $shoot->status,
            'workflowStatus' => $shoot->workflow_status,
            'heroImage' => $shoot->hero_image,
            'updatedAt' => $shoot->updated_at?->toISOString(),
        ];
    }

    private function presentTemplate(Template $template): array
    {
        return [
            'recordType' => 'template',
            'id' => (string) $template->id,
            'name' => $template->name,
            'workflowId' => $template->workflow_id,
            'config' => $template->config,
            'version' => (int) $template->version,
            'updatedAt' => $template->updated_at?->toISOString(),
        ];
    }

    private function presentAiJob(Model $job, string $jobType): array
    {
        $workflowId = match ($jobType) {
            'photo' => $job->editing_type === AiEditingJob::TYPE_SKY_REPLACE ? 'twilight' : 'photo-enhancement',
            'video' => 'listing-video',
            'reel' => 'reel-generator',
        };

        return [
            'recordType' => 'ai_job',
            'id' => $jobType . '-' . $job->getKey(),
            'aiJobId' => (string) $job->getKey(),
            'jobType' => $jobType,
            'workflowId' => $workflowId,
            'status' => (string) $job->status,
            'projectId' => $job->getAttribute('project_id') === null ? null : (string) $job->getAttribute('project_id'),
            'shootId' => $job->getAttribute('shoot_id') === null ? null : (string) $job->getAttribute('shoot_id'),
            'version' => ($job->updated_at ?? $job->created_at)?->toISOString(),
        ];
    }

    private function successResponse(string $destination, ?array $record): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'destination' => $destination,
                'record' => $record,
            ],
        ]);
    }

    private function recordError(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => compact('code', 'message'),
        ], $status);
    }

    private function resolved(array $record): array
    {
        return ['status' => 'resolved', 'record' => $record];
    }

    private function missing(): array
    {
        return ['status' => 'missing'];
    }

    private function forbidden(): array
    {
        return ['status' => 'forbidden'];
    }
}
