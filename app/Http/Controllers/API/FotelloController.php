<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\AiEditingJob;
use App\Services\FotelloService;
use App\Jobs\ProcessFotelloEditingJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FotelloController extends Controller
{
    public function __construct(private FotelloService $fotelloService)
    {
    }

    /**
     * GET /api/fotello/editing-types
     * List available editing types
     */
    public function getEditingTypes()
    {
        try {
            $types = $this->fotelloService->getEditingTypes();

            return response()->json([
                'success' => true,
                'data' => $types,
            ]);
        } catch (\Exception $e) {
            Log::error('FotelloController: Error getting editing types', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return default types even on error to prevent UI breakage
            return response()->json([
                'success' => true,
                'data' => $this->fotelloService->getDefaultEditingTypes(),
                'message' => 'Using default editing types',
            ]);
        }
    }

    /**
     * POST /api/fotello/edit
     * Submit image(s) for AI editing
     */
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
            $fileIds = $request->file_ids;
            $editingType = $request->editing_type;
            $params = $request->params ?? [];

            // Verify user has access to this shoot
            $user = $request->user();
            if (!$this->canEditShoot($user, $shoot)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to edit this shoot',
                ], 403);
            }

            $jobs = [];

            foreach ($fileIds as $fileId) {
                $shootFile = ShootFile::findOrFail($fileId);

                // Verify file belongs to the shoot
                if ($shootFile->shoot_id !== $shoot->id) {
                    continue;
                }

                // Get image URL
                $imageUrl = $this->getImageUrl($shootFile);
                if (!$imageUrl) {
                    Log::warning('FotelloController: Could not get image URL', [
                        'file_id' => $fileId,
                    ]);
                    continue;
                }

                // Create AI editing job
                $editingJob = AiEditingJob::create([
                    'shoot_id' => $shoot->id,
                    'shoot_file_id' => $shootFile->id,
                    'user_id' => $user->id,
                    'status' => AiEditingJob::STATUS_PENDING,
                    'editing_type' => $editingType,
                    'editing_params' => $params,
                    'original_image_url' => $imageUrl,
                ]);

                // Dispatch queue job
                ProcessFotelloEditingJob::dispatch($editingJob);

                $jobs[] = $editingJob;
            }

            if (empty($jobs)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid files found for editing',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Editing jobs submitted successfully',
                'data' => $jobs->map(function ($job) {
                    return [
                        'id' => $job->id,
                        'shoot_id' => $job->shoot_id,
                        'shoot_file_id' => $job->shoot_file_id,
                        'status' => $job->status,
                        'editing_type' => $job->editing_type,
                        'created_at' => $job->created_at,
                    ];
                }),
            ], 201);

        } catch (\Exception $e) {
            Log::error('FotelloController: Error submitting editing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit editing job',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/fotello/jobs
     * List all jobs for a shoot/user
     */
    public function listJobs(Request $request)
    {
        try {
            $user = $request->user();
            $query = AiEditingJob::with(['shoot', 'shootFile', 'user']);

            // Filter by shoot if provided
            if ($request->has('shoot_id')) {
                $query->where('shoot_id', $request->shoot_id);
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // For non-admin users, only show their own jobs
            if (!in_array($user->role, ['admin', 'superadmin'])) {
                $query->where('user_id', $user->id);
            }

            $jobs = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $jobs->items(),
                'meta' => [
                    'current_page' => $jobs->currentPage(),
                    'last_page' => $jobs->lastPage(),
                    'per_page' => $jobs->perPage(),
                    'total' => $jobs->total(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('FotelloController: Error listing jobs', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve jobs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/fotello/jobs/{jobId}
     * Get job status
     */
    public function getJobStatus($jobId)
    {
        try {
            $job = AiEditingJob::with(['shoot', 'shootFile', 'user'])->findOrFail($jobId);

            // Check permissions
            $user = request()->user();
            if (!in_array($user->role, ['admin', 'superadmin']) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this job',
                ], 403);
            }

            // If job is processing and has Fotello job ID, check status
            if ($job->isProcessing() && $job->fotello_job_id) {
                $status = $this->fotelloService->getJobStatus($job->fotello_job_id);
                if ($status) {
                    // Update job status if needed
                    $fotelloStatus = $status['status'] ?? $status['state'] ?? null;
                    if ($fotelloStatus === 'completed') {
                        // Job might be completed, trigger a refresh
                        ProcessFotelloEditingJob::dispatch($job);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $job->id,
                    'shoot_id' => $job->shoot_id,
                    'shoot_file_id' => $job->shoot_file_id,
                    'fotello_job_id' => $job->fotello_job_id,
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
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('FotelloController: Error getting job status', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve job status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/fotello/jobs/{jobId}/cancel
     * Cancel a pending job
     */
    public function cancelJob($jobId)
    {
        try {
            $job = AiEditingJob::findOrFail($jobId);

            // Check permissions
            $user = request()->user();
            if (!in_array($user->role, ['admin', 'superadmin']) && $job->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to cancel this job',
                ], 403);
            }

            // Only pending or processing jobs can be cancelled
            if (!in_array($job->status, [AiEditingJob::STATUS_PENDING, AiEditingJob::STATUS_PROCESSING])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending or processing jobs can be cancelled',
                ], 400);
            }

            // Cancel in Fotello if job ID exists
            if ($job->fotello_job_id) {
                $this->fotelloService->cancelJob($job->fotello_job_id);
            }

            // Update job status
            $job->status = AiEditingJob::STATUS_CANCELLED;
            $job->save();

            return response()->json([
                'success' => true,
                'message' => 'Job cancelled successfully',
                'data' => $job,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('FotelloController: Error cancelling job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel job',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if user can edit this shoot
     */
    private function canEditShoot($user, Shoot $shoot): bool
    {
        // Admins and superadmins can edit any shoot
        if (in_array($user->role, ['admin', 'superadmin'])) {
            return true;
        }

        // Clients can edit their own shoots
        if ($user->role === 'client' && $shoot->client_id === $user->id) {
            return true;
        }

        // Photographers can edit shoots assigned to them
        if ($user->role === 'photographer' && $shoot->photographer_id === $user->id) {
            return true;
        }

        // Editors can edit any shoot
        if ($user->role === 'editor') {
            return true;
        }

        return false;
    }

    /**
     * Get image URL for a shoot file
     */
    private function getImageUrl(ShootFile $shootFile): ?string
    {
        // Try to get from storage path or dropbox path
        $url = $shootFile->storage_path ?? $shootFile->dropbox_path ?? $shootFile->path;

        if (!$url) {
            return null;
        }

        // If it's already a full URL, return it
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }

        // Otherwise, construct full URL
        // This might need adjustment based on your storage setup
        $baseUrl = config('app.url');
        if (str_starts_with($url, '/')) {
            return $baseUrl . $url;
        }

        return $baseUrl . '/' . $url;
    }
}

