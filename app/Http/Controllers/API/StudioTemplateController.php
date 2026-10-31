<?php

namespace App\Http\Controllers\API;

use App\Models\Template;
use App\Services\Studio\StudioClientAccess;
use App\Services\Studio\StudioTemplateAccess;
use App\Services\Studio\StudioTemplateRecipe;
use App\Support\LockedWrite;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudioTemplateController extends StudioController
{
    protected const STUDIO_ROLES = ['admin', 'superadmin', 'editing_manager', 'editor', 'client'];

    private const WORKFLOW_IDS = [
        'photo-enhancement', 'twilight', 'video-cleanup', 'listing-video', 'reel-generator', 'batch-ai-jobs',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeActor($user);
        $data = $request->validate([
            'scope' => ['sometimes', Rule::in(['all', 'mine', 'team'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $scope = $data['scope'] ?? 'all';
        $query = $this->scopeStudioQuery(Template::query(), $user);
        if ($scope === 'mine') {
            $query->where('created_by', $user->getAuthIdentifier());
        } elseif ($scope === 'team') {
            $query->where('visibility', 'team');
            if (!StudioTemplateAccess::staff($user)) {
                $query->whereRaw('1 = 0');
            }
        }
        if (($search = trim($data['q'] ?? '')) !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }
        $templates = $query->orderByDesc('updated_at')->orderBy('name')->get()
            ->map(fn (Template $template): array => $this->presentTemplate($template, $user))->values();

        return response()->json([
            'success' => true,
            'data' => $templates,
            'meta' => ['total' => $templates->count(), 'scope' => $scope],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeActor($user);
        $data = $request->validate($this->templateRules());
        $visibility = $data['visibility'] ?? 'private';
        $this->authorizeVisibility($user, $visibility);
        [$preset, $workflow] = $this->recipeIdentity($data);
        $config = $this->recipeConfig($data['config'], $preset);
        $template = LockedWrite::run(fn () => Template::query()->create([
            'team_id' => $this->scopeTeamId($user),
            'created_by' => (int) $user->getAuthIdentifier(),
            'name' => trim($data['name']),
            'workflow_id' => $workflow,
            'preset_id' => $preset,
            'visibility' => $visibility,
            'config' => $config,
        ]), 'studio-template-create');

        return response()->json(['success' => true, 'data' => $this->presentTemplate($template->fresh(), $user)], 201);
    }

    public function update(Request $request, string $template): JsonResponse
    {
        $user = $request->user();
        $this->authorizeActor($user);

        return LockedWrite::run(fn () => DB::transaction(function () use ($request, $template, $user): JsonResponse {
            $record = Template::query()->whereKey($template)->lockForUpdate()->firstOrFail();
            $this->authorizeStudioAction($user, 'update', $record);
            $data = $request->validate($this->templateRules(true));
            if ((int) $data['version'] !== (int) $record->version) {
                return $this->staleVersionResponse($record, $user);
            }
            if (isset($data['visibility'])) {
                // A legacy privileged editor may maintain an old recipe, but may not
                // take over its owner's privacy/sharing decision.
                abort_unless(StudioTemplateAccess::owns($user, $record), 403);
                $this->authorizeVisibility($user, $data['visibility']);
            }
            // If an owner's role changed to client, a normal save must not
            // continue publishing their new direction into a former team recipe.
            $visibility = $data['visibility'] ?? $record->visibility;
            if ($visibility === null && !StudioTemplateAccess::staff($user)) {
                $visibility = 'private';
            }
            $this->authorizeVisibility($user, $visibility ?? 'private');
            [$preset, $workflow] = $this->recipeIdentity($data, $record);
            $record->fill([
                'name' => trim($data['name'] ?? $record->name),
                'workflow_id' => $workflow,
                'preset_id' => $preset,
                'visibility' => $visibility,
                'config' => $this->recipeConfig($data['config'] ?? $record->config ?? [], $preset),
            ])->save();

            return response()->json(['success' => true, 'data' => $this->presentTemplate($record->fresh(), $user)]);
        }), 'studio-template-update');
    }

    public function duplicate(Request $request, string $template): JsonResponse
    {
        $user = $request->user();
        $this->authorizeActor($user);

        return LockedWrite::run(fn () => DB::transaction(function () use ($request, $template, $user): JsonResponse {
            $record = Template::query()->whereKey($template)->lockForUpdate()->firstOrFail();
            $this->authorizeStudioAction($user, 'view', $record);
            $data = $request->validate([
                'version' => ['required', 'integer', 'min:1'],
                'name' => ['sometimes', 'required', 'string', 'max:255', 'regex:/\S/u'],
            ]);
            if ((int) $data['version'] !== (int) $record->version) {
                return $this->staleVersionResponse($record, $user);
            }
            $copy = Template::query()->create([
                'team_id' => $this->scopeTeamId($user),
                'created_by' => (int) $user->getAuthIdentifier(),
                'name' => trim($data['name'] ?? mb_substr($record->name, 0, 250).' copy'),
                'workflow_id' => $record->workflow_id,
                'preset_id' => $record->preset_id,
                'visibility' => 'private',
                'config' => $this->recipeConfig($record->config ?? [], $record->preset_id),
            ]);

            return response()->json(['success' => true, 'data' => $this->presentTemplate($copy->fresh(), $user)], 201);
        }), 'studio-template-duplicate');
    }

    public function destroy(Request $request, string $template): JsonResponse
    {
        $user = $request->user();
        $this->authorizeActor($user);

        return LockedWrite::run(fn () => DB::transaction(function () use ($request, $template, $user): JsonResponse {
            $record = Template::query()->whereKey($template)->lockForUpdate()->firstOrFail();
            $this->authorizeStudioAction($user, 'delete', $record);
            $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
            if ((int) $data['version'] !== (int) $record->version) {
                return $this->staleVersionResponse($record, $user);
            }
            $id = (string) $record->id;
            $version = (int) $record->version;
            $record->delete();

            return response()->json([
                'success' => true,
                'data' => ['id' => $id, 'deleted' => true, 'version' => $version, 'deletedAt' => now()->toISOString()],
            ]);
        }), 'studio-template-delete');
    }

    private function authorizeActor(?Authenticatable $user): void
    {
        $this->authorizeStudioAction($user, 'view');
        StudioClientAccess::authorize($user);
    }

    private function authorizeVisibility(Authenticatable $user, string $visibility): void
    {
        abort_if($visibility === 'team' && !StudioTemplateAccess::staff($user), 403, 'Only Studio staff can share team templates.');
    }

    private function templateRules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'required', 'string', 'max:255', 'regex:/\S/u'],
            'workflowId' => [$update ? 'sometimes' : 'required_without:presetId', 'string', Rule::in(self::WORKFLOW_IDS)],
            'presetId' => ['sometimes', 'nullable', 'string', Rule::in(StudioTemplateRecipe::PRESETS)],
            'visibility' => ['sometimes', Rule::in(['private', 'team'])],
            'config' => [$update ? 'sometimes' : 'present', 'array', 'max:128'],
            ...($update ? ['version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    private function recipeIdentity(array $data, ?Template $record = null): array
    {
        $preset = array_key_exists('presetId', $data) ? $data['presetId'] : $record?->preset_id;
        $workflow = $data['workflowId'] ?? ($preset ? StudioTemplateRecipe::workflow($preset) : $record?->workflow_id);
        if ($preset && $workflow !== StudioTemplateRecipe::workflow($preset)) {
            throw ValidationException::withMessages(['workflowId' => 'The workflow must match the selected preset.']);
        }

        return [$preset, $workflow];
    }

    private function recipeConfig(array $config, ?string $preset = null): array
    {
        if (strlen(json_encode($config, JSON_THROW_ON_ERROR)) > 65536) {
            throw ValidationException::withMessages(['config' => 'The template recipe must be smaller than 64 KB.']);
        }

        $config = StudioTemplateRecipe::sanitize($config);
        if ($preset !== null) {
            // Native recipes use the workspace DTO. Keep the legacy arbitrary
            // workflow configuration contract for templates without a preset.
            Validator::make(['config' => $config], [
                'config.prompt' => ['sometimes', 'nullable', 'string', 'max:4000'],
                'config.ratio' => ['sometimes', Rule::in(['9:16', '16:9', '1:1', '4:5'])],
                'config.duration' => ['sometimes', 'integer', 'between:5,120'],
                'config.transition' => ['sometimes', Rule::in(\App\Services\Studio\ReelCompositionService::TRANSITIONS)],
                'config.transitionDuration' => ['sometimes', 'numeric', 'between:0.1,2'],
                'config.text' => ['sometimes', 'array'],
                'config.text.title' => ['sometimes', 'nullable', 'string', 'max:200'],
                'config.text.subtitle' => ['sometimes', 'nullable', 'string', 'max:400'],
                'config.text.style' => ['sometimes', Rule::in(['none', 'minimal', 'editorial', 'lower-third', 'graphic'])],
                'config.text.position' => ['sometimes', Rule::in(['top', 'center', 'bottom'])],
                'config.text.timing' => ['sometimes', Rule::in(['last-scene', 'all'])],
                'config.adjustments' => ['sometimes', 'array'],
                'config.resolution' => ['sometimes', Rule::in(['720p', '1080p'])],
                'config.audio' => ['sometimes', 'array'],
                'config.audio.original' => ['sometimes', 'boolean'],
                'config.audio.musicVolume' => ['sometimes', 'numeric', 'between:0,1'],
                'config.audio.fade' => ['sometimes', 'boolean'],
            ])->validate();
        }

        return $config;
    }

    private function presentTemplate(Template $template, Authenticatable $user): array
    {
        $config = StudioTemplateRecipe::sanitize($template->config ?? []);

        return [
            'id' => (string) $template->id,
            'name' => (string) $template->name,
            'workflowId' => (string) $template->workflow_id,
            'presetId' => $template->preset_id,
            'visibility' => $template->visibility ?? 'legacy',
            'isOwner' => StudioTemplateAccess::owns($user, $template),
            'canEdit' => StudioTemplateAccess::canEdit($user, $template),
            'canDelete' => StudioTemplateAccess::canEdit($user, $template),
            'canShare' => StudioTemplateAccess::canShare($user, $template),
            'config' => $config,
            'version' => (int) $template->version,
            'createdBy' => (int) $template->created_by,
            'createdAt' => $template->created_at?->toISOString(),
            'updatedAt' => $template->updated_at?->toISOString(),
            // Both adapters contain parameters only. Applying them must retain the
            // destination's media/frames and clear output/frame review approvals.
            'workspaceDefaults' => ['presetId' => $template->preset_id, 'config' => $config],
            'projectDefaults' => [
                'templateId' => (string) $template->id,
                'workflowId' => (string) $template->workflow_id,
                'workflowConfig' => $config,
            ],
        ];
    }

    private function staleVersionResponse(Template $template, Authenticatable $user): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'stale_version',
                'message' => 'The template has changed since it was loaded.',
                'latestVersion' => (int) $template->version,
            ],
            'data' => $this->presentTemplate($template, $user),
        ], 409);
    }
}
