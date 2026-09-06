<?php

namespace App\Http\Controllers\API;

use App\Jobs\ProcessStudioWorkspace;
use App\Models\AiReelJob;
use App\Models\StudioWorkspace;
use App\Services\Studio\WorkspaceMediaService;
use App\Services\Studio\WorkspaceProcessor;
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

    private const PRESETS = ['listing-ready', 'color-correction', 'twilight', 'green-grass', 'virtual-staging', 'full-shoot', 'walkthrough', 'property-reel', 'social-teaser'];

    public function __construct(private WorkspaceMediaService $mediaService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'view');

        return response()->json(['success' => true, 'data' => $this->scopeStudioQuery(StudioWorkspace::query(), $request->user())->latest('updated_at')->limit(100)->get()->map->present()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'create');
        $requestId = $request->header('Idempotency-Key', $request->input('requestId'));
        if ($requestId && strlen($requestId) > 64) {
            throw ValidationException::withMessages(['requestId' => 'Use an idempotency key of at most 64 characters.']);
        }
        if ($requestId && ($existing = StudioWorkspace::where('created_by', $request->user()->id)->where('request_id', $requestId)->first())) {
            return $this->respond($existing);
        }
        $data = $this->validated($request);
        $media = $this->mediaService->authorize($data['media'], $request->user(), $this->scopeTeamId($request->user()));
        try {
            $workspace = StudioWorkspace::create([
                'team_id' => $this->scopeTeamId($request->user()), 'created_by' => $request->user()->id,
                'request_id' => $requestId, 'name' => $data['name'], 'preset_id' => $data['presetId'],
                'media' => $media, 'config' => $this->config($data['config'] ?? [], $media), 'status' => 'draft',
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            $existing = $requestId ? StudioWorkspace::where('created_by', $request->user()->id)->where('request_id', $requestId)->first() : null;
            if ($existing) {
                return $this->respond($existing);
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

        return $disk->download($path, basename($path), ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, string $workspace): JsonResponse
    {
        $record = $this->find($request, $workspace);
        $data = $this->validated($request, true);
        $media = isset($data['media']) ? $this->mediaService->authorize($data['media'], $request->user(), $record->team_id) : $record->media;

        return DB::transaction(function () use ($record, $data, $media): JsonResponse {
            $record = StudioWorkspace::lockForUpdate()->findOrFail($record->id);
            abort_if($record->isBusy(), 409, 'Wait for this operation to finish or cancel it before editing the draft.');
            abort_if(isset($data['version']) && $data['version'] !== $record->version, 409, 'The workspace changed. Reload before saving.');
            $next = array_replace_recursive($record->config, $data['config'] ?? []);
            if (array_key_exists('frames', $data['config'] ?? [])) {
                $next['frames'] = $data['config']['frames'];
            }
            if (array_key_exists('adjustments', $data['config'] ?? [])) {
                $next['adjustments'] = $data['config']['adjustments'];
            }
            $config = $this->config($next, $media);
            $preparationChanged = $record->media !== $media || ($record->config['ratio'] ?? null) !== $config['ratio']
                || collect($record->config['frames'] ?? [])->mapWithKeys(fn ($f) => [$f['mediaId'] => $f['method']])->sortKeys()->all() !== collect($config['frames'])->mapWithKeys(fn ($f) => [$f['mediaId'] => $f['method']])->sortKeys()->all();
            $reviewFields = ['reviewedOutputIds', 'reviewedFrameIds'];
            $changed = $record->media !== $media || \Illuminate\Support\Arr::except($record->config, $reviewFields) !== \Illuminate\Support\Arr::except($config, $reviewFields) || (isset($data['presetId']) && $record->preset_id !== $data['presetId']);
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
        abort_unless(filled(config('services.openai.api_key')), 503, 'Object detection requires the configured OpenAI service.');

        try {
            return response()->json(['success' => true, 'data' => $processor->segments($record, $data['mediaId'])]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => 'Object detection could not finish. Please try again.'], 503);
        }
    }

    private function start(Request $request, string $workspace, string $type): JsonResponse
    {
        $record = $this->find($request, $workspace);
        $this->mediaService->authorize($record->media, $request->user(), $record->team_id);
        $payload = $request->validate([
            'requestId' => ['sometimes', 'string', 'max:64'], 'mediaId' => [$type === 'revision' ? 'required' : 'sometimes', 'string'],
            'prompt' => [$type === 'revision' ? 'required' : 'sometimes', 'string', 'max:4000'],
            'region' => ['nullable', 'array:x,y,width,height'],
            'region.x' => ['required_with:region', 'numeric', 'between:0,1'], 'region.y' => ['required_with:region', 'numeric', 'between:0,1'],
            'region.width' => ['required_with:region', 'numeric', 'gt:0', 'max:1'], 'region.height' => ['required_with:region', 'numeric', 'gt:0', 'max:1'],
            'drawing' => ['nullable', 'array', 'max:20'], 'drawing.*' => ['array', 'max:200'], 'drawing.*.*' => ['array:x,y'],
            'drawing.*.*.x' => ['required', 'numeric', 'between:0,1'], 'drawing.*.*.y' => ['required', 'numeric', 'between:0,1'],
        ]);
        if (isset($payload['region']) && ($payload['region']['x'] + $payload['region']['width'] > 1.00001 || $payload['region']['y'] + $payload['region']['height'] > 1.00001)) {
            throw ValidationException::withMessages(['region' => 'The selected region must fit inside the image.']);
        }
        if ($type === 'revision' && ! collect($record->media)->contains('id', $payload['mediaId'])) {
            throw ValidationException::withMessages(['mediaId' => 'Select an image from this workspace.']);
        }
        $key = $request->header('Idempotency-Key', $payload['requestId'] ?? null);
        if ($key && strlen($key) > 64) {
            throw ValidationException::withMessages(['requestId' => 'Use an idempotency key of at most 64 characters.']);
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
            if ($record->isVideo() && $type === 'generate') {
                abort_if($record->preset_id === 'walkthrough' && ! filled(config('services.fal.walkthrough_model')), 503, 'The start/end-frame walkthrough model is not configured.');
                $frameIds = array_column($record->config['frames'] ?? [], 'mediaId');
                if (! $frameIds) {
                    $frameIds = array_column($record->media, 'id');
                }
                $prepared = collect($record->prepared_frames ?? [])->where('status', 'completed')->keyBy('mediaId');
                abort_unless(collect($frameIds)->every(fn ($id) => $prepared->has($id)), 422, 'Prepare every selected frame before generating a reel.');
                abort_if(count($frameIds) > 12, 422, 'A reel supports at most 12 selected source photos.');
            }
            $operation = $same && $record->status === 'failed' ? $old : ['id' => (string) Str::uuid(), 'key' => $key, 'type' => $type, 'payload' => $payload, 'completed' => [], 'requests' => []];
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
        $record = StudioWorkspace::findOrFail($id);
        $this->authorizeStudioAction($request->user(), 'view', $record);

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
        ]);
    }

    private function config(array $config, array $media): array
    {
        // Runtime state and file references are exclusively server-owned.
        $config = \Illuminate\Support\Arr::only($config, ['prompt', 'ratio', 'duration', 'transition', 'transitionDuration', 'text', 'adjustments', 'frames', 'reviewedOutputIds', 'reviewedFrameIds']);
        $config = array_replace_recursive(['prompt' => '', 'ratio' => '9:16', 'duration' => 30, 'transition' => 'none', 'transitionDuration' => 0.4, 'text' => ['title' => '', 'subtitle' => '', 'style' => 'none', 'position' => 'bottom'], 'adjustments' => [], 'frames' => []], $config);
        $ids = array_column($media, 'id');
        foreach ($config['frames'] as $frame) {
            if (! in_array($frame['mediaId'], $ids, true)) {
                throw ValidationException::withMessages(['config.frames' => 'Every frame must reference a selected image.']);
            }
        }

        return StudioWorkspace::normalizeConfigStrings($config);
    }
}
