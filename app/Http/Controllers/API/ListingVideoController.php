<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateListingVideo;
use App\Models\AiListingVideoJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ListingVideoController extends Controller
{
    public function __construct(
        private ShootAuthorizationSupport $authorization,
        private ShootFileAccessService $fileAccess,
    ) {
    }

    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shoot_id' => 'required|exists:shoots,id',
            'selected_file_ids' => 'required|array|min:6|max:10',
            'selected_file_ids.*' => 'required|integer|distinct|exists:shoot_files,id',
            'target_seconds' => 'required|integer|in:30,40,45',
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
            $shoot = Shoot::findOrFail($request->integer('shoot_id'));

            if (! $this->canUseListingVideos($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your role does not have access to listing video generation.',
                ], 403);
            }

            if (! $this->authorization->canAccessShootMedia($shoot, $user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to access this shoot.',
                ], 403);
            }

            $selectedIds = array_map('intval', $request->input('selected_file_ids', []));
            $files = ShootFile::whereIn('id', $selectedIds)->get()->keyBy('id');

            foreach ($selectedIds as $fileId) {
                $file = $files->get($fileId);
                if (! $file || (int) $file->shoot_id !== (int) $shoot->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'All selected photos must belong to the selected shoot.',
                    ], 422);
                }

                if (! $this->isSupportedImage($file)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Selected file {$fileId} is not a supported image.",
                    ], 422);
                }
            }

            $job = AiListingVideoJob::create([
                'shoot_id' => $shoot->id,
                'user_id' => $user->id,
                'provider' => 'fal',
                'selected_file_ids' => $selectedIds,
                'target_seconds' => $request->integer('target_seconds'),
                'status' => AiListingVideoJob::STATUS_QUEUED,
                'total_clips' => count($selectedIds),
                'completed_clips' => 0,
                'estimated_cost' => count($selectedIds) * 0.8,
            ]);

            GenerateListingVideo::dispatch($job->id);
            $job->load(['shoot:id,address,city,state,zip', 'user:id,name,email']);

            return response()->json([
                'success' => true,
                'message' => 'Listing video generation started.',
                'data' => $this->presentJob($job),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('ListingVideoController: failed to submit listing video', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit listing video job.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = AiListingVideoJob::with(['shoot:id,address,city,state,zip', 'user:id,name,email'])
            ->orderByDesc('created_at');

        if ($request->filled('shoot_id')) {
            $query->where('shoot_id', $request->integer('shoot_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if (! in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            $query->where('user_id', $user->id);
        }

        $jobs = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => collect($jobs->items())->map(fn (AiListingVideoJob $job) => $this->presentJob($job))->values(),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    public function show(Request $request, AiListingVideoJob $job)
    {
        if (! $this->canViewJob($request->user(), $job)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this job.',
            ], 403);
        }

        $job->load(['shoot:id,address,city,state,zip', 'user:id,name,email']);

        return response()->json([
            'success' => true,
            'data' => $this->presentJob($job),
        ]);
    }

    public function cancel(Request $request, AiListingVideoJob $job)
    {
        if (! $this->canViewJob($request->user(), $job)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to cancel this job.',
            ], 403);
        }

        if (! $job->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Only active listing video jobs can be cancelled.',
            ], 422);
        }

        $job->markAsCancelled();

        return response()->json([
            'success' => true,
            'message' => 'Listing video job cancelled.',
            'data' => $this->presentJob($job->fresh()),
        ]);
    }

    private function canUseListingVideos($user): bool
    {
        return $user && in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true);
    }

    private function canViewJob($user, AiListingVideoJob $job): bool
    {
        if (! $this->canUseListingVideos($user)) {
            return false;
        }

        if (in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true)) {
            return true;
        }

        return (int) $job->user_id === (int) $user->id;
    }

    private function isSupportedImage(ShootFile $file): bool
    {
        if ($this->authorization->isImageMediaFile($file)) {
            return true;
        }

        $name = strtolower((string) ($file->filename ?? $file->stored_filename ?? ''));
        return (bool) preg_match('/\.(jpg|jpeg|png|webp|tiff|tif|heic|heif)$/', $name);
    }

    private function presentJob(?AiListingVideoJob $job): array
    {
        if (! $job) {
            return [];
        }

        if ($job->failIfStale()) {
            $job->refresh();
        }

        $sourceFiles = ShootFile::whereIn('id', $job->selected_file_ids ?? [])->get()->keyBy('id');
        $orderedFiles = collect($job->selected_file_ids ?? [])
            ->map(fn ($id) => $sourceFiles->get((int) $id))
            ->filter()
            ->map(fn (ShootFile $file) => [
                'id' => $file->id,
                'filename' => $file->filename,
                'thumb_url' => $this->resolveThumb($file),
            ])
            ->values();

        return [
            'id' => $job->id,
            'shoot_id' => $job->shoot_id,
            'user_id' => $job->user_id,
            'provider' => $job->provider,
            'selected_file_ids' => $job->selected_file_ids ?? [],
            'selected_files' => $orderedFiles,
            'target_seconds' => $job->target_seconds,
            'status' => $job->status,
            'total_clips' => $job->total_clips,
            'completed_clips' => $job->completed_clips,
            'outputs' => $job->outputs,
            'provider_request_ids' => $job->provider_request_ids,
            'estimated_cost' => (float) $job->estimated_cost,
            'error_message' => $job->error_message,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'created_at' => $job->created_at,
            'updated_at' => $job->updated_at,
            'shoot' => $job->shoot ? [
                'id' => $job->shoot->id,
                'address' => $job->shoot->address,
                'city' => $job->shoot->city,
                'state' => $job->shoot->state,
                'zip' => $job->shoot->zip,
            ] : null,
        ];
    }

    private function resolveThumb(ShootFile $file): ?string
    {
        try {
            return $this->fileAccess->resolveOptimizedFileUrl($file);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
