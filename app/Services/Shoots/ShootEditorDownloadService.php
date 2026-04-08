<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\ShootActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ShootEditorDownloadService
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootActivityLogger $activityLogger,
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

        $filesQuery = $shoot->files()->where('workflow_stage', 'todo');
        if (!empty($fileIdsParam)) {
            $filesQuery->whereIn('id', $fileIdsParam);
        }

        $files = $this->shootEditingAssignmentService->filterFilesForEditor($filesQuery->get(), $shoot, $user);
        $fileCount = $files->count();
        $dropboxEnabled = $this->dropboxService->isEnabled();
        $folderPath = $dropboxEnabled ? $shoot->getDropboxFolderForType('raw') : null;

        if (!empty($fileIdsParam) && $fileCount === 0) {
            return $this->withCors(
                response()->json(['error' => 'No raw files found for selected IDs'], 404),
                $request,
            );
        }
        if ($fileCount === 0 && !$folderPath) {
            return $this->withCors(
                response()->json(['error' => 'No raw files found to download'], 404),
                $request,
            );
        }

        $this->activityLogger->log(
            $shoot,
            'raw_downloaded_by_editor',
            [
                'editor_id' => $user->id,
                'editor_name' => $user->name,
                'file_count' => $fileCount > 0 ? $fileCount : 'all',
            ],
            $user
        );

        $this->notifyAdminsOfEditorDownload($shoot, $user, $fileCount > 0 ? $fileCount : 0);

        if ($dropboxEnabled && $folderPath && empty($fileIdsParam)) {
            try {
                $zipLink = $this->dropboxService->getDropboxZipLink($folderPath);
                if ($zipLink) {
                    return $this->withCors(response()->json([
                        'type' => 'redirect',
                        'url' => $zipLink,
                        'file_count' => $fileCount,
                        'message' => 'Download started. Switch to Edited tab to upload your edits.',
                    ]), $request);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get Dropbox ZIP link, trying fallback', ['error' => $e->getMessage()]);
            }
        }

        try {
            if ($files->count() > 0) {
                $zipPath = $this->shootShareLinkService->generateFilesZipWithDropboxFallback($shoot, $files);
                if ($zipPath && file_exists($zipPath)) {
                    return $this->withCors(response()->download($zipPath, "shoot-{$shoot->id}-raw-files.zip", [
                        'X-File-Count' => $fileCount,
                    ])->deleteFileAfterSend(true), $request);
                }
            }

            if ($dropboxEnabled && $folderPath) {
                try {
                    $zipPath = $this->dropboxService->generateZipOnFly($shoot, 'raw');
                    if ($zipPath && file_exists($zipPath)) {
                        return $this->withCors(response()->download($zipPath, "shoot-{$shoot->id}-raw-files.zip", [
                            'X-File-Count' => $fileCount,
                        ])->deleteFileAfterSend(true), $request);
                    }
                } catch (\Exception $dropboxError) {
                    Log::warning('Dropbox generateZipOnFly failed', ['error' => $dropboxError->getMessage()]);
                }
            }

            return $this->withCors(response()->json([
                'error' => 'No downloadable files available. Files may not be stored locally or Dropbox access may be unavailable.',
                'file_count' => $fileCount,
                'has_dropbox_folder' => $dropboxEnabled && !empty($folderPath),
            ], 404), $request);
        } catch (\Exception $e) {
            Log::error('Failed to generate ZIP for editor download', [
                'error' => $e->getMessage(),
                'shoot_id' => $shoot->id,
            ]);

            return $this->withCors(
                response()->json(['error' => 'Failed to generate ZIP file: ' . $e->getMessage()], 500),
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
