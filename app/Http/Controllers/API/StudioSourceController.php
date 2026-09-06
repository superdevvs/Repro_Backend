<?php

namespace App\Http\Controllers\API;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class StudioSourceController extends StudioController
{
    protected const BROWSE_RECENT_SHOOTS = false;

    private const RESULT_LIMIT = 20;

    private const WORKFLOWS = [
        'photo-enhancement',
        'twilight',
        'video-cleanup',
        'listing-video',
        'reel-generator',
        'batch-ai-jobs',
    ];

    private const RAW_IMAGE_WORKFLOWS = [
        'photo-enhancement',
        'twilight',
        'batch-ai-jobs',
    ];

    private const STANDARD_IMAGE_WORKFLOWS = [
        'listing-video',
        'reel-generator',
    ];

    public function __construct(
        private ShootAuthorizationSupport $shootAuthorization,
        private \App\Services\UploadValidationService $uploadValidation
    ) {}

    public function searchShoots(Request $request): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'view');

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));

        if ($search === '' && ! static::BROWSE_RECENT_SHOOTS) {
            return $this->shootSearchResponse($search, collect());
        }

        $like = '%'.$search.'%';
        $shoots = $this->scopeShootQuery(Shoot::query(), $request->user())
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search, $like): void {
                $query->where('property_slug', 'like', $like)
                    ->orWhere('mls_id', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('state', 'like', $like)
                    ->orWhere('zip', 'like', $like);

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            }))
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->map(fn (Shoot $shoot): array => $this->presentShoot($shoot));

        return $this->shootSearchResponse($search, $shoots);
    }

    public function shootMedia(Request $request, string $shoot): JsonResponse
    {
        $this->authorizeStudioAction($request->user(), 'view');

        $authorizedShoot = $this->scopeShootQuery(Shoot::query(), $request->user())
            ->whereKey($shoot)
            ->first();

        abort_if($authorizedShoot === null, 404, 'Shoot not found.');

        $validated = $request->validate([
            'workflow' => ['required', 'string', Rule::in(self::WORKFLOWS)],
        ]);
        $workflow = $validated['workflow'];

        $media = $authorizedShoot->files()
            ->where('is_hidden', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ShootFile $file): bool => $file->isClearedForProcessing())
            ->filter(fn (ShootFile $file): bool => $this->supportsWorkflow($file, $workflow))
            ->map(fn (ShootFile $file): array => $this->presentMedia($file, $workflow))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $media,
            'meta' => [
                'shoot' => $this->presentShoot($authorizedShoot),
                'workflow' => $workflow,
                'total' => $media->count(),
            ],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeStudioAction($user, 'create');

        $validated = $request->validate([
            'workflow' => ['required', 'string', Rule::in(self::WORKFLOWS)],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file'],
        ]);
        $workflow = (string) $validated['workflow'];
        $constraints = (array) config("studio_uploads.workflows.{$workflow}", []);
        $accepted = [];
        $rejected = [];

        foreach ((array) $request->file('files', []) as $file) {
            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                continue;
            }

            $filename = basename($file->getClientOriginalName() ?: 'unnamed-upload');
            $violations = $this->uploadViolations($file, $workflow, $constraints);
            if ($violations !== []) {
                $rejected[] = compact('filename', 'violations');

                continue;
            }

            try {
                $accepted[] = $this->storeUpload($file, $workflow, $user);
            } catch (\Throwable $exception) {
                $rejected[] = [
                    'filename' => $filename,
                    'violations' => [[
                        'constraint' => 'storage',
                        'message' => 'The file could not be stored.',
                    ]],
                ];
            }
        }

        $acceptedCount = count($accepted);
        $rejectedCount = count($rejected);

        return response()->json([
            'success' => $acceptedCount > 0,
            'data' => [
                'accepted' => $accepted,
                'rejected' => $rejected,
            ],
            'meta' => [
                'workflow' => $workflow,
                'acceptedCount' => $acceptedCount,
                'rejectedCount' => $rejectedCount,
            ],
        ], $acceptedCount > 0 ? 201 : 422);
    }

    private function uploadViolations(
        \Illuminate\Http\UploadedFile $file,
        string $workflow,
        array $constraints
    ): array {
        $violations = [];
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower(trim(explode(';', (string) $file->getMimeType())[0]));
        $size = $file->getSize();
        $maxBytes = (int) ($constraints['max_bytes'] ?? 0);

        if (! $file->isValid()) {
            $violations[] = [
                'constraint' => 'upload',
                'message' => 'The file upload did not complete successfully.',
            ];
        }
        if (! in_array($extension, (array) ($constraints['extensions'] ?? []), true)) {
            $violations[] = [
                'constraint' => 'extension',
                'message' => "Extension .{$extension} is not supported for {$workflow}.",
                'actual' => $extension,
            ];
        }
        if (! in_array($mime, (array) ($constraints['mimes'] ?? []), true)) {
            $violations[] = [
                'constraint' => 'mime',
                'message' => "MIME type {$mime} is not supported for {$workflow}.",
                'actual' => $mime,
            ];
        }
        if (is_int($size) && $maxBytes > 0 && $size > $maxBytes) {
            $violations[] = [
                'constraint' => 'size',
                'message' => "File exceeds the {$maxBytes}-byte limit for {$workflow}.",
                'actualBytes' => $size,
                'maxBytes' => $maxBytes,
            ];
        }
        if ($this->uploadValidation->hasDangerousContentType($file)) {
            $violations[] = [
                'constraint' => 'content',
                'message' => 'File content does not match an allowed media type.',
            ];
        }

        return $violations;
    }

    private function storeUpload(
        \Illuminate\Http\UploadedFile $file,
        string $workflow,
        Authenticatable $user
    ): array {
        $id = \Illuminate\Support\Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension());
        $directory = "studio/uploads/{$this->scopeTeamId($user)}/{$user->getAuthIdentifier()}";
        $disk = (string) config('studio_uploads.disk', 'public');
        $path = $file->storeAs($directory, "{$id}.{$extension}", $disk);
        if ($path === false) {
            throw new \RuntimeException('Studio upload storage failed.');
        }

        $url = \Illuminate\Support\Facades\Storage::disk($disk)->url($path);
        if (! \Illuminate\Support\Str::startsWith($url, ['http://', 'https://'])) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        return [
            'id' => $id,
            'mediaRef' => $path,
            'storagePath' => $path,
            'url' => $url,
            'previewUrl' => $url,
            'filename' => basename($file->getClientOriginalName()),
            'mimeType' => strtolower((string) $file->getMimeType()),
            'mediaType' => $workflow === 'video-cleanup'
                ? 'video'
                : (in_array($extension, (array) config('studio_uploads.workflows.listing-video.extensions', []), true)
                    ? 'image'
                    : 'raw'),
            'fileSize' => (int) $file->getSize(),
            'workflow' => $workflow,
            'uploadedAt' => now()->toIso8601String(),
        ];
    }

    protected function scopeShootQuery(Builder $query, Authenticatable $user): Builder
    {
        $userIds = $this->authorizedUserIds($user);

        return $query->where(function (Builder $shoot) use ($userIds): void {
            $shoot->whereIn('client_id', $userIds)
                ->orWhereIn('photographer_id', $userIds)
                ->orWhereIn('editor_id', $userIds)
                ->orWhereIn('rep_id', $userIds)
                ->orWhereIn('created_by', $userIds)
                ->orWhereHas('services', fn (Builder $service) => $service
                    ->whereIn('shoot_service.editor_id', $userIds));
        });
    }

    private function authorizedUserIds(Authenticatable $user): array
    {
        if ($this->scopeUserId($user) !== null) {
            return [(int) $user->getAuthIdentifier()];
        }

        $teamId = $this->scopeTeamId($user);

        return User::query()
            ->where(function (Builder $candidate) use ($teamId): void {
                $candidate->whereKey($teamId)
                    ->orWhere('metadata->team_id', $teamId);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function supportsWorkflow(ShootFile $file, string $workflow): bool
    {
        if ($workflow === 'video-cleanup') {
            return $this->shootAuthorization->isVideoMediaFile($file);
        }

        if (in_array($workflow, self::STANDARD_IMAGE_WORKFLOWS, true)) {
            return $this->shootAuthorization->isImageMediaFile($file);
        }

        return in_array($workflow, self::RAW_IMAGE_WORKFLOWS, true)
            && ($this->shootAuthorization->isImageMediaFile($file)
                || $this->shootAuthorization->isRawCameraFile($file));
    }

    private function presentShoot(Shoot $shoot): array
    {
        $location = collect([$shoot->city, $shoot->state, $shoot->zip])
            ->filter(fn ($value): bool => trim((string) $value) !== '')
            ->implode(', ');
        $identifier = $shoot->property_slug ?: $shoot->mls_id ?: "Shoot #{$shoot->id}";

        return [
            'id' => $shoot->id,
            'propertyIdentifier' => $identifier,
            'address' => $shoot->address,
            'location' => $location !== '' ? $location : null,
            'label' => $shoot->address ?: $identifier,
            'thumbnailUrl' => $shoot->hero_image,
            'updatedAt' => $shoot->updated_at?->toIso8601String(),
        ];
    }

    private function presentMedia(ShootFile $file, string $workflow): array
    {
        $mediaType = $this->shootAuthorization->isVideoMediaFile($file)
            ? 'video'
            : ($this->shootAuthorization->isRawCameraFile($file) ? 'raw' : 'image');
        $previewUrl = url("/api/shoots/{$file->shoot_id}/files/{$file->id}/preview");

        return [
            'id' => $file->id,
            'shootId' => $file->shoot_id,
            'filename' => $file->filename,
            'mimeType' => $file->mime_type ?: $file->file_type,
            'mediaType' => $mediaType,
            'fileSize' => (int) $file->file_size,
            'workflowStage' => $file->workflow_stage,
            'workflow' => $workflow,
            'previewUrl' => $previewUrl,
            'thumbnailUrl' => $previewUrl,
        ];
    }

    private function shootSearchResponse(string $query, Collection $shoots): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $shoots->values(),
            'meta' => [
                'query' => $query,
                'total' => $shoots->count(),
            ],
        ]);
    }
}
