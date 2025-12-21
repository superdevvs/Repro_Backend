<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\AiEditingJob;
use App\Services\FotelloService;
use App\Jobs\ProcessFotelloEditingJob;
use Illuminate\Support\Facades\Log;

class AiEditingTools
{
    private FotelloService $fotelloService;

    public function __construct()
    {
        $this->fotelloService = app(FotelloService::class);
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
                    ->limit(10) // Limit to 10 files if not specified
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
                    'status' => AiEditingJob::STATUS_PENDING,
                    'editing_type' => $editingType,
                    'editing_params' => $params['params'] ?? [],
                    'original_image_url' => $imageUrl,
                ]);

                // Dispatch queue job
                ProcessFotelloEditingJob::dispatch($editingJob);

                $jobs[] = [
                    'job_id' => $editingJob->id,
                    'file_id' => $file->id,
                    'filename' => $file->filename,
                ];
            }

            return [
                'success' => true,
                'message' => "Submitted {$validFiles->count()} image(s) for AI editing",
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
                $query = AiEditingJob::where('shoot_id', $shootId);
                
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
            $types = $this->fotelloService->getEditingTypes();

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
}

