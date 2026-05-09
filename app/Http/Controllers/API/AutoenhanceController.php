<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAutoenhanceEditingJob;
use App\Models\AiEditingJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\AutoenhanceService;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AutoenhanceController extends Controller
{
    public function __construct(
        private AutoenhanceService $autoenhanceService,
        private ShootFileAccessService $shootFileAccessService,
    ) {
    }

    public function connectionStatus()
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->autoenhanceService->testConnection(),
            ]);
        } catch (\Exception $e) {
            Log::warning('AutoenhanceController: connection status error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'data' => [
                    'success' => false,
                    'status' => 503,
                    'message' => 'Autoenhance status unavailable',
                ],
            ]);
        }
    }

    public function getEditingTypes()
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->autoenhanceService->getEditingTypes(),
            ]);
        } catch (\Exception $e) {
            Log::error('AutoenhanceController: Error getting editing types', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->autoenhanceService->getDefaultEditingTypes(),
                'message' => 'Using default Autoenhance editing types',
            ]);
        }
    }

    public function submitEditing(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shoot_id' => 'required|exists:shoots,id',
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'required|exists:shoot_files,id',
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

        try {
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
                if (!$imageUrl) {
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
                    'provider' => 'autoenhance',
                    'status' => AiEditingJob::STATUS_PENDING,
                    'editing_type' => $request->editing_type,
                    'editing_params' => $request->params ?? [],
                    'original_image_url' => $imageUrl,
                ]);

                ProcessAutoenhanceEditingJob::dispatch($editingJob);
                $jobs->push($editingJob);
            }

            if ($jobs->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid files could be queued for Autoenhance editing',
                    'skipped' => $skipped,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Autoenhance editing jobs submitted successfully',
                'data' => $jobs->map(fn (AiEditingJob $job) => $this->presentJob($job))->values(),
                'skipped' => $skipped,
            ], 201);
        } catch (\Exception $e) {
            Log::error('AutoenhanceController: Error submitting editing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit Autoenhance editing job',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function listJobs(Request $request)
    {
        try {
            $user = $request->user();
            $query = AiEditingJob::with(['shoot:id,address,city,state,zip', 'shootFile', 'user:id,name,email'])->where('provider', 'autoenhance');

            if ($request->has('shoot_id')) {
                $query->where('shoot_id', $request->shoot_id);
            }
            if ($request->has('status')) {
                $query->where('status', $request->status);
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
            Log::error('AutoenhanceController: Error listing jobs', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Autoenhance jobs',
                'error' => $e->getMessage(),
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

            if ($job->isProcessing() && $job->autoenhance_image_id) {
                $status = $this->autoenhanceService->getJobStatus($job->autoenhance_image_id);
                if (($status['status'] ?? null) === 'completed') {
                    ProcessAutoenhanceEditingJob::dispatch($job);
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
            Log::error('AutoenhanceController: Error getting job status', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Autoenhance job status',
                'error' => $e->getMessage(),
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
            $job->started_at = null;
            $job->completed_at = null;
            $job->retry_count = 0;
            $job->save();

            ProcessAutoenhanceEditingJob::dispatch($job);

            return response()->json([
                'success' => true,
                'message' => 'Autoenhance job re-queued for processing',
                'data' => $this->presentJob($job->refresh()->load(['shoot:id,address,city,state,zip', 'shootFile', 'user:id,name,email'])),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('AutoenhanceController: Error retrying job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retry Autoenhance job',
                'error' => $e->getMessage(),
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

            if ($job->autoenhance_image_id) {
                $this->autoenhanceService->cancelJob($job->autoenhance_image_id);
            }

            $job->status = AiEditingJob::STATUS_CANCELLED;
            $job->save();

            return response()->json([
                'success' => true,
                'message' => 'Autoenhance job cancelled successfully',
                'data' => $this->presentJob($job),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('AutoenhanceController: Error cancelling job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel Autoenhance job',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function canEditShoot($user, Shoot $shoot): bool
    {
        return in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true);
    }

    private function getImageUrl(ShootFile $shootFile): ?string
    {
        try {
            $resolved = $this->shootFileAccessService->resolveFileUrl($shootFile, true);
            if ($resolved) {
                return $resolved;
            }
        } catch (\Throwable $e) {
            Log::warning('AutoenhanceController: ShootFileAccessService failed, using fallback', [
                'file_id' => $shootFile->id,
                'error' => $e->getMessage(),
            ]);
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
            'error_message' => $job->error_message,
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
        ];
    }
}
