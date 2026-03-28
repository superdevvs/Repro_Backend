<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadShootFilesAction
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootMediaMutationSupportService $support
    ) {
    }

    public function execute(Request $request, Shoot $shoot, ?User $user): array
    {
        Log::info('Upload request received', [
            'shoot_id' => $shoot->id,
            'has_files' => $request->hasFile('files'),
            'file_count' => $request->hasFile('files') ? (is_array($request->file('files')) ? count($request->file('files')) : 1) : 0,
            'all_keys' => array_keys($request->all()),
            'php_files' => array_keys($_FILES),
            'content_length' => $request->header('Content-Length'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ]);

        $files = $request->file('files');
        if (!$files) {
            $contentLength = (int) $request->header('Content-Length', 0);
            $postMaxSize = $this->parseSize(ini_get('post_max_size'));

            if ($contentLength > $postMaxSize) {
                return [
                    'status' => 413,
                    'payload' => [
                        'error_type' => 'oversize',
                        'message' => 'Upload too large. Maximum allowed: ' . ini_get('post_max_size'),
                        'errors' => ['files' => ['The uploaded file exceeds the server limit of ' . ini_get('post_max_size')]],
                    ],
                ];
            }

            return [
                'status' => 422,
                'payload' => [
                    'error_type' => 'invalid_file',
                    'message' => 'No files received. The file may have been too large or the upload was interrupted.',
                    'errors' => ['files' => ['No valid files were received by the server.']],
                    'debug' => [
                        'post_max_size' => ini_get('post_max_size'),
                        'upload_max_filesize' => ini_get('upload_max_filesize'),
                        'content_length' => $contentLength,
                    ],
                ],
            ];
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if (!$file->isValid()) {
                return [
                    'status' => 422,
                    'payload' => [
                        'error_type' => 'invalid_file',
                        'message' => 'Invalid file uploaded',
                        'errors' => ['files' => ['One or more files failed to upload properly.']],
                    ],
                ];
            }

            if ($file->getSize() > (500 * 1024 * 1024)) {
                return [
                    'status' => 422,
                    'payload' => [
                        'error_type' => 'oversize',
                        'message' => 'File too large: ' . $file->getClientOriginalName(),
                        'errors' => ['files' => ['File exceeds 500MB limit: ' . $file->getClientOriginalName()]],
                    ],
                ];
            }
        }

        $request->files->set('files', $files);
        $uploadType = $request->input('upload_type', 'raw');

        if ($uploadType === 'raw' && $request->has('bracket_mode')) {
            $bracketMode = (int) $request->input('bracket_mode');
            $shoot->bracket_mode = $bracketMode;
            $expectedFinalCount = $shoot->expected_final_count ?? $shoot->package?->expectedDeliveredCount ?? 0;
            $shoot->expected_raw_count = $expectedFinalCount * $bracketMode;
            $shoot->save();
        }

        if ($request->has('photographer_notes')) {
            $shoot->photographer_notes = $request->input('photographer_notes');
            $shoot->save();
        }

        $isAdmin = $user && in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true);
        $isEditedUploadManager = $user && in_array($user->role, ['admin', 'superadmin', 'editing_manager', 'editor'], true);

        if ($uploadType === 'raw' && !$isAdmin && !$shoot->canUploadPhotos()) {
            return [
                'status' => 400,
                'payload' => [
                    'error_type' => 'invalid_workflow_stage',
                    'message' => 'Cannot upload raw files at this workflow stage',
                    'current_status' => $shoot->workflow_status,
                ],
            ];
        }

        if ($uploadType === 'edited' && !$isEditedUploadManager) {
            return [
                'status' => 403,
                'payload' => [
                    'error_type' => 'forbidden',
                    'message' => 'You do not have permission to upload edited files',
                    'current_status' => $shoot->workflow_status,
                ],
            ];
        }

        if ($uploadType === 'edited' && !in_array($shoot->workflow_status, [
            Shoot::STATUS_UPLOADED,
            Shoot::STATUS_EDITING,
            Shoot::STATUS_READY,
        ], true)) {
            return [
                'status' => 400,
                'payload' => [
                    'error_type' => 'invalid_workflow_stage',
                    'message' => 'Cannot upload edited files at this workflow stage',
                    'current_status' => $shoot->workflow_status,
                ],
            ];
        }

        $uploadedFiles = [];
        $errors = [];

        DB::beginTransaction();
        try {
            $isExtra = $request->boolean('is_extra', false);
            $mediaTypeOverride = $request->input('media_type');
            $serviceCategory = $request->input('service_category');

            foreach ($files as $file) {
                try {
                    $resolvedMediaType = null;
                    if ($mediaTypeOverride && in_array($mediaTypeOverride, ['floorplan', 'extra', 'virtual_staging'], true)) {
                        $resolvedMediaType = $mediaTypeOverride;
                    } elseif ($isExtra) {
                        $resolvedMediaType = 'extra';
                    }

                    $shootFile = $uploadType === 'raw'
                        ? $this->dropboxService->uploadToTodo($shoot, $file, auth()->id(), $serviceCategory, $resolvedMediaType)
                        : $this->dropboxService->uploadToCompleted($shoot, $file, auth()->id(), $serviceCategory, $resolvedMediaType);

                    $thumbUrl = $shootFile->thumbnail_path ? Storage::disk('public')->url($shootFile->thumbnail_path) : null;
                    $webUrl = $shootFile->web_path ? Storage::disk('public')->url($shootFile->web_path) : null;

                    $uploadedFiles[] = [
                        'id' => $shootFile->id,
                        'filename' => $shootFile->filename,
                        'workflow_stage' => $shootFile->workflow_stage,
                        'dropbox_path' => $shootFile->dropbox_path,
                        'file_size' => $shootFile->file_size,
                        'uploaded_at' => $shootFile->created_at,
                        'is_extra' => $shootFile->media_type === 'extra',
                        'thumbnail_path' => $shootFile->thumbnail_path,
                        'web_path' => $shootFile->web_path,
                        'placeholder_path' => $shootFile->placeholder_path,
                        'thumb' => $thumbUrl,
                        'thumb_url' => $thumbUrl,
                        'thumbnail_url' => $thumbUrl,
                        'medium' => $webUrl,
                        'web_url' => $webUrl,
                    ];
                } catch (\Exception $e) {
                    $errors[] = [
                        'filename' => $file->getClientOriginalName(),
                        'error_type' => 'upload_failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $shoot = $this->support->refreshMediaCounters($shoot->fresh());
            $this->support->clearShootFilesCache($shoot);

            if (count($uploadedFiles) > 0 && in_array($shoot->workflow_status, [Shoot::STATUS_SCHEDULED, 'scheduled', 'booked'], true)) {
                $shoot->updateWorkflowStatus(Shoot::STATUS_UPLOADED, $user ? $user->id : auth()->id());
                $shoot->save();
            }

            if ($uploadType === 'edited' && count($uploadedFiles) > 0 && $user && in_array($user->role, ['admin', 'superadmin', 'editor', 'editing_manager'], true)) {
                $hasEditedFiles = $shoot->files()->whereIn('workflow_stage', ['completed', 'verified'])->exists();
                if ($hasEditedFiles && !in_array($shoot->workflow_status, [Shoot::STATUS_READY, Shoot::STATUS_DELIVERED, 'ready_for_client', 'admin_verified'], true)) {
                    $shoot->updateWorkflowStatus(Shoot::STATUS_READY, $user->id);
                    $shoot->save();
                }
            }

            DB::commit();

            return [
                'status' => 200,
                'payload' => [
                    'message' => count($errors) > 0 && count($uploadedFiles) > 0 ? 'Files processed with some upload errors' : 'Files processed',
                    'uploaded_files' => $uploadedFiles,
                    'errors' => $errors,
                    'success_count' => count($uploadedFiles),
                    'error_count' => count($errors),
                    'partial_success' => count($uploadedFiles) > 0 && count($errors) > 0,
                    'shoot_status' => $shoot->workflow_status,
                    'raw_photo_count' => $shoot->raw_photo_count,
                    'edited_photo_count' => $shoot->edited_photo_count,
                    'raw_missing_count' => $shoot->raw_missing_count,
                    'edited_missing_count' => $shoot->edited_missing_count,
                    'missing_raw' => $shoot->missing_raw,
                    'missing_final' => $shoot->missing_final,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => 500,
                'payload' => [
                    'error_type' => 'upload_failed',
                    'message' => 'Failed to upload files',
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    protected function parseSize(string $size): int
    {
        $size = trim($size);
        $last = strtolower($size[strlen($size) - 1] ?? '0');
        $value = (int) $size;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
