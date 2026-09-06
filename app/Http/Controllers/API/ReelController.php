<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReel;
use App\Models\AiReelJob;
use App\Models\Shoot;
use App\Models\ShootFile;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootFileAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ReelController extends Controller
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
            'selected_file_ids' => 'required|array|min:1|max:20',
            'selected_file_ids.*' => 'required|integer|distinct|exists:shoot_files,id',
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

            if (! $this->canUseReels($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your role does not have access to reel generation.',
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

            $job = AiReelJob::create([
                'shoot_id' => $shoot->id,
                'user_id' => $user->id,
                'provider' => 'fal',
                'selected_file_ids' => $selectedIds,
                'status' => AiReelJob::STATUS_QUEUED,
            ]);

            GenerateReel::dispatch($job->id);
            $job->load(['shoot:id,address,city,state,zip', 'user:id,name,email']);

            return response()->json([
                'success' => true,
                'message' => 'Reel generation started.',
                'data' => $this->presentJob($job),
            ], 201);
        } catch (\Throwable $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit reel job.',
                'error' => \App\Services\ApiErrorResponder::publicMessage($e),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = AiReelJob::with(['shoot:id,address,city,state,zip', 'user:id,name,email'])
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
            'data' => collect($jobs->items())->map(fn (AiReelJob $job) => $this->presentJob($job))->values(),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    public function show(Request $request, AiReelJob $job)
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

    public function cancel(Request $request, AiReelJob $job)
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
                'message' => 'Only active reel jobs can be cancelled.',
            ], 422);
        }

        $job->markAsCancelled();

        return response()->json([
            'success' => true,
            'message' => 'Reel job cancelled.',
            'data' => $this->presentJob($job->fresh()),
        ]);
    }

    private function canUseReels($user): bool
    {
        return $user && in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true);
    }

    private function canViewJob($user, AiReelJob $job): bool
    {
        if (! $this->canUseReels($user)) {
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

    private function presentJob(?AiReelJob $job): array
    {
        if (! $job) {
            return [];
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
            'status' => $job->status,
            'outputs' => $job->outputs,
            'error_message' => \App\Services\ApiErrorResponder::storedFailure($job->error_message),
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
