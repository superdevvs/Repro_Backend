<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\ShootActivityLogger;
use App\Services\ShootMediaStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ShootEditorDownloadService
{
    private const POLL_AFTER_MS = 3000;
    private const DOWNLOAD_NOTIFICATION_WINDOW_MINUTES = 30;

    public function __construct(
        protected ShootMediaStorageService $mediaStorageService,
        protected ShootActivityLogger $activityLogger,
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
        protected ShootShareLinkService $shootShareLinkService,
        protected ShootEditingAssignmentService $shootEditingAssignmentService,
        protected ShootArchiveFilenameFormatter $archiveFilenameFormatter,
        protected ShootMediaArchiveService $shootMediaArchiveService,
        protected ShootFileAccessService $shootFileAccessService
    ) {
    }

    public function downloadRaw(Request $request, Shoot $shoot, User $user)
    {
        $fileIdsParam = $request->query('file_ids', []);
        if (is_string($fileIdsParam)) {
            $fileIdsParam = array_filter(explode(',', $fileIdsParam));
        }

        $isCustomSelection = ! empty($fileIdsParam);
        // Full-set downloads must use the same file universe as ShootMediaArchiveService
        // so signature-matched cached ZIPs are reused. Custom selections stay explicit.
        if ($isCustomSelection) {
            $allFiles = $shoot->files()
                ->where('workflow_stage', ShootFile::STAGE_TODO)
                ->whereIn('id', $fileIdsParam)
                ->inDeliveryOrder()->get()
                ->filter(fn (ShootFile $file) => $file->isRequiredForEditing())
                // Infected files are withheld from download/delivery (Req 15.7).
                ->reject(fn (ShootFile $file) => $file->isBlockedFromDelivery())
                ->values();
        } else {
            $allFiles = $this->shootMediaArchiveService->getFilesForType($shoot, 'raw');
        }
        $isEditorDownload = $this->shootAuthorizationSupport->hasRole($user, ['editor']);
        $files = $isEditorDownload
            ? $this->shootEditingAssignmentService->filterFilesForEditor($allFiles, $shoot, $user)
            : $allFiles;
        $fileCount = $files->count();

        if (! empty($fileIdsParam) && $fileCount === 0) {
            return $this->withCors(
                response()->json(['error' => 'No raw files found for selected IDs'], 404),
                $request,
            );
        }
        if ($fileCount === 0) {
            return $this->withCors(
                response()->json(['error' => 'No raw files found to download'], 404),
                $request,
            );
        }

        // Prefer the shared async archive pipeline when this download is the full
        // raw hand-off set. Custom photo selections fall through to a scoped ZIP.
        // Building multi-GB ZIPs inline was causing nginx 499/502 timeouts for
        // editors (Cloudflare idle timeout while PHP zipped).
        $fullRawFiles = $isCustomSelection
            ? $this->shootMediaArchiveService->getFilesForType($shoot, 'raw')
            : $allFiles;
        if ($this->fileIdsMatch($files, $fullRawFiles)) {
            try {
                $archiveResponse = $this->shootMediaArchiveService->resolveArchiveResponseData(
                    $shoot,
                    'raw',
                    'original',
                    $request->fullUrl()
                );
                $payload = $archiveResponse['payload'];
                $payload['file_count'] = $fileCount;
                if (($payload['type'] ?? null) === 'redirect' && empty($payload['message'])) {
                    $payload['message'] = 'Raw files ready for download.';
                }
                if ($archiveResponse['status'] === 200 && ($payload['type'] ?? null) === 'redirect'
                    && is_string($payload['url'] ?? null) && $payload['url'] !== '') {
                    $this->recordReadyDownload($shoot, $user, $files, $isEditorDownload);
                }

                return $this->withCors(
                    response()->json($payload, $archiveResponse['status']),
                    $request,
                );
            } catch (\RuntimeException $e) {
                return $this->withCors(
                    response()->json(['error' => $e->getMessage(), 'file_count' => $fileCount], 404),
                    $request,
                );
            } catch (\Exception $e) {
                \App\Services\ApiErrorResponder::log($e, 'error');

                return $this->withCors(
                    response()->json(['error' => 'The ZIP download could not be prepared. Please try again.'], 500),
                    $request,
                );
            }
        }

        return $this->resolveScopedAsyncDownload($request, $shoot, $user, $files, $fileCount);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ShootFile>  $files
     */
    protected function resolveScopedAsyncDownload(
        Request $request,
        Shoot $shoot,
        User $user,
        $files,
        int $fileCount
    ) {
        // Re-read permissions and source metadata immediately before issuing a ready URL.
        $shoot = $shoot->fresh();
        $user = $user->fresh();
        if (! $shoot || ! $user) {
            return $this->withCors(response()->json(['error' => 'No authorized raw files available'], 403), $request);
        }
        $scoped = app(EditorRawArchiveService::class);
        $files = $scoped->authorizedFiles($shoot, $user, $files->pluck('id')->all());
        if ($files->isEmpty()) {
            return $this->withCors(response()->json(['error' => 'No authorized raw files available'], 403), $request);
        }
        $fileCount = $files->count();
        $descriptor = $scoped->descriptor($shoot, $user, $files);
        $storagePath = $descriptor['storage_path'];
        $media = app(MediaStorage::class);

        if ($media->exists($storagePath)) {
            $url = $this->shootFileAccessService->resolvePublicStorageUrl($storagePath)
                ?? $media->publicUrl($storagePath);

            if (is_string($url) && $url !== '') {
                $this->recordReadyDownload($shoot, $user, $files,
                    $this->shootAuthorizationSupport->hasRole($user, ['editor']));

                return $this->withCors(response()->json([
                    'type' => 'redirect',
                    'url' => $url,
                    'message' => 'Raw files ready for download.',
                    'file_count' => $fileCount,
                ]), $request);
            }
        }

        $scoped->queue($shoot, $user, $files);

        return $this->withCors(response()->json([
            'type' => 'preparing',
            'message' => 'Preparing your raw files.',
            'poll_after_ms' => self::POLL_AFTER_MS,
            'status_url' => $request->fullUrl(),
            'file_count' => $fileCount,
        ], 202), $request);
    }

    /**
     * Record a ready hand-off, never a preparation poll or failed request.
     *
     * @param Collection<int, ShootFile> $files
     */
    protected function recordReadyDownload(Shoot $shoot, User $user, Collection $files, bool $isEditor): void
    {
        $signature = hash('sha256', json_encode($files->sortBy('id')->map(fn (ShootFile $file) => [
            $file->id, $file->storage_path ?: $file->path, $file->file_size,
            $file->updated_at?->format('Y-m-d H:i:s.u'),
        ])->values()->all(), JSON_THROW_ON_ERROR));
        $action = $isEditor ? 'raw_downloaded_by_editor' : 'raw_downloaded_by_admin';
        $key = "raw-download-notification:{$shoot->id}:{$user->id}:{$action}:{$signature}";

        // Atomic across concurrent ready responses. The persisted activity also
        // prevents duplicate alerts after a cache clear or deployment.
        if (! Cache::add($key, true, now()->addMinutes(self::DOWNLOAD_NOTIFICATION_WINDOW_MINUTES))) {
            return;
        }
        try {
            if ($shoot->activityLogs()->where('action', $action)->where('user_id', $user->id)
                ->where('created_at', '>=', now()->subMinutes(self::DOWNLOAD_NOTIFICATION_WINDOW_MINUTES))
                ->where('metadata->download_signature', $signature)->exists()) {
                return;
            }

            $this->activityLogger->log($shoot, $action, [
                'downloader_id' => $user->id,
                'downloader_name' => $user->name,
                'downloader_role' => $user->role,
                'file_count' => $files->count(),
                'download_signature' => $signature,
                'download_ready' => true,
            ], $user);

            if ($isEditor) {
                $this->notifyAdminsOfEditorDownload($shoot, $user, $files->count());
            }
        } catch (\Throwable $exception) {
            Cache::forget($key);
            throw $exception;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ShootFile>  $left
     * @param  \Illuminate\Support\Collection<int, ShootFile>  $right
     */
    protected function fileIdsMatch($left, $right): bool
    {
        $a = $left->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $b = $right->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $a !== [] && $a === $b;
    }

    protected function withCors(Response $response, Request $request): Response
    {
        $origin = $request->headers->get('Origin', '*');

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Expose-Headers', implode(', ', config('cors.exposed_headers', ['Content-Disposition'])));

        return $response;
    }

    protected function notifyAdminsOfEditorDownload(Shoot $shoot, User $editor, int $fileCount): void
    {
        try {
            if (! class_exists('App\\Models\\Notification') || ! Schema::hasTable('notifications')) {
                return;
            }

            $admins = User::whereIn('role', ['admin', 'superadmin'])->get();
            foreach ($admins as $admin) {
                \App\Models\Notification::create([
                    'user_id' => $admin->id,
                    'type' => 'editor_download',
                    'title' => 'Editor Downloaded Raw Files',
                    'message' => "{$editor->name} downloaded {$fileCount} raw files from shoot #{$shoot->id} ({$shoot->address})",
                    'data' => [
                        'shoot_id' => $shoot->id,
                        'editor_id' => $editor->id,
                        'editor_name' => $editor->name,
                        'file_count' => $fileCount,
                    ],
                    'read' => false,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to notify admins of editor download: '.$e->getMessage());
        }
    }
}
