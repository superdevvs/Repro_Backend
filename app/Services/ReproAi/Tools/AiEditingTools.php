<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\AiEditingJob;
use App\Services\AutoenhanceService;
use App\Jobs\ProcessAutoenhanceEditingJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AiEditingTools
{
    private AutoenhanceService $autoenhanceService;

    public function __construct()
    {
        $this->autoenhanceService = app(AutoenhanceService::class);
    }

    /**
     * Submit images for AI editing
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Result of the operation
     */
    public function submitAiEditing(array $params = [], array $context = []): array
    {
        try {
            $shootId = $params['shoot_id'] ?? null;
            $editingType = $params['editing_type'] ?? 'enhance';
            $fileIds = $params['file_ids'] ?? null;
            $userId = $context['user_id'] ?? null;

            if (!$shootId) {
                return [
                    'success' => false,
                    'error' => 'Shoot ID is required',
                ];
            }

            if (!$userId) {
                return [
                    'success' => false,
                    'error' => 'User ID is required',
                ];
            }

            $shoot = Shoot::find($shootId);
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Shoot not found',
                ];
            }

            // If file_ids not provided, get all image files from the shoot
            if (!$fileIds) {
                $files = $shoot->files()
                    ->whereIn('file_type', ['image', 'jpg', 'jpeg', 'png'])
                    ->orWhere(function($query) {
                        $query->where('filename', 'like', '%.jpg')
                            ->orWhere('filename', 'like', '%.jpeg')
                            ->orWhere('filename', 'like', '%.png')
                            ->orWhere('filename', 'like', '%.gif');
                    })
                    ->limit(100) // Limit to 100 files if not specified (matches wizard MAX_BATCH_SIZE)
                    ->get();
                
                $fileIds = $files->pluck('id')->toArray();
            }

            if (empty($fileIds)) {
                return [
                    'success' => false,
                    'error' => 'No image files found in this shoot',
                ];
            }

            // Validate files belong to shoot
            $validFiles = ShootFile::where('shoot_id', $shootId)
                ->whereIn('id', $fileIds)
                ->get();

            if ($validFiles->isEmpty()) {
                return [
                    'success' => false,
                    'error' => 'No valid image files found',
                ];
            }

            $jobs = [];
            foreach ($validFiles as $file) {
                // Get image URL
                $imageUrl = $file->storage_path ?? $file->dropbox_path ?? $file->path;
                if (!$imageUrl) {
                    continue;
                }

                // Construct full URL if needed
                if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $baseUrl = config('app.url');
                    $imageUrl = $baseUrl . '/' . ltrim($imageUrl, '/');
                }

                // Create AI editing job
                $editingJob = AiEditingJob::create([
                    'shoot_id' => $shoot->id,
                    'shoot_file_id' => $file->id,
                    'user_id' => $userId,
                    'provider' => 'autoenhance',
                    'status' => AiEditingJob::STATUS_PENDING,
                    'editing_type' => $editingType,
                    'editing_params' => $params['params'] ?? [],
                    'original_image_url' => $imageUrl,
                ]);

                // Dispatch queue job
                ProcessAutoenhanceEditingJob::dispatch($editingJob);

                $jobs[] = [
                    'job_id' => $editingJob->id,
                    'file_id' => $file->id,
                    'filename' => $file->filename,
                ];
            }

            return [
                'success' => true,
                'message' => "Submitted {$validFiles->count()} image(s) to Autoenhance",
                'jobs' => $jobs,
                'editing_type' => $editingType,
            ];

        } catch (\Exception $e) {
            Log::error('AiEditingTools::submitAiEditing error', [
                'error' => $e->getMessage(),
                'params' => $params,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get AI editing job status
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Job status
     */
    public function getAiEditingStatus(array $params = [], array $context = []): array
    {
        try {
            $jobId = $params['job_id'] ?? null;
            $shootId = $params['shoot_id'] ?? null;
            $userId = $context['user_id'] ?? null;

            if ($jobId) {
                $job = AiEditingJob::with(['shoot', 'shootFile'])->find($jobId);
                
                if (!$job) {
                    return [
                        'success' => false,
                        'error' => 'Job not found',
                    ];
                }

                // Check permissions
                if ($userId && $job->user_id != $userId && !in_array($context['user_role'] ?? '', ['admin', 'superadmin'])) {
                    return [
                        'success' => false,
                        'error' => 'You do not have permission to view this job',
                    ];
                }

                return [
                    'success' => true,
                    'job' => [
                        'id' => $job->id,
                        'status' => $job->status,
                        'editing_type' => $job->editing_type,
                        'shoot_id' => $job->shoot_id,
                        'filename' => $job->shootFile->filename ?? 'Unknown',
                        'completed' => $job->isCompleted(),
                        'edited_image_url' => $job->edited_image_url,
                        'error_message' => $job->error_message,
                        'created_at' => $job->created_at->toIso8601String(),
                        'completed_at' => $job->completed_at?->toIso8601String(),
                    ],
                ];
            }

            if ($shootId) {
                $query = AiEditingJob::where('shoot_id', $shootId)->where('provider', 'autoenhance');
                
                if ($userId && !in_array($context['user_role'] ?? '', ['admin', 'superadmin'])) {
                    $query->where('user_id', $userId);
                }

                $jobs = $query->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get();

                return [
                    'success' => true,
                    'jobs' => $jobs->map(function ($job) {
                        return [
                            'id' => $job->id,
                            'status' => $job->status,
                            'editing_type' => $job->editing_type,
                            'filename' => $job->shootFile->filename ?? 'Unknown',
                            'completed' => $job->isCompleted(),
                            'created_at' => $job->created_at->toIso8601String(),
                        ];
                    })->toArray(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Either job_id or shoot_id is required',
            ];

        } catch (\Exception $e) {
            Log::error('AiEditingTools::getAiEditingStatus error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get available editing types
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Available editing types
     */
    public function getEditingTypes(array $params = [], array $context = []): array
    {
        try {
            $types = $this->autoenhanceService->getEditingTypes();

            return [
                'success' => true,
                'editing_types' => $types,
            ];
        } catch (\Exception $e) {
            Log::error('AiEditingTools::getEditingTypes error', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Find a shoot for the given user by free-text address.
     *
     * Performs a case-insensitive LIKE match against the address/city/state fields
     * and returns the most recent matching shoot the user is associated with.
     */
    public function findShootByAddress(string $address, ?int $userId = null, ?string $userRole = null): ?Shoot
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $query = Shoot::query();

        $isPrivileged = in_array($userRole, ['admin', 'superadmin', 'editor', 'editing_manager'], true);
        if ($userId && !$isPrivileged) {
            $query->where(function ($q) use ($userId) {
                $q->where('client_id', $userId)
                  ->orWhere('rep_id', $userId)
                  ->orWhere('editor_id', $userId)
                  ->orWhere('photographer_id', $userId);
            });
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));
        $query->where(function ($q) use ($parts, $address) {
            if (count($parts) === 0) {
                $q->where('address', 'like', "%{$address}%");
                return;
            }
            $q->where('address', 'like', '%' . $parts[0] . '%');
            if (isset($parts[1])) {
                $q->where(function ($q2) use ($parts) {
                    $q2->where('city', 'like', '%' . $parts[1] . '%')
                       ->orWhereNull('city');
                });
            }
        });

        return $query->orderByDesc('created_at')->first();
    }

    /**
     * Count raw (unedited) image files for a shoot.
     */
    public function countRawPhotos(int $shootId): int
    {
        return ShootFile::where('shoot_id', $shootId)
            ->where(function ($query) {
                $query->whereIn('file_type', ['image', 'jpg', 'jpeg', 'png'])
                    ->orWhere('filename', 'like', '%.jpg')
                    ->orWhere('filename', 'like', '%.jpeg')
                    ->orWhere('filename', 'like', '%.png')
                    ->orWhere('filename', 'like', '%.tif')
                    ->orWhere('filename', 'like', '%.tiff');
            })
            ->count();
    }

    /**
     * Aggregate counts for editing jobs belonging to a shoot.
     *
     * @return array{pending:int,processing:int,completed:int,failed:int,cancelled:int,total:int}
     */
    public function summarizeEditingForShoot(int $shootId, ?int $userId = null): array
    {
        $query = AiEditingJob::where('shoot_id', $shootId);
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $summary = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'total' => 0,
        ];

        $jobs = $query->select('status')->get();
        foreach ($jobs as $job) {
            $key = $job->status;
            if (isset($summary[$key])) {
                $summary[$key]++;
            }
            $summary['total']++;
        }

        return $summary;
    }

    /**
     * Re-dispatch every failed editing job for the given shoot.
     *
     * @return array{success:bool,retried:int,error?:string}
     */
    public function retryFailedJobsForShoot(int $shootId, ?int $userId = null): array
    {
        try {
            $query = AiEditingJob::where('shoot_id', $shootId)
                ->where('status', AiEditingJob::STATUS_FAILED);

            if ($userId) {
                $query->where('user_id', $userId);
            }

            $failed = $query->get();
            $retried = 0;

            foreach ($failed as $job) {
                $job->status = AiEditingJob::STATUS_PENDING;
                $job->error_message = null;
                $job->save();

                ProcessAutoenhanceEditingJob::dispatch($job);
                $retried++;
            }

            return [
                'success' => true,
                'retried' => $retried,
            ];
        } catch (\Exception $e) {
            Log::error('AiEditingTools::retryFailedJobsForShoot error', [
                'error' => $e->getMessage(),
                'shoot_id' => $shootId,
            ]);
            return [
                'success' => false,
                'retried' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    // ============================================================================
    // Chat-driven helpers (used by EditPhotosFlow + chat tool calls)
    // ============================================================================

    /**
     * Submit a list of previously-staged images (uuid ids) to Autoenhance with the
     * given mode + params. Each staged file becomes its own AiEditingJob.
     *
     * @param  array<int,string>  $stagedIds
     * @return array{success:bool,jobs:array<int,AiEditingJob>,skipped:array<int,array>,error?:string}
     */
    public function submitStagedQuickEdit(array $stagedIds, int $userId, string $mode, array $params = []): array
    {
        $jobs = [];
        $skipped = [];

        foreach ($stagedIds as $stagedId) {
            $stagedId = (string) $stagedId;
            if ($stagedId === '') {
                continue;
            }
            $dir = "autoenhance-uploads/{$userId}/staging";
            try {
                $matches = collect(Storage::disk('public')->files($dir))
                    ->filter(fn ($p) => str_starts_with(basename($p), $stagedId . '.'))
                    ->values();
            } catch (\Throwable $e) {
                $skipped[] = ['staged_id' => $stagedId, 'reason' => $e->getMessage()];
                continue;
            }
            if ($matches->isEmpty()) {
                $skipped[] = ['staged_id' => $stagedId, 'reason' => 'staged file missing'];
                continue;
            }

            $storedPath = $matches->first();
            try {
                $contents = (string) Storage::disk('public')->get($storedPath);
            } catch (\Throwable $e) {
                $skipped[] = ['staged_id' => $stagedId, 'reason' => $e->getMessage()];
                continue;
            }

            $name = basename($storedPath);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg');
            $contentType = match ($extension) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                default => 'image/jpeg',
            };
            $publicUrl = null;
            try { $publicUrl = Storage::disk('public')->url($storedPath); } catch (\Throwable $e) {}

            try {
                $editingJob = AiEditingJob::create([
                    'shoot_id' => null,
                    'shoot_file_id' => null,
                    'user_id' => $userId,
                    'provider' => 'autoenhance',
                    'status' => AiEditingJob::STATUS_PENDING,
                    'editing_type' => $mode,
                    'editing_params' => $params,
                    'original_image_url' => $publicUrl,
                ]);

                $providerParams = array_merge($params, [
                    'image_name' => $name,
                    'content_type' => $contentType,
                    'mime_type' => $contentType,
                ]);

                $result = $this->autoenhanceService->submitEditingJobFromBuffer(
                    $contents, $name, $contentType, $mode, $providerParams
                );

                if (!is_array($result) || !($result['image_id'] ?? null)) {
                    $errorMessage = is_array($result)
                        ? ($result['error'] ?? 'Autoenhance submission failed')
                        : 'Autoenhance submission failed';
                    $editingJob->markAsFailed(is_string($errorMessage) ? $errorMessage : 'Autoenhance submission failed');
                    if (is_array($result)) {
                        $editingJob->provider_payload = $result;
                        $editingJob->save();
                    }
                    $skipped[] = ['staged_id' => $stagedId, 'name' => $name, 'reason' => $editingJob->error_message ?? 'submission_failed'];
                    continue;
                }

                $editingJob->autoenhance_image_id = $result['image_id'];
                $editingJob->provider_job_id = $result['image_id'];
                $editingJob->provider_order_id = $result['order_id'] ?? null;
                $editingJob->provider_payload = $result['data'] ?? null;
                $editingJob->status = AiEditingJob::STATUS_PROCESSING;
                $editingJob->started_at = now();
                $editingJob->save();

                $jobs[] = $editingJob;
            } catch (\Throwable $e) {
                Log::error('AiEditingTools::submitStagedQuickEdit per-file error', [
                    'staged_id' => $stagedId,
                    'error' => $e->getMessage(),
                ]);
                $skipped[] = ['staged_id' => $stagedId, 'reason' => $e->getMessage()];
            }
        }

        return [
            'success' => count($jobs) > 0,
            'jobs' => $jobs,
            'skipped' => $skipped,
        ];
    }

    /**
     * Get a brief summary of the user's recent AI editing jobs for chat status replies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentJobsForUser(int $userId, int $limit = 10): array
    {
        $jobs = AiEditingJob::where('user_id', $userId)
            ->where('provider', 'autoenhance')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $jobs->map(function (AiEditingJob $job) {
            return [
                'id' => $job->id,
                'status' => $job->status,
                'editing_type' => $job->editing_type,
                'shoot_id' => $job->shoot_id,
                'error' => $job->error_message,
                'created_at' => optional($job->created_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Get statuses for a specific list of job ids the user owns.
     *
     * @param  array<int,int>  $jobIds
     * @return array<int, array<string,mixed>>
     */
    public function getJobsByIds(array $jobIds, int $userId): array
    {
        $ids = array_values(array_unique(array_map('intval', $jobIds)));
        if (empty($ids)) {
            return [];
        }
        $jobs = AiEditingJob::where('user_id', $userId)
            ->whereIn('id', $ids)
            ->get();
        return $jobs->map(fn (AiEditingJob $job) => [
            'id' => $job->id,
            'status' => $job->status,
            'editing_type' => $job->editing_type,
            'shoot_id' => $job->shoot_id,
            'error' => $job->error_message,
        ])->all();
    }

    /**
     * Cancel a list of pending/processing jobs the user owns.
     *
     * @param  array<int,int>  $jobIds
     * @return array{cancelled:int, skipped:array}
     */
    public function cancelJobs(array $jobIds, int $userId): array
    {
        $ids = array_values(array_unique(array_map('intval', $jobIds)));
        if (empty($ids)) {
            return ['cancelled' => 0, 'skipped' => []];
        }
        $cancelled = 0;
        $skipped = [];
        $jobs = AiEditingJob::where('user_id', $userId)->whereIn('id', $ids)->get();
        foreach ($jobs as $job) {
            if (!in_array($job->status, [AiEditingJob::STATUS_PENDING, AiEditingJob::STATUS_PROCESSING], true)) {
                $skipped[] = ['id' => $job->id, 'reason' => 'not_in_progress'];
                continue;
            }
            try {
                if ($job->autoenhance_image_id) {
                    $this->autoenhanceService->cancelJob($job->autoenhance_image_id);
                }
                $job->status = AiEditingJob::STATUS_CANCELLED;
                $job->save();
                $cancelled++;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $job->id, 'reason' => $e->getMessage()];
            }
        }
        return ['cancelled' => $cancelled, 'skipped' => $skipped];
    }

    /**
     * Re-queue a list of failed/cancelled jobs the user owns.
     *
     * @param  array<int,int>  $jobIds
     * @return array{retried:int, skipped:array}
     */
    public function retryJobs(array $jobIds, int $userId): array
    {
        $ids = array_values(array_unique(array_map('intval', $jobIds)));
        if (empty($ids)) {
            return ['retried' => 0, 'skipped' => []];
        }
        $retried = 0;
        $skipped = [];
        $jobs = AiEditingJob::where('user_id', $userId)->whereIn('id', $ids)->get();
        foreach ($jobs as $job) {
            if (!in_array($job->status, [AiEditingJob::STATUS_FAILED, AiEditingJob::STATUS_CANCELLED], true)) {
                $skipped[] = ['id' => $job->id, 'reason' => 'not_failed_or_cancelled'];
                continue;
            }
            try {
                $job->status = AiEditingJob::STATUS_PENDING;
                $job->error_message = null;
                $job->edited_image_url = null;
                $job->autoenhance_image_id = null;
                $job->provider_job_id = null;
                $job->started_at = null;
                $job->completed_at = null;
                $job->retry_count = 0;
                $job->save();
                ProcessAutoenhanceEditingJob::dispatchAfterResponse($job);
                $retried++;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $job->id, 'reason' => $e->getMessage()];
            }
        }
        return ['retried' => $retried, 'skipped' => $skipped];
    }
}

