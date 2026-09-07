<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\ShootActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ShootEditorDownloadService
{
    public function __construct(
        protected ShootMediaStorageService $mediaStorageService,
        protected ShootActivityLogger $activityLogger,
        protected ShootAuthorizationSupport $shootAuthorizationSupport,
        protected ShootShareLinkService $shootShareLinkService,
        protected ShootEditingAssignmentService $shootEditingAssignmentService
    ) {
    }

    public function downloadRaw(Request $request, Shoot $shoot, User $user)
    {
        $fileIdsParam = $request->query('file_ids', []);
        if (is_string($fileIdsParam)) {
            $fileIdsParam = array_filter(explode(',', $fileIdsParam));
        }

        $filesQuery = $shoot->files()->where('workflow_stage', ShootFile::STAGE_TODO);
        if (!empty($fileIdsParam)) {
            $filesQuery->whereIn('id', $fileIdsParam);
        }

        $allFiles = $filesQuery
            ->get()
            ->filter(fn (ShootFile $file) => $file->isRequiredForEditing())
            // Infected files are withheld from download/delivery (Req 15.7).
            ->reject(fn (ShootFile $file) => $file->isBlockedFromDelivery())
            ->values();
        $isEditorDownload = $this->shootAuthorizationSupport->hasRole($user, ['editor']);
        $files = $isEditorDownload
            ? $this->shootEditingAssignmentService->filterFilesForEditor($allFiles, $shoot, $user)
            : $allFiles;
        $fileCount = $files->count();

        if (!empty($fileIdsParam) && $fileCount === 0) {
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

        $this->activityLogger->log(
            $shoot,
            $isEditorDownload ? 'raw_downloaded_by_editor' : 'raw_downloaded_by_admin',
            [
                'downloader_id' => $user->id,
                'downloader_name' => $user->name,
                'downloader_role' => $user->role,
                'file_count' => $fileCount > 0 ? $fileCount : 'all',
            ],
            $user
        );

        if ($isEditorDownload) {
            $this->notifyAdminsOfEditorDownload($shoot, $user, $fileCount > 0 ? $fileCount : 0);
        }

        try {
            if ($files->count() > 0) {
                $zipPath = $this->shootShareLinkService->generateFilesZip($shoot, $files);
                if ($zipPath && file_exists($zipPath)) {
                    return $this->withCors(response()->download($zipPath, "shoot-{$shoot->id}-raw-files.zip", [
                        'X-File-Count' => $fileCount,
                    ])->deleteFileAfterSend(true), $request);
                }
            }

            return $this->withCors(response()->json([
                'error' => 'No downloadable files are available.',
                'file_count' => $fileCount,
            ], 404), $request);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return $this->withCors(
                response()->json(['error' => 'The ZIP download could not be prepared. Please try again.'], 500),
                $request,
            );
        }
    }

    protected function withCors(Response $response, Request $request): Response
    {
        $origin = $request->headers->get('Origin', '*');

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }

    protected function notifyAdminsOfEditorDownload(Shoot $shoot, User $editor, int $fileCount): void
    {
        try {
            if (!class_exists('App\\Models\\Notification') || !Schema::hasTable('notifications')) {
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
            Log::warning('Failed to notify admins of editor download: ' . $e->getMessage());
        }
    }
}
