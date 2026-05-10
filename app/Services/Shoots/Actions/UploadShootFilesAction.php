<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootMediaMutationSupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadShootFilesAction
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootMediaMutationSupportService $support,
        protected ShootAuthorizationSupport $authorizationSupport,
        protected ShootActivityLogger $activityLogger,
        protected AutoStackRawFilesAction $autoStackRawFilesAction
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
        $uploadLimits = $this->buildUploadLimits();
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
                        'upload_limits' => $uploadLimits,
                    ],
                ];
            }

            return [
                'status' => 422,
                'payload' => [
                    'error_type' => 'invalid_file',
                    'message' => 'No files received. The file may have been too large or the upload was interrupted.',
                        'errors' => ['files' => ['No valid files were received by the server.']],
                        'upload_limits' => $uploadLimits,
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
                        'upload_limits' => $uploadLimits,
                    ],
                ];
            }

            if ($file->getSize() > (2000 * 1024 * 1024)) {
                return [
                    'status' => 422,
                    'payload' => [
                        'error_type' => 'oversize',
                        'message' => 'File too large: ' . $file->getClientOriginalName(),
                        'errors' => [
                            $this->buildUploadError(
                                $file->getClientOriginalName(),
                                'oversize',
                                'File exceeds the 2GB upload limit.',
                                false,
                                'Split the upload into smaller files or export a smaller version before retrying.',
                            ),
                        ],
                        'upload_limits' => $uploadLimits,
                    ],
                ];
            }
        }

        $request->files->set('files', $files);
        $uploadType = $request->input('upload_type', 'raw');
        $shootServiceId = $request->input('shoot_service_id');
        $shootServiceId = $shootServiceId !== null && $shootServiceId !== '' ? (int) $shootServiceId : null;

        if ($shootServiceId && !$shoot->serviceItems()->whereKey($shootServiceId)->exists()) {
            return [
                'status' => 422,
                'payload' => [
                    'error_type' => 'invalid_service_item',
                    'message' => 'Selected service item does not belong to this shoot',
                    'upload_limits' => $uploadLimits,
                ],
            ];
        }

        if (
            $user
            && $user->role === 'photographer'
            && $shootServiceId
            && !$this->authorizationSupport->canPhotographerAccessServiceItem($shoot, $shootServiceId, $user)
        ) {
            return [
                'status' => 403,
                'payload' => [
                    'error_type' => 'forbidden',
                    'message' => 'You can only upload media for assigned service items',
                    'upload_limits' => $uploadLimits,
                ],
            ];
        }
        if (
            $user
            && $user->role === 'photographer'
            && !$shootServiceId
            && (string) $shoot->photographer_id !== (string) $user->id
        ) {
            return [
                'status' => 422,
                'payload' => [
                    'error_type' => 'missing_service_item',
                    'message' => 'Select an assigned service item for this upload',
                    'upload_limits' => $uploadLimits,
                ],
            ];
        }

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
                    'upload_limits' => $uploadLimits,
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
                    'upload_limits' => $uploadLimits,
                ],
            ];
        }

        $allowedEditedUploadStatuses = [
            Shoot::STATUS_UPLOADED,
            Shoot::STATUS_EDITING,
            Shoot::STATUS_READY,
        ];

        if ($uploadType === 'edited' && !$isAdmin && !in_array($shoot->workflow_status, $allowedEditedUploadStatuses, true)) {
            return [
                'status' => 400,
                'payload' => [
                    'error_type' => 'invalid_workflow_stage',
                    'message' => 'Cannot upload edited files at this workflow stage',
                    'current_status' => $shoot->workflow_status,
                    'upload_limits' => $uploadLimits,
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
            $rawBracketMode = $uploadType === 'raw' ? (int) ($request->input('bracket_mode') ?? $shoot->bracket_mode ?? 0) : 0;

            // Deterministic batch ordering for raw bracket grouping.
            // Each frontend upload sends one file per XHR but all files in a batch share
            // an upload_batch_id and an upload_batch_index. Without using the batch index,
            // parallel XHRs would all read the same pre-batch raw count and assign duplicate
            // bracket_group/sequence pairs. We claim a stable batch_offset on the first
            // request of a batch via Cache::add (atomic), then derive bracket_group and
            // sequence from (batch_offset + upload_batch_index).
            $rawBatchId = $uploadType === 'raw' ? trim((string) $request->input('upload_batch_id', '')) : '';
            $rawBatchIndex = $request->has('upload_batch_index') && is_numeric($request->input('upload_batch_index'))
                ? (int) $request->input('upload_batch_index')
                : null;
            $rawBatchOffset = null;
            if ($rawBracketMode > 1 && $rawBatchId !== '' && $rawBatchIndex !== null) {
                $batchOffsetCacheKey = "shoot:{$shoot->id}:raw_upload_batch:{$rawBatchId}:offset";
                $preBatchCount = $shoot->files()
                    ->where('workflow_stage', ShootFile::STAGE_TODO)
                    ->where('media_type', 'raw')
                    ->count();
                // Cache::add is atomic: only the first concurrent request wins,
                // subsequent requests read the value the winner stored.
                Cache::add($batchOffsetCacheKey, $preBatchCount, now()->addHours(2));
                $rawBatchOffset = (int) Cache::get($batchOffsetCacheKey, $preBatchCount);
            }

            $rawSequenceIndex = $rawBracketMode > 1
                ? $shoot->files()
                    ->where('workflow_stage', ShootFile::STAGE_TODO)
                    ->where('media_type', 'raw')
                    ->count()
                : 0;

            foreach ($files as $file) {
                try {
                    $resolvedMediaType = null;
                    if ($mediaTypeOverride && in_array($mediaTypeOverride, ['floorplan', 'extra', 'virtual_staging', 'green_grass', 'twilight', 'drone'], true)) {
                        $resolvedMediaType = $mediaTypeOverride;
                    } elseif ($isExtra) {
                        $resolvedMediaType = 'extra';
                    }

                    $shootFile = $uploadType === 'raw'
                        ? $this->dropboxService->uploadToTodo($shoot, $file, auth()->id(), $serviceCategory, $resolvedMediaType)
                        : $this->dropboxService->uploadToCompleted($shoot, $file, auth()->id(), $serviceCategory, $resolvedMediaType);

                    if ($shootServiceId && !$shootFile->shoot_service_id) {
                        $shootFile->shoot_service_id = $shootServiceId;
                        $shootFile->save();
                    }

                    if ($shootServiceId) {
                        $serviceItem = $shoot->serviceItems()->whereKey($shootServiceId)->first();
                        if ($serviceItem) {
                            if ($uploadType === 'raw' && !in_array($serviceItem->workflow_status, [
                                ShootService::WORKFLOW_READY,
                                ShootService::WORKFLOW_DELIVERED,
                                ShootService::WORKFLOW_CANCELLED,
                            ], true)) {
                                $serviceItem->forceFill([
                                    'workflow_status' => ShootService::WORKFLOW_IN_PROGRESS,
                                    'delivery_status' => $serviceItem->delivery_status ?: ShootService::DELIVERY_NOT_STARTED,
                                ])->save();
                            }

                            if ($uploadType === 'edited' && $serviceItem->workflow_status !== ShootService::WORKFLOW_DELIVERED) {
                                $serviceItem->forceFill([
                                    'workflow_status' => ShootService::WORKFLOW_READY,
                                    'delivery_status' => ShootService::DELIVERY_READY,
                                    'ready_at' => $serviceItem->ready_at ?? now(),
                                ])->save();
                            }
                        }
                    }

                    if ($uploadType === 'raw' && $rawBracketMode > 1 && $shootFile->media_type === 'raw') {
                        // Prefer the deterministic (batch_offset + batch_index) ordering when
                        // the frontend provides it (single source of truth across parallel XHRs).
                        // Fall back to the per-request count when the legacy single-request
                        // multi-file path is used (no batch metadata).
                        $orderingIndex = ($rawBatchOffset !== null && $rawBatchIndex !== null)
                            ? $rawBatchOffset + $rawBatchIndex
                            : $rawSequenceIndex;

                        $shootFile->update([
                            'bracket_group' => intdiv($orderingIndex, $rawBracketMode) + 1,
                            'sequence' => ($orderingIndex % $rawBracketMode) + 1,
                        ]);
                        $rawSequenceIndex++;
                    }

                    $thumbUrl = $shootFile->thumbnail_path ? Storage::disk('public')->url($shootFile->thumbnail_path) : null;
                    $webUrl = $shootFile->web_path ? Storage::disk('public')->url($shootFile->web_path) : null;

                    $uploadedFiles[] = [
                        'id' => $shootFile->id,
                        'filename' => $shootFile->filename,
                        'workflow_stage' => $shootFile->workflow_stage,
                        'dropbox_path' => $shootFile->dropbox_path,
                        'file_size' => $shootFile->file_size,
                        'shoot_service_id' => $shootFile->shoot_service_id,
                        'uploaded_at' => $shootFile->created_at,
                        'is_extra' => $shootFile->media_type === 'extra',
                        'bracket_group' => $shootFile->bracket_group,
                        'sequence' => $shootFile->sequence,
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
                    $classifiedError = $this->classifyUploadException($file->getClientOriginalName(), $e);
                    $errors[] = $classifiedError;

                    Log::warning('Shoot file upload failed for one file.', [
                        'shoot_id' => $shoot->id,
                        'file_name' => $file->getClientOriginalName(),
                        'error_type' => $classifiedError['error_type'],
                        'retryable' => $classifiedError['retryable'],
                        'message' => $classifiedError['message'],
                    ]);
                }
            }

            $shoot = $this->support->refreshMediaCounters($shoot->fresh());
            if ($shootServiceId) {
                $shoot->syncServiceItemRollups();
            }
            $this->support->clearShootFilesCache($shoot);

            // Note: Auto-transition to STATUS_READY on edited uploads has been removed.
            // Status changes are now owned exclusively by FinalizeEditedUploadAction
            // which is triggered by the user pressing "Submit Edits".

            DB::commit();

            // Auto-detect bracket groups from EXIF captured_at clustering. This runs
            // after each raw upload so the gallery reflects accurate stacks even mid-batch.
            // Errors here are non-fatal — they only impact the visual stacking heuristic.
            if ($uploadType === 'raw' && count($uploadedFiles) > 0) {
                try {
                    $autoStackResult = $this->autoStackRawFilesAction->execute($shoot);
                    if (($autoStackResult['updated_files'] ?? 0) > 0) {
                        $this->support->clearShootFilesCache($shoot->fresh());
                    }
                } catch (\Throwable $autoStackException) {
                    Log::warning('Auto-stacking raw files after upload failed', [
                        'shoot_id' => $shoot->id,
                        'error' => $autoStackException->getMessage(),
                    ]);
                }
            }

            if (count($uploadedFiles) > 0) {
                try {
                    $activityMetadata = [
                        'uploaded_by_role' => $user?->role,
                        'uploaded_by_name' => $user?->name,
                        'type' => $uploadType === 'edited' ? 'edited' : 'raw',
                        'shoot_service_id' => $shootServiceId,
                        'file_count' => count($uploadedFiles),
                        'file_ids' => array_values(array_filter(array_column($uploadedFiles, 'id'))),
                        'filenames' => array_values(array_filter(array_column($uploadedFiles, 'filename'))),
                    ];

                    $uploadBatchId = trim((string) $request->input('upload_batch_id', ''));
                    if ($uploadBatchId !== '') {
                        $activityMetadata['upload_batch_id'] = mb_substr($uploadBatchId, 0, 120);

                        $uploadBatchTotal = (int) $request->input('upload_batch_total', 0);
                        if ($uploadBatchTotal > 0) {
                            $activityMetadata['upload_batch_total'] = $uploadBatchTotal;
                        }

                        $uploadBatchIndex = $request->input('upload_batch_index');
                        if ($uploadBatchIndex !== null && $uploadBatchIndex !== '') {
                            $activityMetadata['upload_batch_index'] = (int) $uploadBatchIndex;
                        }
                    }

                    $this->activityLogger->logMediaUploaded(
                        $shoot,
                        $activityMetadata,
                        $user
                    );
                } catch (\Exception $activityException) {
                    Log::warning('Failed to log shoot media upload activity', [
                        'shoot_id' => $shoot->id,
                        'error' => $activityException->getMessage(),
                    ]);
                }
            }

            return [
                'status' => 200,
                'payload' => [
                    'message' => count($errors) > 0 && count($uploadedFiles) > 0 ? 'Files processed with some upload errors' : 'Files processed',
                    'uploaded_files' => $uploadedFiles,
                    'errors' => $errors,
                    'success_count' => count($uploadedFiles),
                    'error_count' => count($errors),
                    'partial_success' => count($uploadedFiles) > 0 && count($errors) > 0,
                    'upload_limits' => $uploadLimits,
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
                    'error_type' => 'server_error',
                    'message' => 'Failed to upload files',
                    'error' => $e->getMessage(),
                    'upload_limits' => $uploadLimits,
                ],
            ];
        }
    }

    /**
     * @return array{
     *   per_file:string,
     *   per_file_bytes:int,
     *   total_request:string,
     *   total_request_bytes:int,
     *   max_file_uploads:int
     * }
     */
    protected function buildUploadLimits(): array
    {
        return [
            'per_file' => '2GB',
            'per_file_bytes' => 2000 * 1024 * 1024,
            'total_request' => (string) ini_get('post_max_size'),
            'total_request_bytes' => $this->parseSize((string) ini_get('post_max_size')),
            'max_file_uploads' => (int) ini_get('max_file_uploads'),
        ];
    }

    /**
     * @return array{
     *   file_name:string,
     *   filename:string,
     *   error_type:string,
     *   message:string,
     *   retryable:bool,
     *   next_step:string|null
     * }
     */
    protected function buildUploadError(
        string $fileName,
        string $errorType,
        string $message,
        bool $retryable,
        ?string $nextStep = null
    ): array {
        return [
            'file_name' => $fileName,
            'filename' => $fileName,
            'error_type' => $errorType,
            'message' => $message,
            'retryable' => $retryable,
            'next_step' => $nextStep,
        ];
    }

    /**
     * @return array{
     *   file_name:string,
     *   filename:string,
     *   error_type:string,
     *   message:string,
     *   retryable:bool,
     *   next_step:string|null
     * }
     */
    protected function classifyUploadException(string $fileName, \Throwable $exception): array
    {
        $message = trim((string) $exception->getMessage());
        $lowerMessage = strtolower($message);
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $isRawFile = in_array($extension, ['nef', 'cr2', 'cr3', 'arw', 'dng', 'raf', 'rw2', 'orf', 'pef', 'srw'], true);

        if (str_contains($lowerMessage, 'permission') || str_contains($lowerMessage, 'forbidden')) {
            return $this->buildUploadError(
                $fileName,
                'forbidden',
                'You do not have permission to upload this file for the current shoot.',
                false,
                'Check the assigned role and shoot permissions before trying again.',
            );
        }

        if (str_contains($lowerMessage, 'workflow') || str_contains($lowerMessage, 'stage')) {
            return $this->buildUploadError(
                $fileName,
                'invalid_workflow_stage',
                'This shoot is not currently in a stage that accepts this upload.',
                false,
                'Move the shoot to the correct workflow stage before retrying.',
            );
        }

        if (str_contains($lowerMessage, 'network') || str_contains($lowerMessage, 'timeout') || str_contains($lowerMessage, 'connection')) {
            return $this->buildUploadError(
                $fileName,
                'network_failure',
                'The upload connection was interrupted before this file finished transferring.',
                true,
                'Retry this file. If the issue continues, check the network connection.',
            );
        }

        if (str_contains($lowerMessage, 'unsupported') || str_contains($lowerMessage, 'format')) {
            return $this->buildUploadError(
                $fileName,
                'unsupported_format',
                'This file format is not supported for the selected upload lane.',
                false,
                'Convert the file to a supported format before uploading again.',
            );
        }

        if (str_contains($lowerMessage, 'storage') || str_contains($lowerMessage, 'dropbox') || str_contains($lowerMessage, 'disk') || $isRawFile) {
            return $this->buildUploadError(
                $fileName,
                'storage_failure',
                $isRawFile
                    ? 'The RAW file could not be stored or processed after upload.'
                    : 'The file reached the server but could not be stored successfully.',
                true,
                'Retry this file. If it fails again, support should review the upload diagnostics.',
            );
        }

        return $this->buildUploadError(
            $fileName,
            'server_error',
            $message !== '' ? $message : 'The server could not finish processing this file.',
            true,
            'Retry this file. If the same error repeats, support should inspect the upload logs.',
        );
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
