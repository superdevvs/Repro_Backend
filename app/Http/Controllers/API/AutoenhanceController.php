<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAutoenhanceEditingJob;
use App\Jobs\ProcessFalEditingJob;
use App\Models\AiEditingJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\AutoenhanceService;
use App\Services\FalService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AutoenhanceController extends Controller
{
    public function __construct(
        private AutoenhanceService $autoenhanceService,
        private FalService $falService,
        private ShootFileAccessService $shootFileAccessService,
    ) {
    }

    public function connectionStatus(Request $request)
    {
        $provider = $this->providerFromRequest($request);

        try {
            return response()->json([
                'success' => true,
                'data' => $provider === 'fal'
                    ? $this->falService->testConnection()
                    : $this->autoenhanceService->testConnection(),
            ]);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'warning');

            return response()->json([
                'success' => false,
                'data' => [
                    'success' => false,
                    'status' => 503,
                    'provider' => $provider,
                    'message' => 'AI editing provider status unavailable',
                ],
            ]);
        }
    }

    public function getEditingTypes(Request $request)
    {
        $provider = $this->providerFromRequest($request);

        try {
            return response()->json([
                'success' => true,
                'provider' => $provider,
                'data' => $provider === 'fal'
                    ? $this->falService->getImageEditingTypes()
                    : $this->autoenhanceService->getEditingTypes(),
            ]);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'success' => true,
                'provider' => $provider,
                'data' => $provider === 'fal'
                    ? $this->falService->getImageEditingTypes()
                    : $this->autoenhanceService->getDefaultEditingTypes(),
                'message' => 'Using default AI editing types',
            ]);
        }
    }

    public function submitEditing(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shoot_id' => 'required|exists:shoots,id',
            'file_ids' => 'required|array|min:1|max:100',
            'file_ids.*' => 'required|exists:shoot_files,id',
            'editing_type' => 'required|string',
            'provider' => 'nullable|string|in:autoenhance,fal',
            'params' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $provider = $this->providerFromRequest($request);
            $shoot = Shoot::findOrFail($request->shoot_id);
            $user = $request->user();
            if (!$this->canEditShoot($user, $shoot)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to edit this shoot',
                ], 403);
            }

            $jobs = collect();
            $skipped = [];
            foreach ($request->file_ids as $fileId) {
                $shootFile = ShootFile::find($fileId);
                if (!$shootFile) {
                    $skipped[] = ['file_id' => $fileId, 'reason' => 'File not found'];
                    continue;
                }
                if ((int) $shootFile->shoot_id !== (int) $shoot->id) {
                    $skipped[] = ['file_id' => $fileId, 'reason' => 'File does not belong to shoot'];
                    continue;
                }

                $imageUrl = $this->getImageUrl($shootFile);
                if (!$imageUrl && $provider === 'autoenhance') {
                    Log::warning('AutoenhanceController: Could not get image URL', [
                        'file_id' => $fileId,
                    ]);
                    $skipped[] = ['file_id' => $fileId, 'reason' => 'Source image URL unavailable'];
                    continue;
                }

                $editingJob = AiEditingJob::create([
                    'shoot_id' => $shoot->id,
                    'shoot_file_id' => $shootFile->id,
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'status' => AiEditingJob::STATUS_PENDING,
                    'editing_type' => $request->editing_type,
                    'editing_params' => $request->params ?? [],
                    'original_image_url' => $imageUrl ?: ('shoot-file:' . $shootFile->id),
                ]);

                $provider === 'fal'
                    ? ProcessFalEditingJob::dispatchAfterResponse($editingJob)
                    : ProcessAutoenhanceEditingJob::dispatchAfterResponse($editingJob);
                $jobs->push($editingJob);
            }

            if ($jobs->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid files could be queued for AI editing',
                    'skipped' => $skipped,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'AI editing jobs submitted successfully',
                'provider' => $provider,
                'data' => $jobs->map(fn (AiEditingJob $job) => $this->presentJob($job))->values(),
                'skipped' => $skipped,
            ], 201);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit AI editing job',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    /**
     * Submit a set of shoot files as Autoenhance HDR brackets.
     *
     * Accepts file_ids in the user's intended bracket order. Files are split
     * into chunks of `bracket_size` (3 or 5). Each chunk is uploaded sharing a
     * single `bracket_id` UUID and the same Autoenhance `order_id`, which tells
     * Autoenhance to merge the bracket into a single HDR enhanced output.
     *
     * One AiEditingJob is created per bracket — its `autoenhance_image_id` is
     * the primary (first) image in the bracket; secondary image_ids are tracked
     * in `provider_payload.bracket_image_ids` for reference.
     */
    public function submitBracketEditing(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shoot_id' => 'required|exists:shoots,id',
            'file_ids' => 'required|array|min:3',
            'file_ids.*' => 'required|exists:shoot_files,id',
            'bracket_size' => 'required|integer|in:3,5',
            'editing_type' => 'required|string',
            'params' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $bracketSize = (int) $request->bracket_size;
        $fileIds = array_values($request->file_ids);
        if (count($fileIds) % $bracketSize !== 0) {
            return response()->json([
                'success' => false,
                'message' => "Selected file count must be a multiple of {$bracketSize} for HDR brackets",
            ], 422);
        }

        try {
            $shoot = Shoot::findOrFail($request->shoot_id);
            $user = $request->user();
            if (!$this->canEditShoot($user, $shoot)) {
                return response()->json(['success' => false, 'message' => 'You do not have permission to edit this shoot'], 403);
            }

            $editingType = (string) $request->editing_type;
            $rawParams = $request->params ?? [];
            $jobs = [];
            $skipped = [];

            foreach (array_chunk($fileIds, $bracketSize) as $bracketIndex => $bracketFileIds) {
                $bracketId = (string) Str::uuid();
                $orderId = null;
                $bracketImageIds = [];
                $primaryShootFile = null;
                $bracketEditingJob = null;
                $bracketFailed = null;

                foreach ($bracketFileIds as $position => $fileId) {
                    $shootFile = ShootFile::find($fileId);
                    if (!$shootFile || (int) $shootFile->shoot_id !== (int) $shoot->id) {
                        $skipped[] = ['file_id' => $fileId, 'reason' => 'File not found or does not belong to shoot'];
                        $bracketFailed = "File #{$fileId} unavailable";
                        break;
                    }

                    [$contents, $contentType] = $this->readShootFileBuffer($shootFile);
                    if (!$contents) {
                        $skipped[] = ['file_id' => $fileId, 'reason' => 'Could not read file binary'];
                        $bracketFailed = "Could not read file #{$fileId}";
                        break;
                    }

                    if ($position === 0) {
                        $primaryShootFile = $shootFile;
                        $bracketEditingJob = AiEditingJob::create([
                            'shoot_id' => $shoot->id,
                            'shoot_file_id' => $shootFile->id,
                            'user_id' => $user->id,
                            'provider' => 'autoenhance',
                            'status' => AiEditingJob::STATUS_PENDING,
                            'editing_type' => $editingType,
                            'editing_params' => array_merge($rawParams, [
                                'bracket_id' => $bracketId,
                                'bracket_size' => $bracketSize,
                                'bracket_index' => $bracketIndex,
                            ]),
                            'original_image_url' => $this->getImageUrl($shootFile),
                        ]);
                    }

                    $providerParams = array_merge($rawParams, [
                        'image_name' => $shootFile->filename,
                        'content_type' => $contentType,
                        'mime_type' => $contentType,
                        'bracket_id' => $bracketId,
                    ]);
                    if ($orderId) {
                        $providerParams['order_id'] = $orderId;
                    }

                    $result = $this->autoenhanceService->submitEditingJobFromBuffer(
                        $contents,
                        $shootFile->filename ?? "bracket-{$bracketIndex}-{$position}.jpg",
                        $contentType,
                        $editingType,
                        $providerParams
                    );

                    if (!is_array($result) || !($result['image_id'] ?? null)) {
                        $errorMessage = is_array($result)
                            ? ($result['error'] ?? 'Autoenhance submission failed')
                            : 'Autoenhance submission failed';
                        $bracketFailed = is_string($errorMessage) ? $errorMessage : 'Autoenhance submission failed';
                        if ($bracketEditingJob && is_array($result)) {
                            $bracketEditingJob->provider_payload = $result;
                            $bracketEditingJob->save();
                        }
                        break;
                    }

                    $bracketImageIds[] = $result['image_id'];
                    $orderId = $orderId ?: ($result['order_id'] ?? null);

                    if ($position === 0 && $bracketEditingJob) {
                        $bracketEditingJob->autoenhance_image_id = $result['image_id'];
                        $bracketEditingJob->provider_job_id = $result['image_id'];
                        $bracketEditingJob->provider_order_id = $orderId;
                        $bracketEditingJob->status = AiEditingJob::STATUS_PROCESSING;
                        $bracketEditingJob->started_at = now();
                        $bracketEditingJob->save();
                    }
                }

                if ($bracketEditingJob) {
                    if ($bracketFailed) {
                        $bracketEditingJob->markAsFailed($bracketFailed);
                    } else {
                        $bracketEditingJob->provider_payload = array_merge((array) ($bracketEditingJob->provider_payload ?? []), [
                            'bracket_id' => $bracketId,
                            'bracket_size' => $bracketSize,
                            'bracket_index' => $bracketIndex,
                            'bracket_image_ids' => $bracketImageIds,
                            'bracket_file_ids' => $bracketFileIds,
                        ]);
                        $bracketEditingJob->save();
                    }
                    $jobs[] = $bracketEditingJob;
                }
            }

            if (empty($jobs)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No bracket jobs could be queued',
                    'skipped' => $skipped,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => count($jobs) . ' bracket job(s) submitted',
                'data' => collect($jobs)->map(fn (AiEditingJob $job) => $this->presentJob(
                    $job->load(['shoot:id,address,city,state,zip', 'shootFile', 'user:id,name,email'])
                ))->values(),
                'skipped' => $skipped,
            ], 201);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit bracket job',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    /**
     * Read a ShootFile's binary contents from local storage. Returns
     * [contents, contentType] or [null, null] if unreadable.
     */
    private function readShootFileBuffer(ShootFile $shootFile): array
    {
        $contentType = $shootFile->mime_type ?? $shootFile->file_type ?? null;
        $candidates = array_filter([
            $shootFile->storage_path,
            $shootFile->path,
        ]);
        foreach ($candidates as $candidate) {
            $relative = ltrim(preg_replace('#^/?storage/#', '', (string) $candidate), '/');
            if ($relative && Storage::disk('public')->exists($relative)) {
                return [Storage::disk('public')->get($relative), $contentType];
            }
        }
        // Fallback: try fetching via signed URL accessor (for remote storage).
        $url = $this->getImageUrl($shootFile);
        if ($url) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(120)->get($url);
                if ($response->successful()) {
                    return [$response->body(), $contentType ?: $response->header('Content-Type')];
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return [null, null];
    }

    /**
     * Stage uploaded images for chat-driven Autoenhance flows.
     *
     * The user attaches images in chat → frontend POSTs them here → we persist
     * them to the user's staging directory and return ids the chat flow can use
     * to commit them to Autoenhance later (after Robbie collects mode + params).
     */
    public function stageQuickEdit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'images' => 'required|array|min:1|max:50',
            'images.*' => 'required|file|image|max:25600', // 25 MB per image
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $files = $request->file('images', []);
        if (!is_array($files)) {
            $files = [$files];
        }

        $staged = [];
        $skipped = [];

        foreach ($files as $index => $upload) {
            if (!$upload || !$upload->isValid()) {
                $skipped[] = ['index' => $index, 'reason' => 'Upload was not valid'];
                continue;
            }

            try {
                $originalName = $upload->getClientOriginalName() ?: ('image-' . ($index + 1) . '.jpg');
                $contentType = $upload->getMimeType() ?: 'image/jpeg';
                $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg';
                $stagedId = Str::uuid()->toString();
                $storedPath = "autoenhance-uploads/{$user->id}/staging/{$stagedId}.{$extension}";

                $contents = file_get_contents($upload->getRealPath());
                if ($contents === false) {
                    $skipped[] = ['index' => $index, 'reason' => 'Could not read upload contents'];
                    continue;
                }

                Storage::disk('public')->put($storedPath, $contents, 'public');

                $previewUrl = null;
                try {
                    $previewUrl = Storage::disk('public')->url($storedPath);
                } catch (\Throwable $urlError) {
                    $previewUrl = rtrim((string) config('app.url'), '/') . '/storage/' . ltrim($storedPath, '/');
                }

                $staged[] = [
                    'id' => $stagedId,
                    'name' => $originalName,
                    'content_type' => $contentType,
                    'size' => strlen($contents),
                    'preview_url' => $previewUrl,
                    'storage_path' => $storedPath,
                ];
            } catch (\Throwable $e) {
                \App\Services\ApiErrorResponder::log($e, 'warning');
                $skipped[] = ['index' => $index, 'reason' => \App\Services\ApiErrorResponder::publicMessage($e)];
            }
        }

        if (empty($staged)) {
            return response()->json([
                'success' => false,
                'message' => 'No images could be staged',
                'skipped' => $skipped,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'staged' => $staged,
            'skipped' => $skipped,
        ], 201);
    }

    /**
     * Quick edit endpoint: accept ad-hoc image uploads (multipart) directly from chat /
     * AI editing UI, submit each one individually to Autoenhance using a binary buffer
     * (so we don't need a publicly-reachable URL during local development), and create a
     * tracked AiEditingJob row per image. The job appears in the existing Activity list
     * and can be polled / retried via the same endpoints.
     *
     * Accepts either:
     *   images[]      — direct multipart files (legacy / one-shot path)
     *   staged_ids[]  — ids returned from stageQuickEdit (chat flow path)
     */
    public function quickEdit(Request $request)
    {
        $hasStaged = $request->has('staged_ids');
        $validator = Validator::make($request->all(), [
            'images' => $hasStaged ? 'nullable|array' : 'required|array|min:1|max:25',
            'images.*' => 'nullable|file|image|max:25600', // 25 MB per image
            'staged_ids' => 'nullable|array|min:1|max:50',
            'staged_ids.*' => 'nullable|string',
            'editing_type' => 'nullable|string|in:enhance,sky_replace,vertical_correction,window_pull,hdr_merge',
            'provider' => 'nullable|string|in:autoenhance,fal',
            'params' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $provider = $this->providerFromRequest($request);
        $editingType = $request->input('editing_type', 'enhance');
        $rawParams = $request->input('params');
        if (is_string($rawParams)) {
            $decoded = json_decode($rawParams, true);
            $rawParams = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($rawParams)) {
            $rawParams = [];
        }

        // Build a unified list of {name, contentType, contents, publicUrl} sources from
        // either direct multipart uploads or previously-staged ids.
        /** @var array<int, array{name:string, contentType:string, contents:string, publicUrl:?string}> $sources */
        $sources = [];
        $skipped = [];

        if ($hasStaged) {
            $stagedIds = array_filter(array_map('strval', (array) $request->input('staged_ids', [])));
            foreach ($stagedIds as $idx => $stagedId) {
                $dir = "autoenhance-uploads/{$user->id}/staging";
                $matches = collect(Storage::disk('public')->files($dir))
                    ->filter(fn ($p) => str_starts_with(basename($p), $stagedId . '.'))
                    ->values();
                if ($matches->isEmpty()) {
                    $skipped[] = ['staged_id' => $stagedId, 'reason' => 'Staged file not found'];
                    continue;
                }
                $storedPath = $matches->first();
                try {
                    $contents = Storage::disk('public')->get($storedPath);
                } catch (\Throwable $readError) {
                    $skipped[] = ['staged_id' => $stagedId, 'reason' => \App\Services\ApiErrorResponder::publicMessage($readError)];
                    continue;
                }
                $name = basename($storedPath);
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg');
                $contentType = $extension === 'png' ? 'image/png'
                    : ($extension === 'webp' ? 'image/webp'
                    : ($extension === 'avif' ? 'image/avif' : 'image/jpeg'));
                $publicUrl = null;
                try { $publicUrl = Storage::disk('public')->url($storedPath); } catch (\Throwable $e) {}
                $sources[] = [
                    'name' => $name,
                    'content_type' => $contentType,
                    'contents' => (string) $contents,
                    'public_url' => $publicUrl,
                ];
            }
        } else {
            $files = $request->file('images', []);
            if (!is_array($files)) {
                $files = [$files];
            }
            foreach ($files as $index => $upload) {
                if (!$upload || !$upload->isValid()) {
                    $skipped[] = ['index' => $index, 'reason' => 'Upload was not valid'];
                    continue;
                }
                $originalName = $upload->getClientOriginalName() ?: ('image-' . ($index + 1) . '.jpg');
                $contentType = $upload->getMimeType() ?: 'image/jpeg';
                $contents = file_get_contents($upload->getRealPath());
                if ($contents === false || $contents === '') {
                    $skipped[] = ['index' => $index, 'name' => $originalName, 'reason' => 'Could not read file contents'];
                    continue;
                }
                // Persist a copy so we can show it later in Activity.
                $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg';
                $storedName = Str::uuid()->toString() . '.' . strtolower($extension);
                $storedPath = "autoenhance-uploads/{$user->id}/{$storedName}";
                try {
                    Storage::disk('public')->put($storedPath, $contents, 'public');
                } catch (\Throwable $storageError) {
                    \App\Services\ApiErrorResponder::log($storageError, 'warning');
                }
                $publicUrl = null;
                try { $publicUrl = Storage::disk('public')->url($storedPath); } catch (\Throwable $e) {
                    $publicUrl = rtrim((string) config('app.url'), '/') . '/storage/' . ltrim($storedPath, '/');
                }
                $sources[] = [
                    'name' => $originalName,
                    'content_type' => $contentType,
                    'contents' => $contents,
                    'public_url' => $publicUrl,
                ];
            }
        }

        $jobs = [];

        foreach ($sources as $index => $src) {
            $originalName = $src['name'];
            $contentType = $src['content_type'];
            $contents = $src['contents'];
            $publicUrl = $src['public_url'] ?? null;

            try {
                $editingJob = AiEditingJob::create([
                    'shoot_id' => null,
                    'shoot_file_id' => null,
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'status' => AiEditingJob::STATUS_PENDING,
                    'editing_type' => $editingType,
                    'editing_params' => $rawParams,
                    'original_image_url' => $publicUrl,
                ]);

                $providerParams = array_merge($rawParams, [
                    'image_name' => $originalName,
                    'content_type' => $contentType,
                    'mime_type' => $contentType,
                ]);

                $result = $provider === 'fal'
                    ? $this->falService->submitImageEditFromBuffer(
                        $contents,
                        $originalName,
                        $contentType,
                        $editingType,
                        $providerParams
                    )
                    : $this->autoenhanceService->submitEditingJobFromBuffer(
                        $contents,
                        $originalName,
                        $contentType,
                        $editingType,
                        $providerParams
                    );

                $providerJobId = is_array($result)
                    ? ($provider === 'fal'
                        ? ($result['request_id'] ?? $result['job_id'] ?? null)
                        : ($result['image_id'] ?? null))
                    : null;

                if (!is_array($result) || !$providerJobId) {
                    $errorMessage = is_array($result)
                        ? ($result['error'] ?? 'AI editing submission failed')
                        : 'AI editing submission failed';
                    $editingJob->markAsFailed(is_string($errorMessage) ? $errorMessage : 'AI editing submission failed');
                    if (is_array($result)) {
                        $editingJob->provider_payload = $result;
                        $editingJob->save();
                    }
                    $skipped[] = ['index' => $index, 'name' => $originalName, 'reason' => 'AI editing submission failed. Please try again.'];
                    continue;
                }

                if ($provider === 'autoenhance') {
                    $editingJob->autoenhance_image_id = $providerJobId;
                    $editingJob->provider_order_id = $result['order_id'] ?? null;
                }
                $editingJob->provider_job_id = (string) $providerJobId;
                $editingJob->provider_payload = $result['data'] ?? null;
                $editingJob->status = AiEditingJob::STATUS_PROCESSING;
                $editingJob->started_at = now();
                $editingJob->save();

                $jobs[] = $editingJob;
            } catch (\Throwable $e) {
                \App\Services\ApiErrorResponder::log($e, 'error');
                $skipped[] = [
                    'index' => $index,
                    'name' => $originalName ?? null,
                    'reason' => \App\Services\ApiErrorResponder::publicMessage($e),
                ];
            }
        }

        if (empty($jobs) && !empty($skipped)) {
            return response()->json([
                'success' => false,
                'message' => 'No images could be submitted to AI editing',
                'skipped' => $skipped,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quick-edit jobs submitted',
            'provider' => $provider,
            'data' => collect($jobs)->map(fn (AiEditingJob $job) => $this->presentJob(
                $job->load(['shoot:id,address,city,state,zip', 'shootFile', 'user:id,name,email'])
            ))->values(),
            'skipped' => $skipped,
        ], 201);
    }

    /**
     * Synchronously poll every `processing` job the user owns. For each:
     *   - call Autoenhance for current status
     *   - if completed, download the enhanced image, persist it on the public disk,
     *     and mark the AiEditingJob as completed (so the Activity card flips state)
     *   - if failed/cancelled, mark accordingly.
     *
     * This is the local-dev driver for AI editing progression where webhooks
     * aren't reachable. Frontend polls this endpoint while any job is in
     * `processing` state.
     */
    public function pollProcessingJobs(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = AiEditingJob::whereIn('provider', ['autoenhance', 'fal'])
            ->where('status', AiEditingJob::STATUS_PROCESSING)
            ->where(function ($query) {
                $query->whereNotNull('autoenhance_image_id')
                    ->orWhereNotNull('provider_job_id');
            });
        if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            $query->where('user_id', $user->id);
        }
        $jobs = $query->orderByDesc('id')->limit(50)->get();

        $updated = [];
        foreach ($jobs as $job) {
            try {
                $status = $job->provider === 'fal'
                    ? $this->falService->imageEditStatus((string) $job->provider_job_id)
                    : $this->autoenhanceService->getJobStatus($job->autoenhance_image_id);
                if (!$status) continue;

                $normalized = strtolower((string) ($status['status'] ?? 'processing'));
                $job->provider_result = $status;

                if ($normalized === 'completed') {
                    if ($job->provider === 'fal') {
                        $result = $this->falService->imageEditResult((string) $job->provider_job_id);
                        $editedImageUrl = $result['edited_image_url'] ?? $result['image_url'] ?? null;
                        $job->provider_result = array_merge($job->provider_result ?? [], [
                            'result' => $result,
                        ]);
                    } else {
                        $editedImageUrl = $status['enhanced_image_url']
                            ?? $status['result_url']
                            ?? $status['image_url']
                            ?? $status['edited_image_url']
                            ?? null;
                        if (!$editedImageUrl) {
                            $editedImageUrl = $this->autoenhanceService->downloadEditedImage($job->autoenhance_image_id);
                        }
                    }
                    if (!$editedImageUrl) {
                        $job->markAsFailed('Enhanced image URL not found in AI editing provider response');
                        $updated[] = $job->id;
                        continue;
                    }

                    $stored = $this->persistEnhancedImageForJob($job, $editedImageUrl);
                    $job->markAsCompleted($stored ?: $editedImageUrl);
                    $updated[] = $job->id;
                } elseif (in_array($normalized, ['failed', 'error', 'cancelled', 'rejected'], true)) {
                    $errorMessage = $status['error'] ?? $status['message'] ?? $status['status_reason'] ?? 'Autoenhance job failed';
                    $job->markAsFailed((string) $errorMessage);
                    $updated[] = $job->id;
                } else {
                    // Still processing — just persist provider_result snapshot.
                    $job->save();
                }
            } catch (\Throwable $e) {
                \App\Services\ApiErrorResponder::log($e, 'warning');
            }
        }

        return response()->json([
            'success' => true,
            'polled' => $jobs->count(),
            'updated' => $updated,
        ]);
    }

    /**
     * Download the enhanced image and persist it on the public disk. Also creates
     * a ShootFile row when the job is tied to a shoot. For ad-hoc / quick-edit
     * jobs (shoot_id null) we just return the public URL of the stored copy.
     */
    private function persistEnhancedImageForJob(AiEditingJob $job, string $editedImageUrl): ?string
    {
        try {
            $binary = null;
            $mimeType = 'image/jpeg';
            if (str_starts_with($editedImageUrl, 'data:')) {
                [$meta, $payload] = explode(',', $editedImageUrl, 2);
                if (preg_match('/^data:([^;]+)/', $meta, $m)) {
                    $mimeType = $m[1];
                }
                $binary = base64_decode($payload);
            } else {
                $response = \Illuminate\Support\Facades\Http::timeout(120)->get($editedImageUrl);
                if (!$response->successful()) {
                    return null;
                }
                $binary = $response->body();
                $mimeType = $response->header('Content-Type', $mimeType);
            }

            if (!$binary) return null;

            $extension = match (strtolower($mimeType)) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $provider = $job->provider ?: 'autoenhance';
            $baseName = $job->shoot_id
                ? ('shoots/' . $job->shoot_id . '/' . $provider)
                : ('ai-editing-uploads/' . $job->user_id . '/edited');
            $filename = Str::slug($provider . '-' . $job->id) . '.' . $extension;
            $path = $baseName . '/' . $filename;
            Storage::disk('public')->put($path, $binary, 'public');
            $publicPath = 'storage/' . $path;

            // Tie back as a ShootFile only when the job belongs to a shoot.
            if ($job->shoot_id) {
                ShootFile::create([
                    'shoot_id' => $job->shoot_id,
                    'filename' => $filename,
                    'stored_filename' => $filename,
                    'path' => $publicPath,
                    'storage_path' => $publicPath,
                    'file_type' => $mimeType,
                    'mime_type' => $mimeType,
                    'media_type' => 'edited',
                    'file_size' => strlen($binary),
                    'uploaded_by' => $job->user_id,
                    'uploaded_at' => now(),
                    'workflow_stage' => ShootFile::STAGE_COMPLETED,
                    'ai_editing_job_id' => $job->id,
                    'is_ai_edited' => true,
                    'ai_editing_metadata' => [
                        'provider' => $provider,
                        'editing_type' => $job->editing_type,
                        'completed_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            return $publicPath;
        } catch (\Throwable $e) {
            \App\Services\ApiErrorResponder::log($e, 'warning');
            return null;
        }
    }

    public function listJobs(Request $request)
    {
        try {
            $user = $request->user();
            $query = AiEditingJob::with(['shoot:id,address,city,state,zip', 'shootFile', 'user:id,name,email'])
                ->whereIn('provider', ['autoenhance', 'fal']);

            if ($request->has('shoot_id')) {
                $query->where('shoot_id', $request->shoot_id);
            }
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            if ($request->has('provider')) {
                $query->where('provider', $this->providerFromRequest($request));
            }
            if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
                $query->where('user_id', $user->id);
            }

            $jobs = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => collect($jobs->items())->map(fn (AiEditingJob $job) => $this->presentJob($job))->values(),
                'meta' => [
                    'current_page' => $jobs->currentPage(),
                    'last_page' => $jobs->lastPage(),
                    'per_page' => $jobs->perPage(),
                    'total' => $jobs->total(),
                ],
            ]);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve AI editing jobs',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function getJobStatus($jobId)
    {
        try {
            $job = AiEditingJob::with(['shoot:id,address,city,state,zip', 'shootFile', 'user:id,name,email'])->findOrFail($jobId);
            $user = request()->user();
            if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this job',
                ], 403);
            }

            if ($job->isProcessing() && $job->provider === 'fal' && $job->provider_job_id) {
                $status = $this->falService->imageEditStatus((string) $job->provider_job_id);
                if (($status['status'] ?? null) === 'completed') {
                    ProcessFalEditingJob::dispatchAfterResponse($job);
                }
            } elseif ($job->isProcessing() && $job->autoenhance_image_id) {
                $status = $this->autoenhanceService->getJobStatus($job->autoenhance_image_id);
                if (($status['status'] ?? null) === 'completed') {
                    ProcessAutoenhanceEditingJob::dispatchAfterResponse($job);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $this->presentJob($job),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Autoenhance job status',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function retryJob($jobId)
    {
        try {
            $job = AiEditingJob::with(['shoot', 'shootFile', 'user'])->findOrFail($jobId);
            $user = request()->user();
            if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to retry this job',
                ], 403);
            }

            if (!in_array($job->status, [AiEditingJob::STATUS_FAILED, AiEditingJob::STATUS_CANCELLED], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only failed or cancelled jobs can be retried',
                ], 400);
            }

            // Fast-path: if Autoenhance already has the image and it's processed,
            // just re-fetch the result instead of re-submitting from scratch. This
            // covers the "Enhanced image URL not found in Autoenhance response"
            // failure mode where the upload succeeded but the local download didn't.
            $provider = $job->provider ?: $this->defaultProvider();

            if ($provider === 'autoenhance' && $job->autoenhance_image_id) {
                $status = $this->autoenhanceService->getJobStatus($job->autoenhance_image_id);
                $normalized = strtolower((string) ($status['status'] ?? ''));
                if ($status && $normalized === 'completed') {
                    $editedImageUrl = $status['enhanced_image_url']
                        ?? $status['result_url']
                        ?? $status['image_url']
                        ?? $status['edited_image_url']
                        ?? null;
                    if (!$editedImageUrl) {
                        $editedImageUrl = $this->autoenhanceService->downloadEditedImage($job->autoenhance_image_id);
                    }
                    if ($editedImageUrl) {
                        $stored = $this->persistEnhancedImageForJob($job, $editedImageUrl);
                        $job->error_message = null;
                        $job->markAsCompleted($stored ?: $editedImageUrl);
                        return response()->json([
                            'success' => true,
                            'message' => 'AI editing result downloaded',
                            'data' => $this->presentJob($job->refresh()->load(['shoot:id,address,city,state,zip', 'shootFile', 'user:id,name,email'])),
                        ]);
                    }
                }
            }

            if ($job->shootFile) {
                $resolvedUrl = $this->getImageUrl($job->shootFile);
                if ($resolvedUrl) {
                    $job->original_image_url = $resolvedUrl;
                }
            }
            $job->status = AiEditingJob::STATUS_PENDING;
            $job->error_message = null;
            $job->edited_image_url = null;
            $job->autoenhance_image_id = null;
            $job->provider_job_id = null;
            $job->provider = $provider;
            $job->started_at = null;
            $job->completed_at = null;
            $job->retry_count = 0;
            $job->save();

            $provider === 'fal'
                ? ProcessFalEditingJob::dispatchAfterResponse($job)
                : ProcessAutoenhanceEditingJob::dispatchAfterResponse($job);

            return response()->json([
                'success' => true,
                'message' => 'AI editing job re-queued for processing',
                'data' => $this->presentJob($job->refresh()->load(['shoot:id,address,city,state,zip', 'shootFile', 'user:id,name,email'])),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'success' => false,
                'message' => 'Failed to retry Autoenhance job',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function cancelJob($jobId)
    {
        try {
            $job = AiEditingJob::findOrFail($jobId);
            $user = request()->user();
            if (!in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to cancel this job',
                ], 403);
            }

            if (!in_array($job->status, [AiEditingJob::STATUS_PENDING, AiEditingJob::STATUS_PROCESSING], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending or processing jobs can be cancelled',
                ], 400);
            }

            if ($job->provider === 'autoenhance' && $job->autoenhance_image_id) {
                $this->autoenhanceService->cancelJob($job->autoenhance_image_id);
            }

            $job->status = AiEditingJob::STATUS_CANCELLED;
            $job->save();

            return response()->json([
                'success' => true,
                'message' => 'AI editing job cancelled successfully',
                'data' => $this->presentJob($job),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel Autoenhance job',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function handleWebhook(Request $request)
    {
        $configuredToken = config('services.autoenhance.webhook_secret');
        if ($configuredToken) {
            $providedToken = $request->bearerToken()
                ?: $request->header('x-autoenhance-webhook-token')
                ?: $request->header('x-webhook-token')
                ?: $request->input('token');

            if (!hash_equals((string) $configuredToken, (string) $providedToken)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook token',
                ], 401);
            }
        }

        $payload = $request->all();
        $event = $payload['event'] ?? null;
        $imageId = $payload['image_id'] ?? $payload['imageId'] ?? null;

        Log::info('AutoenhanceController: Webhook received', [
            'event' => $event,
            'image_id' => $imageId,
            'order_id' => $payload['order_id'] ?? null,
        ]);

        if (!$imageId) {
            return response()->json([
                'success' => true,
                'message' => 'Webhook received',
            ]);
        }

        $job = AiEditingJob::where('provider', 'autoenhance')
            ->where(function ($query) use ($imageId) {
                $query->where('autoenhance_image_id', $imageId)
                    ->orWhere('provider_job_id', $imageId);
            })
            ->first();

        if (!$job) {
            return response()->json([
                'success' => true,
                'message' => 'No matching local job',
            ]);
        }

        $job->provider_result = array_merge($job->provider_result ?? [], [
            'webhook' => $payload,
            'webhook_received_at' => now()->toIso8601String(),
        ]);
        $job->save();

        if ($event === 'image_processed') {
            if (filter_var($payload['error'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $job->markAsFailed($payload['message'] ?? $payload['status_reason'] ?? 'Autoenhance reported processing error');
            } else {
                ProcessAutoenhanceEditingJob::dispatchAfterResponse($job);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed',
        ]);
    }

    private function canEditShoot($user, Shoot $shoot): bool
    {
        return in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true);
    }

    private function providerFromRequest(Request $request): string
    {
        $provider = strtolower((string) (
            $request->input('provider')
            ?? $request->query('provider')
            ?? $this->defaultProvider()
        ));

        return in_array($provider, ['autoenhance', 'fal'], true)
            ? $provider
            : $this->defaultProvider();
    }

    private function defaultProvider(): string
    {
        $provider = strtolower((string) config('services.ai_editing.provider', 'fal'));

        return in_array($provider, ['autoenhance', 'fal'], true) ? $provider : 'fal';
    }

    private function getImageUrl(ShootFile $shootFile): ?string
    {
        try {
            $resolved = $this->shootFileAccessService->resolveFileUrl($shootFile, true);
            if ($resolved) {
                return $resolved;
            }
        } catch (\Throwable $e) {
            \App\Services\ApiErrorResponder::log($e, 'warning');
        }

        $url = $shootFile->url ?? $shootFile->storage_path ?? $shootFile->dropbox_path ?? $shootFile->path;
        if (!$url) {
            return null;
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        $baseUrl = rtrim(config('app.url'), '/');
        return $baseUrl . '/' . ltrim($url, '/');
    }

    private function resolveSourceThumb(?ShootFile $file): ?string
    {
        if (!$file) {
            return null;
        }

        try {
            $optimized = $this->shootFileAccessService->resolveOptimizedFileUrl($file);
            if ($optimized) {
                return $optimized;
            }
        } catch (\Throwable $e) {
        }

        try {
            return $this->shootFileAccessService->resolveFileUrl($file, false);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function presentJob(AiEditingJob $job): array
    {
        $shoot = $job->relationLoaded('shoot') ? $job->shoot : null;
        $sourceFile = $job->relationLoaded('shootFile') ? $job->shootFile : $job->shootFile;
        $sourceThumb = $this->resolveSourceThumb($sourceFile);
        $outputFile = ShootFile::where('ai_editing_job_id', $job->id)
            ->where('media_type', 'edited')
            ->orderByDesc('id')
            ->first();
        $outputThumb = $this->resolveSourceThumb($outputFile);

        return [
            'id' => $job->id,
            'shoot_id' => $job->shoot_id,
            'shoot_file_id' => $job->shoot_file_id,
            'provider' => $job->provider,
            'provider_job_id' => $job->provider_job_id,
            'provider_order_id' => $job->provider_order_id,
            'autoenhance_image_id' => $job->autoenhance_image_id,
            'status' => $job->status,
            'editing_type' => $job->editing_type,
            'editing_params' => $job->editing_params,
            'original_image_url' => $job->original_image_url,
            'edited_image_url' => $job->edited_image_url,
            'error_message' => \App\Services\ApiErrorResponder::storedFailure($job->error_message),
            'retry_count' => $job->retry_count,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
            'shoot' => $shoot ? [
                'id' => $shoot->id,
                'address' => $shoot->address,
                'city' => $shoot->city ?? null,
                'state' => $shoot->state ?? null,
                'zip' => $shoot->zip ?? null,
            ] : null,
            'source_file' => $sourceFile ? [
                'id' => $sourceFile->id,
                'filename' => $sourceFile->filename,
                'thumb_url' => $sourceThumb,
            ] : null,
            'output_file' => $outputFile ? [
                'id' => $outputFile->id,
                'filename' => $outputFile->filename,
                'url' => $this->getImageUrl($outputFile),
                'thumb_url' => $outputThumb,
            ] : null,
        ];
    }
}
