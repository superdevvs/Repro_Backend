<?php

namespace App\Http\Controllers\API;

use App\Exceptions\StudioProviderException;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use App\Services\Studio\VirtualStagingOptions;
use App\Services\Studio\VirtualStagingProcessor;
use App\Services\Studio\WorkspaceMediaService;
use App\Services\Studio\WorkspaceProcessor;
use App\Services\Studio\WorkspaceVideoAudio;
use App\Services\Studio\WorkspaceVideoOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudioWorkspaceController extends StudioController
{
    protected const STUDIO_ROLES = ['admin', 'superadmin', 'editing_manager', 'editor', 'client'];

    private const PRESETS = \App\Services\Studio\WorkspaceCatalog::PRESETS;

    public function __construct(private WorkspaceMediaService $mediaService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'view');

        $query = StudioWorkspace::whereNull('listing_studio_trial_id')->whereNull('listing_studio_order_item_id');
        if (in_array($request->user()->role, self::STUDIO_PRIVILEGED_ROLES, true)) {
            $query->where(fn ($query) => $query->where('team_id', $this->scopeTeamId($request->user()))->orWhereNotNull('shoot_id'));
        } else {
            $this->scopeStudioQuery($query, $request->user());
        }

        $filterRules = [
            'search' => 'nullable|string|max:200', 'mode' => ['nullable', Rule::in(['photo', 'video'])],
            'shootId' => ['nullable', 'integer', 'min:1'],
        ];
        if (! $request->attributes->get('contextual_search_source')) {
            $filterRules += ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
        }
        $filters = $request->validate($filterRules);
        if ($mode = $filters['mode'] ?? null) {
            $videoPresets = \App\Services\Studio\WorkspaceCatalog::VIDEO_PRESETS;
            $mode === 'video' ? $query->whereIn('preset_id', $videoPresets) : $query->whereNotIn('preset_id', $videoPresets);
        }
        if ($shootId = $filters['shootId'] ?? null) {
            $shoot = \App\Models\Shoot::findOrFail($shootId);
            abort_unless(app(\App\Services\Shoots\ShootAuthorizationSupport::class)->canAccessShootMedia($shoot, $request->user()), 403);
            // Standalone edits retain their source shoot in the media snapshot.
            // json_each is SQLite's bounded array expansion (also used in production).
            $query->where(fn ($scope) => $scope->where('shoot_id', $shootId)->orWhereRaw(
                "EXISTS (SELECT 1 FROM json_each(studio_workspaces.media) AS selected_media WHERE CAST(json_extract(selected_media.value, '$.shootId') AS INTEGER) = ?)", [$shootId]
            )->orWhereRaw(
                "EXISTS (SELECT 1 FROM json_each(studio_workspaces.access_media) AS retained_media WHERE CAST(json_extract(retained_media.value, '$.shootId') AS INTEGER) = ?)", [$shootId]
            ));
        }
        if ($term = trim((string) $request->query('search', ''))) {
            $query->where(function ($scope) use ($term) {
                $scope->where('name', 'like', '%'.$term.'%')->orWhere('id', $term)
                    ->orWhere('preset_id', 'like', '%'.str_replace(' ', '-', $term).'%');
                if (ctype_digit($term)) $scope->orWhere('shoot_id', (int) $term);
            });
        }

        // Ownership is not a continuing grant to a shoot. Authorize bounded
        // batches before counting pages; never materialize all media or bind an
        // unbounded list of UUIDs merely to display one Library page.
        $contextual = (bool) $request->attributes->get('contextual_search_source');
        $paged = $contextual || $request->hasAny(['page', 'per_page']);
        $perPage = $contextual ? max(1, min(100, $request->integer('per_page', 8))) : ($paged ? $request->integer('per_page', 30) : 100);
        $currentPage = $paged ? max(1, $request->integer('page', 1)) : 1;
        $offset = ($currentPage - 1) * $perPage;
        $total = 0;
        $pageIds = [];
        $access = app(\App\Services\Studio\WorkspaceReadAccess::class);
        (clone $query)->select(['id', 'shoot_id', 'media', 'access_media'])->latest('updated_at')->orderByDesc('id')->chunk(100,
            function ($records) use ($request, $access, $offset, $perPage, $paged, &$total, &$pageIds): bool {
                foreach ($access->visibleIds($records, $request->user()) as $id) {
                    if ($total >= $offset && count($pageIds) < $perPage) {
                        $pageIds[] = $id;
                    }
                    $total++;
                    if (! $paged && count($pageIds) === $perPage) {
                        return false;
                    }
                }

                return true;
            });
        $data = $query->whereIn('id', $pageIds)->latest('updated_at')->orderByDesc('id')->get()->map->present();
        $meta = ['current_page' => $currentPage, 'per_page' => $perPage, 'total' => $total];
        if ($contextual) {
            return response()->json(['data' => $data, 'meta' => $meta]);
        }
        if ($paged) {
            return response()->json(['success' => true, 'data' => $data, 'meta' => $meta + ['last_page' => max(1, (int) ceil($total / $perPage)), 'has_more' => $currentPage * $perPage < $total]]);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'create');
        $requestId = $request->header('Idempotency-Key', $request->input('requestId'));
        if ($requestId && strlen($requestId) > 64) {
            throw ValidationException::withMessages(['requestId' => 'Use an idempotency key of at most 64 characters.']);
        }
        if ($requestId && ($existing = StudioWorkspace::where('created_by', $request->user()->id)->where('request_id', $requestId)->first())) {
            return $this->respond($this->find($request, $existing->id));
        }
        $data = $this->validated($request);
        $media = $this->mediaService->authorize($data['media'], $request->user(), $this->scopeTeamId($request->user()));
        try {
            $workspace = StudioWorkspace::create([
                'team_id' => $this->scopeTeamId($request->user()), 'created_by' => $request->user()->id,
                'request_id' => $requestId, 'name' => $data['name'], 'preset_id' => $data['presetId'],
                'media' => $media, 'config' => $this->config($data['config'] ?? [], $media, $data['presetId']), 'status' => 'draft',
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            $existing = $requestId ? StudioWorkspace::where('created_by', $request->user()->id)->where('request_id', $requestId)->first() : null;
            if ($existing) {
                return $this->respond($this->find($request, $existing->id));
            }
            throw $exception;
        }

        return $this->respond($workspace->fresh(), 201);
    }

    public function show(Request $request, string $workspace): JsonResponse
    {
        return $this->respond($this->find($request, $workspace));
    }

    public function download(Request $request, string $workspace, string $output): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $record = $this->find($request, $workspace);
        $item = collect($record->outputs ?? [])->firstWhere('id', $output);
        abort_unless($item && in_array($item['status'] ?? '', ['completed', 'ready'], true), 404, 'This output is not available.');
        $disk = Storage::disk('public');
        $path = $item['path'] ?? null;
        if (! $path) {
            $prefix = rtrim($disk->url(''), '/').'/';
            abort_unless(str_starts_with($item['url'] ?? '', $prefix), 404, 'This output is not stored here.');
            $path = rawurldecode(substr($item['url'], strlen($prefix)));
        }
        abort_if(str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '\\'), 404);
        abort_unless($disk->exists($path), 404, 'This output file is no longer available.');

        $filename = basename(str_replace('\\', '/', (string) ($item['name'] ?? basename($path))));

        return $disk->download($path, $filename, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, string $workspace): JsonResponse
    {
        $record = $this->find($request, $workspace);
        $data = $this->validated($request, true);
        $media = isset($data['media']) ? $this->mediaService->authorize($data['media'], $request->user(), $record->team_id) : $record->media;
        if ($record->shoot_id) {
            abort_if(isset($data['presetId']) && $data['presetId'] !== $record->preset_id, 422, 'Create a new project to change the booked editing service.');
            abort_unless(collect($media)->every(fn ($item) => (int) ($item['shootId'] ?? 0) === $record->shoot_id), 422, 'This project belongs to one shoot. Choose photos from that shoot.');
        }

        return DB::transaction(function () use ($record, $data, $media): JsonResponse {
            $record = StudioWorkspace::lockForUpdate()->findOrFail($record->id);
            abort_if($record->isBusy(), 409, 'Wait for this operation to finish or cancel it before editing the draft.');
            abort_if(isset($data['version']) && $data['version'] !== $record->version, 409, 'The workspace changed. Reload before saving.');
            if ($record->outputs || $record->prepared_frames || $record->history) {
                $record->access_media = app(\App\Services\Studio\WorkspaceReadAccess::class)->retainedSources($record);
            }
            $next = array_replace_recursive($record->config, $data['config'] ?? []);
            foreach (['frames', 'adjustments', 'reviewedOutputIds', 'reviewedFrameIds'] as $field) {
                if (array_key_exists($field, $data['config'] ?? [])) {
                    $next[$field] = $data['config'][$field];
                }
            }
            $config = $this->config($next, $media, $data['presetId'] ?? $record->preset_id, $record);
            $preparationChanged = $record->media !== $media || ($record->config['ratio'] ?? null) !== $config['ratio']
                || collect($record->config['frames'] ?? [])->mapWithKeys(fn ($f) => [$f['mediaId'] => $f['method']])->sortKeys()->all() !== collect($config['frames'])->mapWithKeys(fn ($f) => [$f['mediaId'] => $f['method']])->sortKeys()->all();
            $reviewFields = ['reviewedOutputIds', 'reviewedFrameIds'];
            $changed = $record->media !== $media || StudioWorkspace::canonicalConfig(\Illuminate\Support\Arr::except($record->config, $reviewFields)) !== StudioWorkspace::canonicalConfig(\Illuminate\Support\Arr::except($config, $reviewFields)) || (isset($data['presetId']) && $record->preset_id !== $data['presetId']);
            $record->fill(['name' => $data['name'] ?? $record->name, 'preset_id' => $data['presetId'] ?? $record->preset_id, 'media' => $media, 'config' => $config, 'version' => $record->version + 1]);
            if ($changed) {
                $record->fill(['status' => $preparationChanged || ! $record->prepared_frames ? 'draft' : 'ready', 'prepared_frames' => $preparationChanged ? [] : $record->prepared_frames, 'operation' => null, 'error' => null, 'progress' => null]);
            }
            $record->save();

            return $this->respond($record);
        });
    }

    public function prepare(Request $request, string $workspace): JsonResponse
    {
        return $this->start($request, $workspace, 'prepare');
    }

    public function generate(Request $request, string $workspace): JsonResponse
    {
        return $this->start($request, $workspace, 'generate');
    }

    public function revisions(Request $request, string $workspace): JsonResponse
    {
        return $this->start($request, $workspace, 'revision');
    }

    public function upscale(Request $request, string $workspace): JsonResponse
    {
        return $this->start($request, $workspace, 'upscale');
    }

    public function edits(Request $request, string $workspace): JsonResponse
    {
        return $this->start($request, $workspace, 'adjust');
    }

    public function editPreview(Request $request, string $workspace): \Illuminate\Http\Response
    {
        $record = $this->find($request, $workspace);
        $this->mediaService->authorize($record->media, $request->user(), $record->team_id);
        $data = $request->validate(['mediaId' => ['required', 'string'], 'outputId' => ['required', 'string'], 'edits' => ['required', 'array']]);
        $recipe = \App\Services\Studio\CustomPhotoEdits::validate($data['edits']);
        $output = collect($record->outputs ?? [])->firstWhere('id', $data['outputId']);
        $path = $output['path'] ?? '';
        abort_unless($output && collect($record->media)->contains('id', $data['mediaId']) && ($output['mediaId'] ?? '') === $data['mediaId'] && ($output['kind'] ?? '') === 'image' && ($output['status'] ?? '') === 'completed'
            && str_starts_with($path, 'studio/workspaces/'.$record->id.'/') && ! str_contains($path, '..') && ! str_contains($path, '\\') && Storage::disk('public')->exists($path), 422, 'Choose an available completed photo.');
        $bytes = Storage::disk('public')->get($path);
        $size = @getimagesizefromstring($bytes);
        abort_unless($size && $size[0] * $size[1] <= 40000000, 422, 'This photo exceeds the 40 megapixel editing limit.');
        $small = (string) \Intervention\Image\ImageManager::gd()->read($bytes)->orient()->scaleDown(width: 1000, height: 1000)->toJpeg(96);

        return response(app(\App\Services\Studio\CustomPhotoEdits::class)->apply($small, $recipe, app(\App\Services\Studio\WorkspacePhotoLogo::class)->bytes($record, $recipe)), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store']);
    }

    public function photoLogo(Request $request, string $workspace): JsonResponse
    {
        $record = $this->find($request, $workspace);
        abort_if($record->isVideo() || $record->isBusy(), 409, 'Wait for the photo operation to finish.');
        $data = $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);

        return response()->json(['success' => true, 'data' => app(\App\Services\Studio\WorkspacePhotoLogo::class)->store($record, $data['logo']->get())], 201);
    }

    public function uploadAudio(Request $request, string $workspace, WorkspaceVideoAudio $audio): JsonResponse
    {
        $record = $this->find($request, $workspace);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'audio' => ['required', 'file', 'mimes:mp3,mpga,m4a,mp4,aac,wav,flac', 'max:20480']]);
        abort_unless($record->isVideo(), 422, 'Audio is available for video projects.');
        abort_if($record->isBusy() || $record->version !== (int) $data['version'], 409, 'The workspace changed. Refresh before adding audio.');
        $asset = $audio->store($record, $data['audio']);
        try {
            // Transcoding is deliberately outside the write transaction. Recheck authorization and
            // use a single conditional write so a concurrent generation cannot accept stale audio.
            $this->find($request, $workspace);
            $updated = StudioWorkspace::whereKey($record->id)->where('version', $data['version'])
                ->whereNotIn('status', ['preparing', 'generating'])->increment('version');
            abort_unless($updated === 1, 409, 'The workspace changed. Refresh before adding audio.');
        } catch (\Throwable $exception) {
            $audio->delete($record, $asset['id']);
            throw $exception;
        }
        $asset['workspaceVersion'] = $record->version + 1;

        return response()->json(['success' => true, 'data' => $asset], 201);
    }

    public function audioLibrary(Request $request, string $workspace, WorkspaceVideoAudio $assets): JsonResponse
    {
        $record = $this->find($request, $workspace);

        return response()->json(['success' => true, 'data' => $assets->all($record), 'workspaceVersion' => $record->version]);
    }

    public function previewAudio(Request $request, string $workspace, string $audio, WorkspaceVideoAudio $assets)
    {
        $record = $this->find($request, $workspace);

        return response()->file($assets->path($record, $audio), ['Content-Type' => 'audio/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function deleteAudio(Request $request, string $workspace, string $audio, WorkspaceVideoAudio $assets): JsonResponse
    {
        $record = $this->find($request, $workspace);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($record, $data, $audio, $assets): JsonResponse {
            // Acquire the SQLite writer before reading config and removing the immutable asset.
            $changed = StudioWorkspace::whereKey($record->id)->where('version', $data['version'])
                ->whereNotIn('status', ['preparing', 'generating'])->increment('version');
            abort_unless($changed === 1, 409, 'The workspace changed. Refresh before removing audio.');
            $record = $record->fresh();
            abort_if(data_get($record->config, 'audio.musicId') === $audio, 409, 'Remove this audio from Sound before deleting it.');
            $assets->metadata($record, $audio);
            $assets->delete($record, $audio);

            return $this->respond($record);
        });
    }

    public function cancel(Request $request, string $workspace): JsonResponse
    {
        $record = $this->find($request, $workspace);

        return DB::transaction(function () use ($record): JsonResponse {
            $record = StudioWorkspace::lockForUpdate()->findOrFail($record->id);
            if ($record->isBusy()) {
                if ($id = data_get($record->operation, 'reelJobId')) {
                    AiReelJob::find($id)?->markAsCancelled();
                }
                $record->update(['status' => 'cancelled', 'error' => null, 'version' => $record->version + 1]);
            }

            return $this->respond($record);
        });
    }

    public function segments(Request $request, string $workspace, WorkspaceProcessor $processor): JsonResponse
    {
        $record = $this->find($request, $workspace);
        $this->mediaService->authorize($record->media, $request->user(), $record->team_id);
        $data = $request->validate(['mediaId' => ['required', 'string']]);
        abort_unless(collect($record->media)->contains('id', $data['mediaId']), 422, 'Select a workspace image.');
        abort_unless(filled(config('services.openai.api_key')), 503, 'Object detection is not configured. Contact an administrator.');

        try {
            return response()->json(['success' => true, 'data' => $processor->segments($record, $data['mediaId'])]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => 'Object detection could not finish. Please try again.'], 503);
        }
    }

    public function furnitureAnalysis(Request $request, string $workspace, VirtualStagingProcessor $processor): JsonResponse
    {
        $record = $this->find($request, $workspace);
        abort_if($record->isBusy(), 409, 'Wait for the current staging job to finish.');
        abort_unless($record->preset_id === 'virtual-staging', 422, 'Furniture detection is part of virtual staging.');
        $this->mediaService->authorize($record->media, $request->user(), $record->team_id);
        $data = $request->validate(['mediaId' => ['required', 'string']]);
        $item = collect($record->media)->firstWhere('id', $data['mediaId']);
        abort_unless(is_array($item), 422, 'Select a workspace image.');
        try {
            return response()->json(['success' => true, 'data' => $processor->analyze($record, $item)]);
        } catch (StudioProviderException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => 'Furniture in this photo could not be detected. Please retry.'], 503);
        }
    }

    private function start(Request $request, string $workspace, string $type): JsonResponse
    {
        $record = $this->find($request, $workspace);
        $this->mediaService->authorize($record->media, $request->user(), $record->team_id);
        // Check the history budget before any provider work can be accepted.
        app(\App\Services\Studio\WorkspaceReadAccess::class)->retainedSources($record);
        if ($type === 'generate') {
            $check = clone $record;
            $check->operation = ['type' => 'generate'];
            app(\App\Services\Shoots\ShootPhotoSet::class)->assertFullWorkspace($check);
        }
        $payload = $request->validate([
            'edits' => [$type === 'adjust' ? 'required' : 'prohibited', 'array'],
            'targets' => [$type === 'adjust' ? 'required' : 'prohibited', 'array', 'min:1', 'max:300'],
            'targets.*' => ['array:mediaId,outputId'], 'targets.*.mediaId' => ['required', 'string', 'distinct'], 'targets.*.outputId' => ['required', 'string', 'max:200'],
            'requestId' => ['sometimes', 'string', 'max:64'], 'mediaId' => [in_array($type, ['revision', 'upscale'], true) ? 'required' : 'sometimes', 'string'],
            'outputId' => [$type === 'upscale' || ($type === 'revision' && $request->boolean('customEdit')) ? 'required' : 'sometimes', 'string', 'max:200'],
            'referenceMediaIds' => ['sometimes', 'array', 'max:4'], 'referenceMediaIds.*' => ['string', 'distinct', 'max:100'],
            'customEdit' => [$type === 'revision' ? 'sometimes' : 'prohibited', 'boolean'],
            'prompt' => [$type === 'revision' ? 'required' : 'sometimes', 'string', 'max:4000'],
            'region' => ['nullable', 'array:x,y,width,height'],
            'region.x' => ['required_with:region', 'numeric', 'between:0,1'], 'region.y' => ['required_with:region', 'numeric', 'between:0,1'],
            'region.width' => ['required_with:region', 'numeric', 'gt:0', 'max:1'], 'region.height' => ['required_with:region', 'numeric', 'gt:0', 'max:1'],
            'drawing' => ['nullable', 'array', 'max:20'], 'drawing.*' => ['array', 'max:200'], 'drawing.*.*' => ['array:x,y'],
            'drawing.*.*.x' => ['required', 'numeric', 'between:0,1'], 'drawing.*.*.y' => ['required', 'numeric', 'between:0,1'],
        ]);
        if ($type === 'adjust') {
            abort_if($record->isVideo(), 422, 'Custom edits are for photos.');
            $payload['edits'] = \App\Services\Studio\CustomPhotoEdits::validate($payload['edits']);
            app(\App\Services\Studio\WorkspacePhotoLogo::class)->bytes($record, $payload['edits']);
            abort_if(count($payload['targets']) > 1 && (! empty($payload['edits']['crop']) || ! empty($payload['edits']['blur']) || ! empty($payload['edits']['marks'])), 422, 'Area selections apply to one photo at a time.');
            foreach ($payload['targets'] as $target) {
                $output = collect($record->outputs ?? [])->firstWhere('id', $target['outputId']);
                abort_unless(collect($record->media)->contains('id', $target['mediaId']) && $output && ($output['mediaId'] ?? null) === $target['mediaId'] && ($output['kind'] ?? null) === 'image' && ($output['status'] ?? null) === 'completed', 422, 'Choose a completed version from this workspace.');
            }
        }
        if (isset($payload['region']) && ($payload['region']['x'] + $payload['region']['width'] > 1.00001 || $payload['region']['y'] + $payload['region']['height'] > 1.00001)) {
            throw ValidationException::withMessages(['region' => 'The selected region must fit inside the image.']);
        }
        if (in_array($type, ['revision', 'upscale'], true) && ! collect($record->media)->contains('id', $payload['mediaId'])) {
            throw ValidationException::withMessages(['mediaId' => 'Select an image from this workspace.']);
        }
        foreach ($payload['referenceMediaIds'] ?? [] as $id) {
            abort_unless(collect($record->media)->contains('id', $id), 422, 'Choose reference photos from this workspace.');
        }
        if ($type === 'upscale' || ! empty($payload['customEdit'])) {
            $output = collect($record->outputs ?? [])->firstWhere('id', $payload['outputId']);
            abort_unless($output && ($output['mediaId'] ?? null) === $payload['mediaId'] && ($output['kind'] ?? null) === 'image' && ($output['status'] ?? null) === 'completed', 422, 'Choose a completed image version.');
        }
        $key = $request->header('Idempotency-Key', $payload['requestId'] ?? null);
        if ($key && strlen($key) > 64) {
            throw ValidationException::withMessages(['requestId' => 'Use an idempotency key of at most 64 characters.']);
        }
        if (! $key && $record->shoot_dispatch_key && $record->status === 'failed' && $type === 'generate' && $payload === [] && ($record->operation['type'] ?? '') === 'generate') {
            $key = $record->operation['key'];
        }
        $key ??= hash('sha256', json_encode([$type, \Illuminate\Support\Arr::except($record->config, ['reviewedOutputIds', 'reviewedFrameIds']), $record->media, $payload]));
        $dispatch = false;
        $record = DB::transaction(function () use ($record, $type, $payload, $key, &$dispatch): StudioWorkspace {
            $record = StudioWorkspace::lockForUpdate()->findOrFail($record->id);
            $old = $record->operation ?? [];
            $same = ($old['key'] ?? null) === $key && ($old['type'] ?? null) === $type;
            if ($same && in_array($record->status, ['preparing', 'generating', 'ready', 'completed'], true)) {
                return $record;
            }
            abort_if($record->isBusy(), 409, 'This workspace already has an operation in progress.');
            abort_if(count($record->media) === 0, 422, 'Add source photos before continuing.');
            if ($record->preset_id === 'virtual-staging' && in_array($type, ['generate', 'revision'], true) && empty($payload['customEdit'])) {
                $settings = app(\App\Services\Studio\StudioProviderSettings::class);
                $availability = $settings->readiness('virtual-staging', $settings->route('virtual-staging'));
                abort_unless($availability['ready'], 422, $availability['reason'] ?? 'An administrator needs to finish configuring this service.');
                VirtualStagingOptions::assert($record->config['adjustments'] ?? []);
            }
            if ($record->isVideo() && $type === 'generate') {
                app(WorkspaceVideoAudio::class)->path($record, data_get($record->config, 'audio.musicId'));
                abort_if($record->preset_id === 'walkthrough' && ! filled(config('services.fal.walkthrough_model')), 503, 'The start/end-frame walkthrough model is not configured.');
                $frameIds = array_column($record->config['frames'] ?? [], 'mediaId');
                if (! $frameIds) {
                    $frameIds = array_column($record->media, 'id');
                }
                $prepared = collect($record->prepared_frames ?? [])->where('status', 'completed')->keyBy('mediaId');
                abort_unless(collect($frameIds)->every(fn ($id) => $prepared->has($id)), 422, 'Prepare every selected frame before generating a reel.');
                abort_if(count($frameIds) > 12, 422, 'A reel supports at most 12 selected source photos.');
            }
            if (! empty($payload['customEdit'])) {
                abort_if($record->isVideo(), 422, 'Custom photo edits are for photos.');
                $settings = app(\App\Services\Studio\StudioProviderSettings::class);
                $availability = $settings->readiness('custom-revision', $settings->route('revision'));
                abort_unless($availability['ready'], 422, $availability['reason'] ?? 'AI refinement is not configured.');
            }
            $operation = $same && $record->status === 'failed' ? $old : ['id' => (string) Str::uuid(), 'key' => $key, 'type' => $type, 'payload' => $payload, 'completed' => [], 'requests' => []];
            if (! isset($operation['routing']) && empty($operation['requests'])) {
                $settings = app(\App\Services\Studio\StudioProviderSettings::class);
                $stored = $settings->stored();
                foreach (array_unique([$record->preset_id, 'revision', 'upscale', 'outpaint']) as $service) {
                    $operation['routing'][$service] = $settings->route($service, $stored);
                }
            }
            $record->update(['operation' => $operation, 'status' => $type === 'prepare' ? 'preparing' : 'generating', 'progress' => 0, 'error' => null, 'version' => $record->version + 1]);
            $dispatch = true;

            return $record;
        });
        if ($dispatch) {
            try {
                ProcessStudioWorkspace::dispatch($record->id, $record->operation['id']);
            } catch (\Throwable $e) {
                $record->update(['status' => 'failed', 'error' => 'The operation could not be queued. Please retry.']);
                report($e);

                return $this->respond($record, 503);
            }
        }

        return $this->respond($record->fresh(), 202);
    }

    private function find(Request $request, string $id): StudioWorkspace
    {
        $record = StudioWorkspace::whereNull('listing_studio_trial_id')->whereNull('listing_studio_order_item_id')->findOrFail($id);
        if ($record->shoot_id && in_array($request->user()->role, self::STUDIO_PRIVILEGED_ROLES, true)) {
            app(\App\Services\Shoots\ShootAuthorizationSupport::class)->ensureShootAccess(\App\Models\Shoot::findOrFail($record->shoot_id), $request->user());
        } else {
            $this->authorizeStudioAction($request->user(), 'view', $record);
        }
        app(\App\Services\Studio\WorkspaceReadAccess::class)->authorize($record, $request->user());

        return $record;
    }

    private function respond(StudioWorkspace $workspace, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $workspace->present()], $status);
    }

    private function validated(Request $request, bool $patch = false): array
    {
        $presence = $patch ? 'sometimes' : 'required';

        return $request->validate([
            'requestId' => ['sometimes', 'string', 'max:64'], 'version' => ['sometimes', 'integer', 'min:1'],
            'name' => [$presence, 'string', 'max:255'], 'presetId' => [$presence, Rule::in(self::PRESETS)],
            'media' => [$presence, 'array', 'max:300'], 'media.*.id' => ['required', 'string', 'distinct', 'max:100'],
            'media.*.fileId' => ['nullable', 'integer'], 'media.*.shootId' => ['nullable', 'integer'], 'media.*.mediaRef' => ['nullable', 'string', 'max:1024'],
            'media.*.stackFileIds' => ['sometimes', 'array', 'min:2', 'max:7'], 'media.*.stackFileIds.*' => ['required', 'integer', 'min:1'],
            'config' => ['sometimes', 'array'], 'config.prompt' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'config.ratio' => ['sometimes', Rule::in(['9:16', '16:9', '1:1', '4:5'])], 'config.duration' => ['sometimes', 'integer', 'between:5,120'],
            'config.transition' => ['sometimes', Rule::in(\App\Services\Studio\ReelCompositionService::TRANSITIONS)], 'config.transitionDuration' => ['sometimes', 'numeric', 'between:0.1,2'],
            'config.text' => ['sometimes', 'array'], 'config.text.title' => ['sometimes', 'nullable', 'string', 'max:200'], 'config.text.subtitle' => ['sometimes', 'nullable', 'string', 'max:400'],
            'config.text.style' => ['sometimes', Rule::in(['none', 'minimal', 'editorial', 'lower-third', 'graphic'])], 'config.text.position' => ['sometimes', Rule::in(['top', 'center', 'bottom'])],
            'config.text.timing' => ['sometimes', Rule::in(['last-scene', 'all'])],
            'config.adjustments' => ['sometimes', 'array'], 'config.frames' => ['sometimes', 'array', 'max:300'],
            'config.reviewedOutputIds' => ['sometimes', 'array', 'max:1000'], 'config.reviewedOutputIds.*' => ['string', 'max:200'],
            'config.reviewedFrameIds' => ['sometimes', 'array', 'max:300'], 'config.reviewedFrameIds.*' => ['string', 'max:200'],
            'config.frames.*.mediaId' => ['required', 'string', 'distinct'], 'config.frames.*.method' => ['required', Rule::in(['extend', 'crop', 'fit'])],
            'config.frames.*.duration' => ['sometimes', 'numeric', 'between:0.5,30'], 'config.frames.*.prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...WorkspaceVideoOptions::rules(),
        ]);
    }

    private function config(array $config, array $media, string $preset, ?StudioWorkspace $workspace = null): array
    {
        // Runtime state and file references are exclusively server-owned.
        $config = \Illuminate\Support\Arr::only($config, ['prompt', 'ratio', 'duration', 'transition', 'transitionDuration', 'text', 'adjustments', 'frames', 'reviewedOutputIds', 'reviewedFrameIds', 'resolution', 'audio']);
        $config = array_replace_recursive(['prompt' => '', 'ratio' => '9:16', 'duration' => 30, 'transition' => 'none', 'transitionDuration' => 0.4, 'text' => ['title' => '', 'subtitle' => '', 'style' => 'none', 'position' => 'bottom'], 'adjustments' => [], 'frames' => []], $config);
        $config = WorkspaceVideoOptions::normalize($config);
        if ($id = $config['audio']['musicId']) {
            abort_unless($workspace && in_array($preset, ['property-reel', 'walkthrough', 'social-teaser'], true), 422, 'Upload audio to this video workspace before selecting it.');
            app(WorkspaceVideoAudio::class)->path($workspace, $id);
        }
        $config['adjustments'] = \App\Services\Studio\PhotoPresetOptions::normalize($preset, $config['adjustments']);
        $ids = array_column($media, 'id');
        foreach ($config['frames'] as $frame) {
            if (! in_array($frame['mediaId'], $ids, true)) {
                throw ValidationException::withMessages(['config.frames' => 'Every frame must reference a selected image.']);
            }
        }

        return StudioWorkspace::normalizeConfigStrings($config);
    }
}
