<?php

namespace App\Services\Shoots\Actions;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use App\Services\DropboxWorkflowService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\BracketModeResolver;
use App\Services\Shoots\ShootEditingAssignmentService;
use App\Services\Shoots\ShootMediaMutationSupportService;
use App\Services\Shoots\ShootMediaReadService;
use App\Services\Shoots\ShootNotesCompatibilityService;
use App\Services\Shoots\ShootUploadIdempotencyService;
use App\Services\Shoots\UploadIntakeResolver;
use App\Services\UploadValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UploadShootFilesAction
{
    public function __construct(
        protected DropboxWorkflowService $dropboxService,
        protected ShootMediaMutationSupportService $support,
        protected ShootActivityLogger $activityLogger,
        protected AutoStackRawFilesAction $autoStackRawFilesAction,
        protected UploadValidationService $uploadValidation,
        protected ShootUploadIdempotencyService $uploadIdempotency,
        protected ShootMediaReadService $mediaReadService,
        protected ShootEditingAssignmentService $editingAssignmentService,
        protected ShootNotesCompatibilityService $notesCompatibilityService,
        protected BracketModeResolver $bracketModes,
        protected UploadIntakeResolver $uploadIntake
    ) {}

    public function execute(Request $request, Shoot $shoot, ?User $user): array
    {
        $files = $request->file('files');
        $normalizedFiles = $files ? (is_array($files) ? $files : [$files]) : [];

        // Ownership is checked before anything is recorded. The idempotency attempt
        // stores shoot_service_id as a foreign key, so an id that is not an execution
        // row on this shoot — a catalogue service id sent by mistake, or a row from
        // another shoot — used to fail as a constraint violation and surface as a 500.
        // The caller needs to be told plainly that the reference is wrong.
        $ownershipError = $this->rejectUnownedServiceItem($request, $shoot);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $claim = $this->uploadIdempotency->claim($request, $shoot, $user, $normalizedFiles);
        if ($claim['replay']) {
            return $claim['replay'];
        }

        $attempt = $claim['attempt'];
        try {
            $result = $this->executeUpload($request, $shoot, $user);
            if ($attempt) {
                $this->uploadIdempotency->finish($attempt, $result);
            }

            return $result;
        } catch (ValidationException $exception) {
            // Preserve Laravel's established field-keyed validation response for
            // legacy callers. Idempotent callers receive (and subsequently replay)
            // the same typed terminal validation result as other upload failures.
            if (! $attempt) {
                throw $exception;
            }

            $validationErrors = $exception->errors();
            $payload = [
                'error_type' => 'invalid_file',
                'message' => 'One or more files could not be accepted.',
                'uploaded_files' => [],
                'errors' => $validationErrors,
                'validation_errors' => $validationErrors,
                'success_count' => 0,
                'error_count' => max(1, count($validationErrors)),
                'partial_success' => false,
            ];
            $this->uploadIdempotency->fail($attempt, $payload, 422);

            return ['status' => 422, 'payload' => $payload];
        } catch (\Throwable $exception) {
            $correlationId = $attempt?->correlation_id ?? (string) Str::uuid();
            $payload = [
                'error_type' => 'server_error',
                'message' => 'Failed to upload files.',
                'uploaded_files' => [],
                'errors' => [[
                    'error_type' => 'server_error',
                    'message' => 'The server could not finish this upload.',
                    'retryable' => true,
                ]],
                'success_count' => 0,
                'error_count' => 1,
                'partial_success' => false,
                'correlation_id' => $correlationId,
            ];

            Log::error('Shoot upload attempt failed unexpectedly.', [
                'shoot_id' => $shoot->id,
                'actor_id' => $user?->id,
                'correlation_id' => $correlationId,
                'exception' => $exception::class,
            ]);

            if ($attempt) {
                $this->uploadIdempotency->fail($attempt, $payload);
            }

            return ['status' => 500, 'payload' => $payload];
        }
    }

    private function executeUpload(Request $request, Shoot $shoot, ?User $user): array
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
        if (! $files) {
            $contentLength = (int) $request->header('Content-Length', 0);
            $postMaxSize = $this->parseSize(ini_get('post_max_size'));

            if ($contentLength > $postMaxSize) {
                return [
                    'status' => 413,
                    'payload' => [
                        'error_type' => 'oversize',
                        'message' => 'Upload too large. Maximum allowed: '.ini_get('post_max_size'),
                        'errors' => ['files' => ['The uploaded file exceeds the server limit of '.ini_get('post_max_size')]],
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

        if (! is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if (! $file->isValid()) {
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
                        'message' => 'File too large: '.$file->getClientOriginalName(),
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

        // Pre-scan validation (Req 14.5/14.6): reject oversize / disallowed-type
        // files with HTTP 422 BEFORE creating any ShootFile row or enqueuing a
        // scan job. ValidationException is rendered by Laravel's exception
        // handler as a 422 JSON response with field-keyed errors. Runs after
        // the legacy in-action 2GB cap and isValid() checks so existing
        // response shapes for those specific cases are preserved; this catches
        // anything still left (e.g. > configured per-file limit but <= 2GB,
        // or a disallowed extension).
        $this->uploadValidation->validateMany($files, 'files', $user?->role);

        $request->files->set('files', $files);
        $uploadType = $request->input('upload_type', 'raw');
        $shootServiceId = $request->input('shoot_service_id');
        $shootServiceId = $shootServiceId !== null && $shootServiceId !== '' ? (int) $shootServiceId : null;

        $allServiceItems = $shoot->serviceItems()->with('service')->get();

        // Which raw lane(s) this batch actually needs. Derived from the files' mime
        // types, unioned with any lane the client declared, so a video file in a batch
        // still has to satisfy the video lane even if the caller said "photo".
        // Only raw capture is lane-gated here: edited uploads are already constrained
        // by the separate requires-editing capability.
        $requiredLanes = $uploadType === 'raw'
            ? $this->uploadIntake->requiredLanes($request->input('upload_lane'), is_array($files) ? $files : [$files])
            : [];

        // Eligibility for raw capture means the service actually declares the lane.
        // Without this, a shoot whose only booked service is a Matterport tour would
        // auto-select that tour and silently attach camera files to it.
        $laneEligible = function (ShootService $item) use ($requiredLanes): bool {
            if ($requiredLanes === []) {
                return true;
            }

            return $this->uploadIntake->unsupportedLanes($item, $requiredLanes) === [];
        };

        if ($user?->role === 'photographer' && $allServiceItems->isNotEmpty()) {
            $eligibleItems = $allServiceItems
                ->filter(fn (ShootService $item) => (string) $item->photographer_id === (string) $user->id
                    || (
                        ! $item->photographer_id
                        && (string) $shoot->photographer_id === (string) $user->id
                    )
                )
                ->filter($laneEligible)
                ->values();

            if ($shootServiceId && ! $eligibleItems->contains(fn (ShootService $item) => (int) $item->id === $shootServiceId)) {
                return [
                    'status' => 403,
                    'payload' => $this->typedUploadError(
                        'forbidden',
                        'You can only upload media for service items assigned directly to you.',
                        $uploadLimits
                    ),
                ];
            }

            if (! $shootServiceId && $eligibleItems->count() === 1) {
                $shootServiceId = (int) $eligibleItems->first()->id;
            } elseif (! $shootServiceId && $eligibleItems->count() > 1) {
                return [
                    'status' => 422,
                    'payload' => $this->typedUploadError(
                        'missing_service_item',
                        'Select one assigned service item for this upload batch.',
                        $uploadLimits
                    ),
                ];
            } elseif (! $shootServiceId && $eligibleItems->isEmpty()) {
                return [
                    'status' => 403,
                    'payload' => $this->typedUploadError(
                        'forbidden',
                        'No uploadable service item is assigned to you.',
                        $uploadLimits
                    ),
                ];
            }
        }

        if ($user?->role === 'editor' && $uploadType === 'edited' && $allServiceItems->isNotEmpty()) {
            $eligibleItems = $allServiceItems
                ->filter(fn (ShootService $item) => $item->service?->requiresEditing() ?? true)
                ->filter(function (ShootService $item) use ($shoot, $user) {
                    if ((string) $shoot->editor_id === (string) $user->id) {
                        // The legacy top-level editor owns every editing-required item.
                        return true;
                    }

                    return (string) $item->editor_id === (string) $user->id;
                })
                ->values();

            if ($shootServiceId && ! $eligibleItems->contains(fn (ShootService $item) => (int) $item->id === $shootServiceId)) {
                return [
                    'status' => 403,
                    'payload' => $this->typedUploadError(
                        'forbidden',
                        'You can only upload edits for service items assigned to you.',
                        $uploadLimits
                    ),
                ];
            }

            if (! $shootServiceId && $eligibleItems->count() === 1) {
                $shootServiceId = (int) $eligibleItems->first()->id;
            } elseif (! $shootServiceId && $eligibleItems->count() > 1) {
                return [
                    'status' => 422,
                    'payload' => $this->typedUploadError(
                        'missing_service_item',
                        'Select one editing service item for this upload batch.',
                        $uploadLimits
                    ),
                ];
            } elseif (! $shootServiceId && $eligibleItems->isEmpty()) {
                return [
                    'status' => 403,
                    'payload' => $this->typedUploadError(
                        'forbidden',
                        'No editing-required service item is assigned to you.',
                        $uploadLimits
                    ),
                ];
            }
        }

        if ($shootServiceId && ! $shoot->serviceItems()->whereKey($shootServiceId)->exists()) {
            return [
                'status' => 422,
                'payload' => [
                    'error_type' => 'invalid_service_item',
                    'message' => 'Selected service item does not belong to this shoot',
                    'upload_limits' => $uploadLimits,
                ],
            ];
        }

        // The authoritative eligibility gate. Client-side filtering is presentation
        // only: this endpoint is reachable directly, so an ineligible service must be
        // refused here regardless of what any UI offered. It also catches a caller
        // that sent a catalogue service id where an execution row id was required —
        // such an id either does not resolve to a row on this shoot at all, or
        // resolves to one whose capability is checked here like any other.
        if ($shootServiceId && $requiredLanes !== []) {
            $selectedItem = $shoot->serviceItems()->with('service')->whereKey($shootServiceId)->first();

            if ($selectedItem) {
                $unsupported = $this->uploadIntake->unsupportedLanes($selectedItem, $requiredLanes);

                if ($unsupported !== []) {
                    Log::info('Rejected upload for a service item that does not accept the requested lane.', [
                        'shoot_id' => $shoot->id,
                        'shoot_service_id' => $shootServiceId,
                        'service_id' => $selectedItem->service_id,
                        'actor_id' => $user?->id,
                        'requested_lanes' => $requiredLanes,
                        'unsupported_lanes' => $unsupported,
                        'upload_intake_type' => $this->uploadIntake->intakeTypeFor($selectedItem),
                    ]);

                    return [
                        'status' => 422,
                        'payload' => $this->typedUploadError(
                            'service_item_not_uploadable',
                            'This service does not accept '
                                .implode(' or ', $unsupported)
                                .' uploads. Choose a service that covers this media.',
                            $uploadLimits
                        ),
                    ];
                }
            }
        }

        if (
            $user
            && $user->role === 'photographer'
            && $shootServiceId
            && ! $allServiceItems->contains(fn (ShootService $item) => (int) $item->id === $shootServiceId
                && (
                    (string) $item->photographer_id === (string) $user->id
                    || (
                        ! $item->photographer_id
                        && (string) $shoot->photographer_id === (string) $user->id
                    )
                )
            )
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
            && ! $shootServiceId
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

        // Uploading no longer defines bracket state.
        //
        // This used to write `shoots.bracket_mode` and recompute
        // `shoots.expected_raw_count` from any raw request that carried a
        // bracket_mode, which meant one photographer's upload silently redefined
        // the divisor for every service on the shoot — including another
        // photographer's work. Bracket size is now an execution property of the
        // shoot-service assignment, snapshotted when the photographer is assigned
        // and changed only through a deliberate Change & Restack. Upload reads it.
        //
        // The expected raw count is likewise no longer stored: it is the sum over
        // service items of photo_count x that item's own bracket size, which a
        // single shoot-wide multiplication cannot express once two services differ.

        if ($request->has('photographer_notes')) {
            $previousPhotographerNotes = $shoot->photographer_notes;
            $shoot->photographer_notes = $request->input('photographer_notes');
            $shoot->save();
            $this->notesCompatibilityService->syncScalarField(
                $shoot,
                'photographer_notes',
                $shoot->photographer_notes,
                $user,
                $previousPhotographerNotes,
            );
        }

        $isAdmin = $user && in_array($user->role, ['admin', 'superadmin', 'editing_manager'], true);
        $isEditor = $user && $user->role === 'editor';
        // An editor may upload edited revisions only for shoots they are assigned to
        // (admin tiers stay unrestricted). This scopes post-delivery revision uploads to
        // the assigned editor and blocks unassigned editors. Mirrors the assignment gate
        // already used for photographers above and for media access in
        // ShootAuthorizationSupport.
        $editorHasEditingAssignment = $isEditor
            && $this->editingAssignmentService->editorHasAssignment($shoot, $user);
        $isEditedUploadManager = $isAdmin || $editorHasEditingAssignment;
        // Photographers may upload raw revisions even after delivery (client revision
        // requests). Service-item / assignment scoping is already enforced above, so a
        // photographer reaching this point is assigned to the shoot/service item.
        $canBypassRawStage = $isAdmin || ($user && $user->role === 'photographer');

        if ($uploadType === 'raw' && ! $canBypassRawStage && ! $shoot->canUploadPhotos()) {
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

        if ($uploadType === 'edited' && ! $isEditedUploadManager) {
            return [
                'status' => 403,
                'payload' => [
                    'error_type' => 'forbidden',
                    'message' => $isEditor
                        ? 'You can only upload edited files for shoots assigned to you'
                        : 'You do not have permission to upload edited files',
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

        // Editors (and admin tiers) may upload edited revisions at any stage, including
        // after delivery, so client-requested revisions can be delivered post-handoff.
        if ($uploadType === 'edited' && ! $isEditedUploadManager && ! in_array($shoot->workflow_status, $allowedEditedUploadStatuses, true)) {
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

        try {
            $isExtra = $request->boolean('is_extra', false);
            $requiredForEditing = $request->has('required_for_editing')
                ? $request->boolean('required_for_editing')
                : $request->boolean('requiredForEditing', false);
            $mediaTypeOverride = $request->input('media_type');
            // A per-file treatment request (virtual staging / green grass / twilight).
            // Kept strictly apart from $mediaTypeOverride: a treated frame must stay
            // media_type='raw' so it remains in its service's bracket stacks, in the
            // Photos tab and in delivery. Anything unrecognised normalises to null.
            $treatment = ShootFile::normalizeTreatment($request->input('treatment'));
            $serviceCategory = $request->input('service_category');

            // The divisor comes from the service item being uploaded to, never from
            // the request. An incoming bracket_mode is not authoritative: it is the
            // client's view of the service's configured size, and trusting it would
            // let any upload redefine how a service's stacks are cut. A service that
            // does not bracket resolves to 0 here no matter what was sent, so a
            // floor-plan or drone upload can never take stack numbers.
            $rawBracketMode = 0;
            if ($uploadType === 'raw' && $shootServiceId) {
                $serviceItem = $shoot->serviceItems()
                    ->with(['service', 'shoot'])
                    ->whereKey($shootServiceId)
                    ->first();
                // Bracketing is a property of the photo lane only. A bundled service
                // that serves both lanes still must not stack its video raws, so the
                // batch has to be photo-only before any stack size is applied.
                $bracketableBatch = $serviceItem
                    && $requiredLanes === [\App\Models\Service::LANE_PHOTO];
                $rawBracketMode = $bracketableBatch
                    ? (int) ($this->bracketModes->effectiveBracketMode($serviceItem) ?? 0)
                    : 0;
            } elseif ($uploadType === 'raw') {
                // Unassigned uploads have no service item to read, so they fall back
                // to the legacy shoot-wide value for compatibility.
                $rawBracketMode = (int) ($this->bracketModes->normalize($shoot->bracket_mode) ?? 0);
            }

            // Deterministic batch ordering for raw bracket grouping.
            // Each frontend upload sends one file per XHR but all files in a batch share
            // an upload_batch_id and an upload_batch_index. Without using the batch index,
            // parallel XHRs would all read the same pre-batch raw count and assign duplicate
            // bracket_group/sequence pairs. We claim a stable batch_offset on the first
            // request of a batch via Cache::add (atomic), then derive bracket_group and
            // sequence from (batch_offset + upload_batch_index).
            //
            // Both counts are scoped to the service item this upload belongs to. A shoot
            // can book several photo services, and counting the whole shoot let one
            // service continue another's incomplete stack: an Exterior set of 7 frames at
            // 5x leaves stack 2 holding frames 1-2, and the next service's first file was
            // then numbered stack 2 frame 3 — a stack straddling two services. Scoping the
            // count keeps each service's stacks its own while still letting a later upload
            // to the SAME service legitimately continue its own trailing partial stack.
            // Unassigned uploads are counted against each other via whereNull rather than
            // being lumped in with every assigned service.
            $rawBracketScope = function ($query) use ($shootServiceId) {
                $query->where('workflow_stage', ShootFile::STAGE_TODO)
                    ->where('media_type', 'raw');

                return $shootServiceId
                    ? $query->where('shoot_service_id', $shootServiceId)
                    : $query->whereNull('shoot_service_id');
            };

            $rawBatchId = $uploadType === 'raw' ? trim((string) $request->input('upload_batch_id', '')) : '';
            $rawBatchIndex = $request->has('upload_batch_index') && is_numeric($request->input('upload_batch_index'))
                ? (int) $request->input('upload_batch_index')
                : null;
            $rawBatchOffset = null;
            if ($rawBracketMode > 1 && $rawBatchId !== '' && $rawBatchIndex !== null) {
                // The batch id is unique per upload group, so the cached offset is already
                // per service; the service scoping below is what makes its value correct.
                $batchOffsetCacheKey = "shoot:{$shoot->id}:raw_upload_batch:{$rawBatchId}:offset";
                $preBatchCount = $rawBracketScope($shoot->files())->count();
                // Cache::add is atomic: only the first concurrent request wins,
                // subsequent requests read the value the winner stored.
                Cache::add($batchOffsetCacheKey, $preBatchCount, now()->addHours(2));
                $rawBatchOffset = (int) Cache::get($batchOffsetCacheKey, $preBatchCount);
            }

            $rawSequenceIndex = $rawBracketMode > 1
                ? $rawBracketScope($shoot->files())->count()
                : 0;

            foreach ($files as $file) {
                $shootFile = null;
                try {
                    DB::beginTransaction();
                    $resolvedMediaType = null;
                    if ($mediaTypeOverride && in_array($mediaTypeOverride, ['floorplan', 'extra', 'virtual_staging', 'green_grass', 'twilight', 'drone'], true)) {
                        $resolvedMediaType = $mediaTypeOverride;
                    } elseif ($isExtra) {
                        $resolvedMediaType = 'extra';
                    }

                    // The execution row is passed into storage so its replace-in-place
                    // duplicate check is scoped to that row. Without it, two services on
                    // one shoot receiving the same filename collapsed into one file.
                    $shootFile = $uploadType === 'raw'
                        ? $this->dropboxService->uploadToTodo($shoot, $file, auth()->id(), $serviceCategory, $resolvedMediaType, $shootServiceId)
                        : $this->dropboxService->uploadToCompleted($shoot, $file, auth()->id(), $serviceCategory, $resolvedMediaType, $shootServiceId);

                    if ($shootServiceId && ! $shootFile->shoot_service_id) {
                        $shootFile->shoot_service_id = $shootServiceId;
                        $shootFile->save();
                    }

                    $flagUpdates = [];
                    // Recorded alongside the capture identity, not instead of it. Only
                    // written when asked for, so an untreated frame keeps a null column
                    // rather than an empty string.
                    if ($treatment !== null && Schema::hasColumn('shoot_files', 'treatment')) {
                        $flagUpdates['treatment'] = $treatment;
                    }
                    if (Schema::hasColumn('shoot_files', 'is_extra')) {
                        $flagUpdates['is_extra'] = $isExtra || $shootFile->media_type === 'extra';
                    }
                    if (Schema::hasColumn('shoot_files', 'required_for_editing')) {
                        $flagUpdates['required_for_editing'] = ($isExtra || $shootFile->media_type === 'extra')
                            && $requiredForEditing;
                    }
                    if ($flagUpdates !== []) {
                        $shootFile->forceFill($flagUpdates)->save();
                        $shootFile->refresh();
                    }

                    if ($shootServiceId) {
                        $serviceItem = $shoot->serviceItems()->whereKey($shootServiceId)->first();
                        if ($serviceItem) {
                            if ($uploadType === 'raw' && ! in_array($serviceItem->workflow_status, [
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

                    // Generate a renderable preview for floorplan uploads (PDF -> page JPGs,
                    // or link the image) so they don't render as empty cards. Non-fatal.
                    if ($shootFile->media_type === 'floorplan') {
                        try {
                            app(\App\Services\Shoots\FloorplanPreviewService::class)->ensurePreview($shootFile);
                            $shootFile->refresh();
                        } catch (\Throwable $e) {
                            Log::warning('Floorplan preview generation failed on upload', [
                                'shoot_file_id' => $shootFile->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // A file becomes accepted only after its own database work commits.
                    // Other files in the same batch are not rolled back with it.
                    DB::commit();
                    $uploadedFiles[] = $this->mediaReadService->formatUploadedFile($shootFile);
                } catch (\Throwable $e) {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }

                    // Remove only assets created by this failed attempt. Existing-file
                    // replacements are intentionally left alone for legacy safety.
                    if ($shootFile?->wasRecentlyCreated) {
                        try {
                            $this->support->deleteStoredAssets($shootFile);
                        } catch (\Throwable $cleanupException) {
                            Log::warning('Failed to compensate upload storage after DB failure.', [
                                'shoot_id' => $shoot->id,
                                'shoot_file_id' => $shootFile->id,
                                'exception' => $cleanupException::class,
                            ]);
                        }
                    }

                    $classifiedError = $this->classifyUploadException($file->getClientOriginalName(), $e);
                    $errors[] = $classifiedError;

                    Log::warning('Shoot file upload failed for one file.', [
                        'shoot_id' => $shoot->id,
                        'file_name' => $file->getClientOriginalName(),
                        'error_type' => $classifiedError['error_type'],
                        'retryable' => $classifiedError['retryable'],
                        'exception' => $e::class,
                    ]);
                }
            }

            // Counters, rollups, and cache invalidation are secondary refreshes. A
            // failure here cannot relabel already committed files as failed uploads.
            try {
                $shoot = $this->support->refreshMediaCounters($shoot->fresh());
                if ($shootServiceId) {
                    $shoot->syncServiceItemRollups();
                }
                $this->support->clearShootFilesCache($shoot);
            } catch (\Throwable $refreshException) {
                $shoot = $shoot->fresh() ?? $shoot;
                Log::warning('Media refresh failed after accepted uploads.', [
                    'shoot_id' => $shoot->id,
                    'exception' => $refreshException::class,
                ]);
            }

            // Note: Auto-transition to STATUS_READY on edited uploads has been removed.
            // Status changes are now owned exclusively by FinalizeEditedUploadAction
            // which is triggered by the user pressing "Submit Edits".

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
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $correlationId = (string) Str::uuid();
            Log::error('Shoot upload orchestration failed.', [
                'shoot_id' => $shoot->id,
                'actor_id' => $user?->id,
                'correlation_id' => $correlationId,
                'exception' => $e::class,
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'error_type' => 'server_error',
                    'message' => 'Failed to upload files',
                    'uploaded_files' => $uploadedFiles,
                    'errors' => [[
                        'error_type' => 'server_error',
                        'message' => 'The server could not finish refreshing this upload.',
                        'retryable' => true,
                    ]],
                    'success_count' => count($uploadedFiles),
                    'error_count' => 1,
                    'partial_success' => count($uploadedFiles) > 0,
                    'correlation_id' => $correlationId,
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
     * Refuse a `shoot_service_id` that is not an execution row on this shoot.
     *
     * Returns null when there is nothing to object to, so the caller can carry on.
     *
     * @return array{status: int, payload: array<string, mixed>}|null
     */
    private function rejectUnownedServiceItem(Request $request, Shoot $shoot): ?array
    {
        $raw = $request->input('shoot_service_id');
        if ($raw === null || $raw === '') {
            return null;
        }

        if ($shoot->serviceItems()->whereKey((int) $raw)->exists()) {
            return null;
        }

        Log::info('Rejected upload for a service item that does not belong to this shoot.', [
            'shoot_id' => $shoot->id,
            'supplied_shoot_service_id' => $raw,
        ]);

        return [
            'status' => 422,
            'payload' => [
                'error_type' => 'invalid_service_item',
                'message' => 'Selected service item does not belong to this shoot',
                'uploaded_files' => [],
                'errors' => [[
                    'error_type' => 'invalid_service_item',
                    'message' => 'Selected service item does not belong to this shoot.',
                    'retryable' => false,
                ]],
                'success_count' => 0,
                'error_count' => 1,
                'partial_success' => false,
                'upload_limits' => $this->buildUploadLimits(),
            ],
        ];
    }

    protected function typedUploadError(string $errorType, string $message, array $uploadLimits): array
    {
        return [
            'error_type' => $errorType,
            'message' => $message,
            'uploaded_files' => [],
            'errors' => [
                $this->buildUploadError(
                    '',
                    $errorType,
                    $message,
                    false,
                    $errorType === 'missing_service_item' ? 'Choose a service before uploading.' : null
                ),
            ],
            'success_count' => 0,
            'error_count' => 1,
            'partial_success' => false,
            'upload_limits' => $uploadLimits,
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

        if ($exception instanceof \Illuminate\Database\QueryException) {
            return $this->buildUploadError(
                $fileName,
                'storage_failure',
                'The file reached the server but its media record could not be saved.',
                true,
                'Retry this file. If it fails again, support should review the upload correlation logs.',
            );
        }

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
