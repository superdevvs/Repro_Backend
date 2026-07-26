<?php

namespace App\Services\Studio;

use App\Jobs\GenerateListingVideo;
use App\Jobs\GenerateReel;
use App\Jobs\ProcessAutoenhanceEditingJob;
use App\Jobs\ProcessFalEditingJob;
use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\BrandState;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\Template;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StudioProjectSubmissionService
{
    private const WORKFLOWS = [
        'photo-enhancement', 'twilight', 'video-cleanup',
        'listing-video', 'reel-generator', 'batch-ai-jobs',
    ];

    public function __construct(private ShootAuthorizationSupport $shootAuthorization)
    {
    }

    public function submit(Request $request, int $teamId, bool $privileged): JsonResponse
    {
        $this->normalize($request);
        $identity = $request->validate([
            'request_id' => ['required', 'string', 'max:64'],
        ]);
        $user = $request->user();
        $requestId = (string) $identity['request_id'];

        if ($existing = $this->existingProject((int) $user->id, $requestId)) {
            return $this->response($existing, false);
        }
        $sourceType = $request->input('source_type');
        $sourceField = $sourceType === 'shoot' ? 'file_ids' : ($sourceType === 'upload' ? 'media_refs' : null);
        if ($sourceField !== null && is_array($request->input($sourceField)) && $request->input($sourceField) === []) {
            return response()->json([
                'message' => "The {$sourceField} field must contain at least one item.",
                'errors' => [$sourceField => ["The {$sourceField} field must contain at least one item."]],
            ], 422);
        }
        $workflow = $request->input('workflow_id');
        if (is_string($workflow) && !in_array($workflow, self::WORKFLOWS, true)) {
            abort(404, 'Workflow not found.');
        }
        [$minimumSources, $maximumSources] = match ($workflow) {
            'listing-video' => [6, 10],
            'reel-generator' => [1, 20],
            default => [1, 100],
        };

        $validated = $request->validate([
            'request_id' => ['required', 'string', 'max:64'],
            'workflow_id' => ['required', 'string', Rule::in(self::WORKFLOWS)],
            'source_type' => ['required', 'string', Rule::in(['shoot', 'upload'])],
            'shoot_id' => ['nullable', 'integer', 'required_if:source_type,shoot'],
            'file_ids' => ['exclude_unless:source_type,shoot', 'required', 'array', "min:{$minimumSources}", "max:{$maximumSources}"],
            'file_ids.*' => ['integer', 'distinct'],
            'media_refs' => ['exclude_unless:source_type,upload', 'required', 'array', "min:{$minimumSources}", "max:{$maximumSources}"],
            'media_refs.*' => ['string', 'distinct', 'max:2048'],
            'name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'template_id' => ['nullable', 'uuid'],
            'workflow_config' => ['present', 'array'],
            'provider' => ['nullable', 'string', Rule::in(['autoenhance', 'fal'])],
            'target_seconds' => ['nullable', 'integer', Rule::in([30, 40, 45])],
            'bracket_size' => ['nullable', 'integer', Rule::in([3, 5])],
        ]);

        $template = $this->template($validated['template_id'] ?? null, $user, $teamId, $privileged);
        if ($template && $template->workflow_id !== $validated['workflow_id']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'template_id' => ['The template workflow does not match workflow_id.'],
            ]);
        }

        $workflowConfig = $template?->config ?? $validated['workflow_config'];
        $brandState = in_array($validated['workflow_id'], ['listing-video', 'reel-generator'], true)
            ? BrandState::latestCommittedForTeam($teamId)?->settings
            : null;
        [$shoot, $sources] = $validated['source_type'] === 'shoot'
            ? $this->shootSources($validated, $user, $teamId, $privileged)
            : $this->uploadSources($validated, $user, $teamId);
        $this->validateSourceCount($validated['workflow_id'], count($sources), $validated);

        try {
            $result = DB::transaction(function () use (
                $validated, $user, $teamId, $template, $workflowConfig, $brandState, $shoot, $sources
            ): array {
                $project = Project::query()->create([
                    'team_id' => $teamId,
                    'created_by' => (int) $user->id,
                    'shoot_id' => $shoot?->id,
                    'name' => trim((string) ($validated['name'] ?? '')) ?: $this->defaultName($validated['workflow_id'], $shoot),
                    'address' => $validated['address'] ?? $shoot?->address,
                    'source_type' => $validated['source_type'],
                    'workflow_id' => $validated['workflow_id'],
                    'status' => 'submitted',
                    'request_id' => $validated['request_id'],
                    'template_id' => $template?->id,
                    'workflow_config' => $workflowConfig,
                    'brand_state' => $brandState,
                ]);

                foreach ($sources as $source) {
                    ProjectMedia::query()->create([
                        'project_id' => $project->id,
                        'team_id' => $teamId,
                        'created_by' => (int) $user->id,
                        'media_ref' => $source['media_ref'],
                        'kind' => 'source',
                    ]);
                }

                $jobs = $this->createJobs(
                    $project, $validated, $workflowConfig, $brandState, $shoot, $sources, (int) $user->id
                );

                return compact('project', 'jobs');
            }, 3);
        } catch (QueryException $exception) {
            if ($existing = $this->existingProject((int) $user->id, $requestId)) {
                return $this->response($existing, false);
            }
            throw $exception;
        }

        $this->dispatchJobs($result['jobs'], $validated['workflow_id']);

        return $this->response($result['project']->fresh(), true);
    }
    private function normalize(Request $request): void
    {
        $source = is_array($request->input('source')) ? $request->input('source') : [];
        $media = $request->input('media', $source['media'] ?? []);
        $sourceType = $request->input('source_type', $request->input('sourceType', $source['type'] ?? null));
        $fileIds = $request->input(
            'file_ids',
            $request->input('fileIds', $request->input(
                'selected_file_ids',
                $request->input('selectedFileIds', $source['file_ids'] ?? $source['fileIds'] ?? null)
            ))
        );
        $mediaRefs = $request->input(
            'media_refs',
            $request->input('mediaRefs', $source['media_refs'] ?? $source['mediaRefs'] ?? null)
        );

        if (is_array($media)) {
            if ($fileIds === null) {
                $fileIds = collect($media)->map(fn ($item) => is_array($item) ? ($item['id'] ?? null) : null)
                    ->filter(fn ($id) => is_int($id) || ctype_digit((string) $id))->values()->all();
            }
            if ($mediaRefs === null) {
                $mediaRefs = collect($media)->map(fn ($item) => is_array($item)
                    ? ($item['mediaRef'] ?? $item['storagePath'] ?? $item['media_ref'] ?? null)
                    : (is_string($item) ? $item : null))->filter()->values()->all();
            }
        }

        $request->merge([
            'request_id' => $request->input('request_id', $request->input('requestId')),
            'workflow_id' => $request->input('workflow_id', $request->input('workflowId', $request->input('workflow'))),
            'source_type' => $sourceType,
            'shoot_id' => $request->input('shoot_id', $request->input('shootId', $source['shoot_id'] ?? $source['shootId'] ?? null)),
            'file_ids' => $fileIds,
            'media_refs' => $mediaRefs,
            'template_id' => $request->input('template_id', $request->input('templateId')),
            'workflow_config' => $request->input('workflow_config', $request->input('workflowConfig', $request->input('config', []))),
            'target_seconds' => $request->input('target_seconds', $request->input('targetSeconds')),
            'bracket_size' => $request->input('bracket_size', $request->input('bracketSize')),
        ]);
    }

    private function existingProject(int $userId, string $requestId): ?Project
    {
        return Project::query()
            ->where('created_by', $userId)
            ->where('request_id', $requestId)
            ->first();
    }

    private function template(?string $id, User $user, int $teamId, bool $privileged): ?Template
    {
        if ($id === null) {
            return null;
        }
        $template = Template::query()->find($id);
        if (!$template) {
            abort(404, 'Template not found.');
        }
        if ((int) $template->team_id !== $teamId || (!$privileged && (int) $template->created_by !== (int) $user->id)) {
            throw new AuthorizationException('This action is not authorized.');
        }

        return $template;
    }
    private function shootSources(array $validated, User $user, int $teamId, bool $privileged): array
    {
        $shoot = Shoot::query()->find($validated['shoot_id']);
        if (!$shoot) {
            abort(404, 'Shoot not found.');
        }
        if (!$this->shootIsInScope($shoot, $user, $teamId, $privileged)
            || !$this->shootAuthorization->canAccessShootMedia($shoot, $user)) {
            throw new AuthorizationException('This action is not authorized.');
        }

        $fileIds = array_values($validated['file_ids'] ?? []);
        if ($fileIds === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file_ids' => ['At least one source file is required.'],
            ]);
        }
        $files = ShootFile::query()->whereIn('id', $fileIds)->get()->keyBy('id');
        foreach ($fileIds as $fileId) {
            $file = $files->get($fileId);
            if (!$file) {
                abort(404, "Shoot file {$fileId} not found.");
            }
            if ((int) $file->shoot_id !== (int) $shoot->id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file_ids' => ["Shoot file {$fileId} does not belong to shoot {$shoot->id}."],
                ]);
            }
            if (!$this->supportsWorkflow($file, $validated['workflow_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file_ids' => ["Shoot file {$fileId} is not supported by {$validated['workflow_id']}."],
                ]);
            }
        }

        $sources = collect($fileIds)->map(function (int $id) use ($files): array {
            $file = $files->get($id);
            return [
                'file' => $file,
                'media_ref' => (string) ($file->storage_path ?: $file->path ?: "shoot-file:{$file->id}"),
            ];
        })->all();

        return [$shoot, $sources];
    }

    private function shootIsInScope(Shoot $shoot, User $user, int $teamId, bool $privileged): bool
    {
        $userIds = $privileged
            ? User::query()->where(fn (Builder $query) => $query
                ->whereKey($teamId)->orWhere('metadata->team_id', $teamId))->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [(int) $user->id];

        return Shoot::query()->whereKey($shoot->id)->where(function (Builder $query) use ($userIds): void {
            $query->whereIn('client_id', $userIds)
                ->orWhereIn('photographer_id', $userIds)
                ->orWhereIn('editor_id', $userIds)
                ->orWhereIn('rep_id', $userIds)
                ->orWhereIn('created_by', $userIds)
                ->orWhereHas('services', fn (Builder $service) => $service->whereIn('shoot_service.editor_id', $userIds));
        })->exists();
    }

    private function supportsWorkflow(ShootFile $file, string $workflow): bool
    {
        if ($workflow === 'video-cleanup') {
            return $this->shootAuthorization->isVideoMediaFile($file);
        }
        if (in_array($workflow, ['listing-video', 'reel-generator'], true)) {
            return $this->shootAuthorization->isImageMediaFile($file);
        }

        return $this->shootAuthorization->isImageMediaFile($file)
            || $this->shootAuthorization->isRawCameraFile($file);
    }
    private function uploadSources(array $validated, User $user, int $teamId): array
    {
        $refs = array_values($validated['media_refs'] ?? []);
        if ($refs === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'media_refs' => ['At least one uploaded media reference is required.'],
            ]);
        }

        $disk = (string) config('studio_uploads.disk', 'public');
        $prefix = "studio/uploads/{$teamId}/{$user->id}/";
        $extensions = (array) config("studio_uploads.workflows.{$validated['workflow_id']}.extensions", []);
        foreach ($refs as $ref) {
            $normalized = ltrim(str_replace('\\', '/', $ref), '/');
            if (!str_starts_with($normalized, $prefix)) {
                throw new AuthorizationException('An uploaded media reference is outside the authorized scope.');
            }
            if (!Storage::disk($disk)->exists($normalized)) {
                abort(404, 'Uploaded media not found.');
            }
            if (!in_array(strtolower(pathinfo($normalized, PATHINFO_EXTENSION)), $extensions, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'media_refs' => ["Uploaded media {$ref} is not supported by {$validated['workflow_id']}."],
                ]);
            }
        }

        return [null, collect($refs)->map(fn (string $ref): array => [
            'file' => null,
            'media_ref' => ltrim(str_replace('\\', '/', $ref), '/'),
        ])->all()];
    }

    private function validateSourceCount(string $workflow, int $count, array $validated): void
    {
        [$minimum, $maximum] = match ($workflow) {
            'listing-video' => [6, 10],
            'reel-generator' => [1, 20],
            default => [1, 100],
        };
        if ($count < $minimum || $count > $maximum) {
            $field = $validated['source_type'] === 'shoot' ? 'file_ids' : 'media_refs';
            throw \Illuminate\Validation\ValidationException::withMessages([
                $field => ["The {$field} field must contain between {$minimum} and {$maximum} items."],
            ]);
        }

        $bracketSize = $validated['bracket_size'] ?? null;
        if ($bracketSize !== null && ($workflow !== 'twilight' || $count < 3 || $count % $bracketSize !== 0)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bracket_size' => ['Twilight bracket media must be grouped in complete sets of 3 or 5.'],
            ]);
        }
    }

    private function defaultName(string $workflow, ?Shoot $shoot): string
    {
        if ($shoot?->address) {
            return (string) $shoot->address;
        }

        return str($workflow)->replace('-', ' ')->title()->append(' Project')->toString();
    }
    private function createJobs(
        Project $project,
        array $validated,
        array $workflowConfig,
        ?array $brandState,
        ?Shoot $shoot,
        array $sources,
        int $userId
    ): array {
        return match ($validated['workflow_id']) {
            'listing-video' => [$this->createListingVideoJob(
                $project, $validated, $workflowConfig, $brandState, $shoot, $sources, $userId
            )],
            'reel-generator' => [$this->createReelJob(
                $project, $validated, $workflowConfig, $brandState, $shoot, $sources, $userId
            )],
            default => $this->createEditingJobs(
                $project, $validated, $workflowConfig, $brandState, $shoot, $sources, $userId
            ),
        };
    }

    private function createEditingJobs(
        Project $project,
        array $validated,
        array $workflowConfig,
        ?array $brandState,
        ?Shoot $shoot,
        array $sources,
        int $userId
    ): array {
        $editingType = match ($validated['workflow_id']) {
            'twilight' => AiEditingJob::TYPE_SKY_REPLACE,
            'video-cleanup' => 'video-cleanup',
            default => AiEditingJob::TYPE_ENHANCE,
        };
        $provider = $validated['provider'] ?? ($validated['workflow_id'] === 'video-cleanup' ? 'fal' : 'autoenhance');
        $chunks = isset($validated['bracket_size']) ? array_chunk($sources, (int) $validated['bracket_size']) : $sources;
        $jobs = [];

        foreach ($chunks as $index => $sourceOrChunk) {
            $chunk = isset($validated['bracket_size']) ? $sourceOrChunk : [$sourceOrChunk];
            $source = $chunk[0];
            $file = $source['file'];
            $params = $workflowConfig;
            $params['_studio'] = array_filter([
                'workflowId' => $validated['workflow_id'],
                'templateId' => $project->template_id,
                'brandState' => $brandState,
                'bracketSize' => $validated['bracket_size'] ?? null,
                'bracketIndex' => isset($validated['bracket_size']) ? $index : null,
                'bracketFileIds' => isset($validated['bracket_size'])
                    ? collect($chunk)->pluck('file.id')->filter()->values()->all()
                    : null,
                'bracketMediaRefs' => isset($validated['bracket_size'])
                    ? collect($chunk)->pluck('media_ref')->values()->all()
                    : null,
            ], fn ($value) => $value !== null);

            $jobs[] = AiEditingJob::query()->create([
                'project_id' => $project->id,
                'request_id' => $validated['request_id'],
                'shoot_id' => $shoot?->id,
                'shoot_file_id' => $file?->id,
                'user_id' => $userId,
                'provider' => $provider,
                'status' => AiEditingJob::STATUS_PENDING,
                'editing_type' => $editingType,
                'editing_params' => $params,
                'original_image_url' => $file
                    ? (string) ($file->storage_path ?: $file->path ?: "shoot-file:{$file->id}")
                    : Storage::disk((string) config('studio_uploads.disk', 'public'))->url($source['media_ref']),
            ]);
        }

        return $jobs;
    }
    private function createListingVideoJob(
        Project $project,
        array $validated,
        array $workflowConfig,
        ?array $brandState,
        ?Shoot $shoot,
        array $sources,
        int $userId
    ): AiListingVideoJob {
        $fileIds = collect($sources)->pluck('file.id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $mediaRefs = $shoot ? [] : collect($sources)->pluck('media_ref')->values()->all();

        return AiListingVideoJob::query()->create([
            'project_id' => $project->id,
            'request_id' => $validated['request_id'],
            'shoot_id' => $shoot?->id,
            'user_id' => $userId,
            'provider' => 'fal',
            'selected_file_ids' => $fileIds,
            'source_media_refs' => $mediaRefs,
            'workflow_config' => $workflowConfig,
            'brand_state' => $brandState,
            'target_seconds' => $validated['target_seconds'] ?? ($workflowConfig['target_seconds'] ?? 30),
            'status' => AiListingVideoJob::STATUS_QUEUED,
            'total_clips' => count($sources),
            'completed_clips' => 0,
            'estimated_cost' => count($sources) * 0.8,
        ]);
    }

    private function createReelJob(
        Project $project,
        array $validated,
        array $workflowConfig,
        ?array $brandState,
        ?Shoot $shoot,
        array $sources,
        int $userId
    ): AiReelJob {
        return AiReelJob::query()->create([
            'project_id' => $project->id,
            'request_id' => $validated['request_id'],
            'shoot_id' => $shoot?->id,
            'user_id' => $userId,
            'provider' => 'fal',
            'selected_file_ids' => collect($sources)->pluck('file.id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            'source_media_refs' => $shoot ? [] : collect($sources)->pluck('media_ref')->values()->all(),
            'workflow_config' => $workflowConfig,
            'brand_state' => $brandState,
            'status' => AiReelJob::STATUS_QUEUED,
        ]);
    }

    private function dispatchJobs(array $jobs, string $workflow): void
    {
        foreach ($jobs as $job) {
            match ($workflow) {
                'listing-video' => GenerateListingVideo::dispatch($job->id),
                'reel-generator' => GenerateReel::dispatch($job->id),
                'video-cleanup' => null,
                default => $job->provider === 'fal'
                    ? ProcessFalEditingJob::dispatch($job)
                    : ProcessAutoenhanceEditingJob::dispatch($job),
            };
        }
    }

    private function response(Project $project, bool $created): JsonResponse
    {
        $photoIds = AiEditingJob::query()->where('project_id', $project->id)->orderBy('id')->pluck('id')
            ->map(fn ($id): string => (string) $id);
        $listingIds = AiListingVideoJob::query()->where('project_id', $project->id)->orderBy('id')->pluck('id')
            ->map(fn ($id): string => (string) $id);
        $reelIds = AiReelJob::query()->where('project_id', $project->id)->orderBy('id')->pluck('id')
            ->map(fn ($id): string => (string) $id);
        $jobIds = $photoIds->concat($listingIds)->concat($reelIds)->values();
        $jobs = $photoIds->map(fn (string $id): array => ['id' => $id, 'type' => 'photo'])
            ->concat($listingIds->map(fn (string $id): array => ['id' => $id, 'type' => 'listing-video']))
            ->concat($reelIds->map(fn (string $id): array => ['id' => $id, 'type' => 'reel']))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'projectId' => (string) $project->id,
                'aiJobId' => $jobIds->count() === 1 ? $jobIds->first() : null,
                'aiJobIds' => $jobIds,
                'jobs' => $jobs,
                'deepLink' => [
                    'destination' => 'projects',
                    'recordType' => 'project',
                    'recordId' => (string) $project->id,
                    'workflowId' => (string) $project->workflow_id,
                ],
                'version' => (int) $project->version,
            ],
        ], $created ? 201 : 200);
    }
}
