<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\AiVideoPreset;
use App\Models\AiVideoGenerationJob;
use App\Models\AiVideoVerticalVariant;
use App\Services\HiggsFieldService;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Jobs\ProcessVideoAspectConversion;
use App\Jobs\ProcessVideoGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class HiggsFieldController extends Controller
{
    public function __construct(private HiggsFieldService $higgsFieldService)
    {
    }

    // ─── Preset Endpoints ────────────────────────────────────────────────

    /**
     * GET /api/higgsfield/presets
     */
    public function getPresets(Request $request)
    {
        try {
            $user = $request->user();
            $isAdmin = in_array($user->role, ['admin', 'superadmin']);

            $presets = $this->higgsFieldService->getPresets($isAdmin);

            // Strip prompt_template for non-admin users
            if (!$isAdmin) {
                $presets = array_map(function ($preset) {
                    unset($preset['prompt_template']);
                    return $preset;
                }, $presets);
            }

            return response()->json([
                'success' => true,
                'data' => $presets,
            ]);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error getting presets', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->higgsFieldService->getDefaultPresets(),
            ]);
        }
    }

    /**
     * POST /api/higgsfield/presets
     */
    public function createPreset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'required|string|unique:ai_video_presets,slug|max:50',
            'name' => 'required|string|max:100',
            'description' => 'required|string|max:255',
            'icon' => 'required|string|max:50',
            'category' => 'required|string|in:Lighting,Movement,Staging,Specialty',
            'prompt_template' => 'required|string',
            'max_frames' => 'required|integer|in:1,2',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $preset = AiVideoPreset::create($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Preset created successfully',
                'data' => $preset,
            ], 201);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error creating preset', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create preset',
            ], 500);
        }
    }

    /**
     * PUT /api/higgsfield/presets/{id}
     */
    public function updatePreset(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'slug' => 'string|unique:ai_video_presets,slug,' . $id . '|max:50',
            'name' => 'string|max:100',
            'description' => 'string|max:255',
            'icon' => 'string|max:50',
            'category' => 'string|in:Lighting,Movement,Staging,Specialty',
            'prompt_template' => 'string',
            'max_frames' => 'integer|in:1,2',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $preset = AiVideoPreset::findOrFail($id);
            $preset->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Preset updated successfully',
                'data' => $preset->fresh(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Preset not found'], 404);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error updating preset', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update preset',
            ], 500);
        }
    }

    /**
     * DELETE /api/higgsfield/presets/{id}
     */
    public function deletePreset($id)
    {
        try {
            $preset = AiVideoPreset::findOrFail($id);
            $preset->delete();

            return response()->json([
                'success' => true,
                'message' => 'Preset deleted successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Preset not found'], 404);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error deleting preset', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete preset',
            ], 500);
        }
    }

    // ─── Video Generation Endpoints ──────────────────────────────────────

    /**
     * POST /api/higgsfield/generate
     */
    public function submitVideoGeneration(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shoot_id' => 'required|exists:shoots,id',
            'start_frame_file_id' => 'required|exists:shoot_files,id',
            'end_frame_file_id' => 'nullable|exists:shoot_files,id',
            'preset_id' => 'required|exists:ai_video_presets,id',
            'aspect_ratio' => 'required|in:horizontal,vertical,square,standard',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $shoot = Shoot::findOrFail($request->shoot_id);

            if (!$this->canAccessShoot($user, $shoot)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to access this shoot',
                ], 403);
            }

            $preset = AiVideoPreset::findOrFail($request->preset_id);
            $startFrameFile = ShootFile::findOrFail($request->start_frame_file_id);

            // Verify files belong to the shoot
            if ($startFrameFile->shoot_id !== $shoot->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Start frame does not belong to the selected shoot',
                ], 400);
            }

            $endFrameFile = null;
            $originalEndFrameUrl = null;

            if ($request->end_frame_file_id) {
                $endFrameFile = ShootFile::findOrFail($request->end_frame_file_id);
                if ($endFrameFile->shoot_id !== $shoot->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'End frame does not belong to the selected shoot',
                    ], 400);
                }
                $originalEndFrameUrl = $this->getImageUrl($endFrameFile);
            }

            $originalStartFrameUrl = $this->getImageUrl($startFrameFile);
            if (!$originalStartFrameUrl) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not resolve start frame image URL',
                ], 400);
            }

            // Create the video generation job
            $job = AiVideoGenerationJob::create([
                'shoot_id' => $shoot->id,
                'user_id' => $user->id,
                'preset_id' => $preset->id,
                'start_frame_file_id' => $startFrameFile->id,
                'end_frame_file_id' => $endFrameFile?->id,
                'preset_prompt' => $preset->prompt_template,
                'aspect_ratio' => $request->aspect_ratio,
                'status' => AiVideoGenerationJob::STATUS_PENDING,
                'original_start_frame_url' => $originalStartFrameUrl,
                'original_end_frame_url' => $originalEndFrameUrl,
            ]);

            // Dispatch appropriate job based on aspect ratio
            if ($request->aspect_ratio === 'vertical') {
                ProcessVideoAspectConversion::dispatch($job);
            } else {
                ProcessVideoGeneration::dispatch($job);
            }

            $job->load(['preset', 'variants']);

            return response()->json([
                'success' => true,
                'message' => 'Video generation job submitted successfully',
                'data' => $this->formatJob($job),
            ], 201);

        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error submitting video generation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit video generation job',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/higgsfield/jobs
     */
    public function listJobs(Request $request)
    {
        try {
            $user = $request->user();
            $query = AiVideoGenerationJob::with(['preset', 'variants']);

            if ($request->has('shoot_id')) {
                $query->where('shoot_id', $request->shoot_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                $query->where('user_id', $user->id);
            }

            $jobs = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => collect($jobs->items())->map(fn($job) => $this->formatJob($job)),
                'meta' => [
                    'current_page' => $jobs->currentPage(),
                    'last_page' => $jobs->lastPage(),
                    'per_page' => $jobs->perPage(),
                    'total' => $jobs->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error listing jobs', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve jobs',
            ], 500);
        }
    }

    /**
     * GET /api/higgsfield/jobs/{jobId}
     */
    public function getJobStatus($jobId)
    {
        try {
            $job = AiVideoGenerationJob::with(['preset', 'variants'])->findOrFail($jobId);

            $user = request()->user();
            if (!in_array($user->role, ['admin', 'superadmin']) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this job',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatJob($job),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Job not found'], 404);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error getting job status', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve job status',
            ], 500);
        }
    }

    /**
     * POST /api/higgsfield/jobs/{jobId}/select-variants
     */
    public function selectVariants(Request $request, $jobId)
    {
        $validator = Validator::make($request->all(), [
            'start_frame_variant_id' => 'required|exists:ai_video_vertical_variants,id',
            'end_frame_variant_id' => 'nullable|exists:ai_video_vertical_variants,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $job = AiVideoGenerationJob::with('variants')->findOrFail($jobId);

            $user = $request->user();
            if (!in_array($user->role, ['admin', 'superadmin']) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to modify this job',
                ], 403);
            }

            if ($job->status !== AiVideoGenerationJob::STATUS_AWAITING_APPROVAL) {
                return response()->json([
                    'success' => false,
                    'message' => 'Job is not awaiting variant approval',
                ], 400);
            }

            // Deselect all existing variants
            $job->variants()->update(['is_selected' => false]);

            // Select start frame variant
            $startVariant = AiVideoVerticalVariant::where('id', $request->start_frame_variant_id)
                ->where('video_generation_job_id', $job->id)
                ->where('frame_type', 'start')
                ->where('status', 'completed')
                ->firstOrFail();

            $startVariant->select();
            $job->selected_start_frame_url = $startVariant->image_url;

            // Select end frame variant if provided
            if ($request->end_frame_variant_id) {
                $endVariant = AiVideoVerticalVariant::where('id', $request->end_frame_variant_id)
                    ->where('video_generation_job_id', $job->id)
                    ->where('frame_type', 'end')
                    ->where('status', 'completed')
                    ->firstOrFail();

                $endVariant->select();
                $job->selected_end_frame_url = $endVariant->image_url;
            }

            $job->save();

            // Dispatch video generation with selected variants
            ProcessVideoGeneration::dispatch($job);

            $job->load('variants');

            return response()->json([
                'success' => true,
                'message' => 'Variants selected, video generation started',
                'data' => $this->formatJob($job),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Job or variant not found'], 404);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error selecting variants', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to select variants',
            ], 500);
        }
    }

    /**
     * POST /api/higgsfield/jobs/{jobId}/regenerate-variants
     */
    public function regenerateVariants($jobId)
    {
        try {
            $job = AiVideoGenerationJob::findOrFail($jobId);

            $user = request()->user();
            if (!in_array($user->role, ['admin', 'superadmin']) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to modify this job',
                ], 403);
            }

            if ($job->status !== AiVideoGenerationJob::STATUS_AWAITING_APPROVAL) {
                return response()->json([
                    'success' => false,
                    'message' => 'Job is not awaiting variant approval',
                ], 400);
            }

            // Delete old variants
            $job->variants()->delete();

            // Reset and re-dispatch conversion
            $job->selected_start_frame_url = null;
            $job->selected_end_frame_url = null;
            $job->save();

            ProcessVideoAspectConversion::dispatch($job);

            $job->load('variants');

            return response()->json([
                'success' => true,
                'message' => 'Variant regeneration started',
                'data' => $this->formatJob($job),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Job not found'], 404);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error regenerating variants', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to regenerate variants',
            ], 500);
        }
    }

    /**
     * POST /api/higgsfield/jobs/{jobId}/cancel
     */
    public function cancelJob($jobId)
    {
        try {
            $job = AiVideoGenerationJob::findOrFail($jobId);

            $user = request()->user();
            if (!in_array($user->role, ['admin', 'superadmin']) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to cancel this job',
                ], 403);
            }

            if (!$job->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only active jobs can be cancelled',
                ], 400);
            }

            // Try to cancel pending Higgsfield requests
            if ($job->higgsfield_video_request_id) {
                $this->higgsFieldService->cancelRequest($job->higgsfield_video_request_id);
            }

            // Cancel pending variant requests
            $pendingVariants = $job->variants()->where('status', 'pending')->get();
            foreach ($pendingVariants as $variant) {
                $this->higgsFieldService->cancelRequest($variant->higgsfield_request_id);
            }

            $job->status = AiVideoGenerationJob::STATUS_CANCELLED;
            $job->save();

            return response()->json([
                'success' => true,
                'message' => 'Job cancelled successfully',
                'data' => $this->formatJob($job),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Job not found'], 404);
        } catch (\Exception $e) {
            Log::error('HiggsFieldController: Error cancelling job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel job',
            ], 500);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function formatJob(AiVideoGenerationJob $job): array
    {
        return [
            'id' => $job->id,
            'shoot_id' => $job->shoot_id,
            'preset_id' => $job->preset_id,
            'preset_name' => $job->preset?->name,
            'status' => $job->status,
            'aspect_ratio' => $job->aspect_ratio,
            'start_frame_file_id' => $job->start_frame_file_id,
            'end_frame_file_id' => $job->end_frame_file_id,
            'original_start_frame_url' => $job->original_start_frame_url,
            'original_end_frame_url' => $job->original_end_frame_url,
            'selected_start_frame_url' => $job->selected_start_frame_url,
            'selected_end_frame_url' => $job->selected_end_frame_url,
            'start_frame_variants' => $job->variants
                ->where('frame_type', 'start')
                ->values()
                ->map(fn($v) => [
                    'id' => $v->id,
                    'variant_index' => $v->variant_index,
                    'status' => $v->status,
                    'image_url' => $v->image_url,
                    'is_selected' => $v->is_selected,
                ]),
            'end_frame_variants' => $job->variants
                ->where('frame_type', 'end')
                ->values()
                ->map(fn($v) => [
                    'id' => $v->id,
                    'variant_index' => $v->variant_index,
                    'status' => $v->status,
                    'image_url' => $v->image_url,
                    'is_selected' => $v->is_selected,
                ]),
            'video_url' => $job->video_url,
            'video_thumbnail_url' => $job->video_thumbnail_url,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
        ];
    }

    private function canAccessShoot($user, Shoot $shoot): bool
    {
        if (in_array($user->role, ['admin', 'superadmin', 'editing_manager'])) {
            return true;
        }

        if ($user->role === 'client' && $shoot->client_id === $user->id) {
            return true;
        }

        if ($user->role === 'photographer') {
            return app(ShootAuthorizationSupport::class)->isPhotographerAssignedToShoot($shoot, $user);
        }

        return false;
    }

    /**
     * Get a publicly accessible image URL for a shoot file.
     * Used for storing in DB and frontend display.
     * Uses same URL resolution logic as ShootController.
     */
    private function getImageUrl(ShootFile $shootFile): ?string
    {
        // Prefer web_path (optimized/smaller) over full storage_path
        $path = $shootFile->web_path ?? $shootFile->storage_path ?? $shootFile->dropbox_path ?? $shootFile->path;

        if (!$path) {
            Log::warning('HiggsFieldController: No path found for shoot file', [
                'file_id' => $shootFile->id,
            ]);
            return null;
        }

        // If it's already a full URL (e.g., CDN, Dropbox shared link), use directly
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // Build public URL using same logic as ShootController's $resolveUrl
        $cleanPath = ltrim($path, '/');
        if (str_starts_with($cleanPath, 'shoots/') || str_starts_with($cleanPath, 'storage/')) {
            $storagePath = str_starts_with($cleanPath, 'storage/') ? $cleanPath : 'storage/' . $cleanPath;
            return url($storagePath);
        }

        return url('storage/' . $cleanPath);
    }

    /**
     * Read a shoot file from local disk and return as base64 data URI.
     * This is used by queue jobs when sending images to external APIs like Higgsfield,
     * since those APIs need to fetch the image and may not be able to reach our server directly.
     *
     * @param ShootFile $shootFile
     * @return string|null Base64 data URI or null if file not found
     */
    public static function readImageAsBase64(ShootFile $shootFile): ?string
    {
        $pathsToTry = [];

        $storagePath = $shootFile->storage_path;
        $filename = $shootFile->filename;
        $storedFilename = $shootFile->stored_filename;
        $shootId = $shootFile->shoot_id;

        // Try web_path first (optimized/smaller for faster API calls)
        if ($shootFile->web_path) {
            $webClean = ltrim($shootFile->web_path, '/');
            $pathsToTry[] = storage_path('app/public/' . $webClean);
            $pathsToTry[] = public_path('storage/' . $webClean);
        }

        // Then try storage_path
        if ($storagePath) {
            $cleanPath = ltrim($storagePath, '/');
            $pathsToTry[] = storage_path('app/public/' . $cleanPath);
            $pathsToTry[] = storage_path('app/' . $cleanPath);
            $pathsToTry[] = public_path('storage/' . $cleanPath);
        }

        // Try common patterns with filename
        if ($filename && $shootId) {
            $pathsToTry[] = storage_path("app/public/shoots/{$shootId}/todo/{$filename}");
            $pathsToTry[] = storage_path("app/public/shoots/{$shootId}/completed/{$filename}");
            $pathsToTry[] = storage_path("app/public/shoots/{$shootId}/{$filename}");
        }

        if ($storedFilename && $shootId) {
            $pathsToTry[] = storage_path("app/public/shoots/{$shootId}/todo/{$storedFilename}");
            $pathsToTry[] = storage_path("app/public/shoots/{$shootId}/completed/{$storedFilename}");
            $pathsToTry[] = storage_path("app/public/shoots/{$shootId}/{$storedFilename}");
        }

        // Try web_path / thumbnail_path as local file paths
        if ($shootFile->web_path) {
            $webClean = ltrim($shootFile->web_path, '/');
            $pathsToTry[] = storage_path('app/public/' . $webClean);
            $pathsToTry[] = public_path('storage/' . $webClean);
        }

        foreach ($pathsToTry as $diskPath) {
            if (file_exists($diskPath) && is_file($diskPath)) {
                try {
                    // Resize large images to max 2048px to avoid memory exhaustion
                    $resized = self::resizeImageForApi($diskPath);
                    if ($resized) {
                        Log::info('HiggsFieldController: Converted image to base64', [
                            'file_id' => $shootFile->id,
                            'disk_path' => $diskPath,
                            'base64_length' => strlen($resized['base64']),
                        ]);
                        return "data:{$resized['mime']};base64,{$resized['base64']}";
                    }

                    // Fallback: read raw file if GD is unavailable
                    $contents = file_get_contents($diskPath);
                    if ($contents === false) continue;

                    $mimeType = mime_content_type($diskPath) ?: 'image/jpeg';
                    $base64 = base64_encode($contents);

                    Log::info('HiggsFieldController: Converted image to base64 (raw)', [
                        'file_id' => $shootFile->id,
                        'disk_path' => $diskPath,
                        'size_bytes' => strlen($contents),
                    ]);

                    return "data:{$mimeType};base64,{$base64}";
                } catch (\Exception $e) {
                    Log::warning('HiggsFieldController: Failed to read image file', [
                        'path' => $diskPath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::warning('HiggsFieldController: Could not find image on disk for base64 conversion', [
            'file_id' => $shootFile->id,
            'storage_path' => $storagePath,
            'paths_tried' => count($pathsToTry),
        ]);

        return null;
    }

    /**
     * Resize an image to fit within max dimensions to prevent memory exhaustion.
     * Returns ['base64' => ..., 'mime' => ...] or null on failure.
     */
    private static function resizeImageForApi(string $path, int $maxDim = 2048): ?array
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return null;
        }

        $info = @getimagesize($path);
        if (!$info) return null;

        [$origW, $origH, $type] = $info;

        // If already small enough and under 5MB, skip resize
        if ($origW <= $maxDim && $origH <= $maxDim && filesize($path) < 5 * 1024 * 1024) {
            $contents = file_get_contents($path);
            if ($contents === false) return null;
            $mime = image_type_to_mime_type($type);
            return ['base64' => base64_encode($contents), 'mime' => $mime];
        }

        // Calculate new dimensions
        $scale = min($maxDim / $origW, $maxDim / $origH, 1.0);
        $newW = (int) round($origW * $scale);
        $newH = (int) round($origH * $scale);

        // Temporarily increase memory for image processing
        $oldLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $src = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG  => @imagecreatefrompng($path),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
                default => false,
            };

            if (!$src) {
                ini_set('memory_limit', $oldLimit);
                return null;
            }

            $dst = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            imagedestroy($src);

            ob_start();
            imagejpeg($dst, null, 85);
            $contents = ob_get_clean();
            imagedestroy($dst);

            ini_set('memory_limit', $oldLimit);

            if (!$contents) return null;

            return ['base64' => base64_encode($contents), 'mime' => 'image/jpeg'];
        } catch (\Exception $e) {
            ini_set('memory_limit', $oldLimit);
            Log::warning('HiggsFieldController: Image resize failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
