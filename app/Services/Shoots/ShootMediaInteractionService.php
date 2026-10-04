<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\Actions\DeleteShootMediaAction;
use Illuminate\Support\Facades\DB;

class ShootMediaInteractionService
{
    public function __construct(
        protected DeleteShootMediaAction $deleteShootMediaAction,
        protected ShootMediaMutationSupportService $shootMediaMutationSupportService,
        protected DeliveryMediaOrderService $deliveryMediaOrderService
    ) {}

    public function toggleFavorite(ShootFile $file): array
    {
        $file->is_favorite = ! $file->is_favorite;
        $file->save();
        $shoot = $file->relationLoaded('shoot') ? $file->shoot : Shoot::find($file->shoot_id);
        if ($shoot) {
            $this->shootMediaMutationSupportService->clearShootFilesCache($shoot, auth()->user());
        }

        return [
            'message' => 'Favorite updated',
            'file' => $file->fresh(),
        ];
    }

    public function flagMedia(Shoot $shoot, ShootFile $file, ?string $reason, bool $clearFlag): array
    {
        if ($clearFlag) {
            $file->flag_reason = null;
            if ($file->workflow_stage === ShootFile::STAGE_FLAGGED) {
                $file->workflow_stage = ShootFile::STAGE_TODO;
            }
            $file->save();

            if ($shoot->files()->whereNotNull('flag_reason')->count() === 0) {
                $shoot->is_flagged = false;
                $shoot->admin_issue_notes = null;
                $shoot->save();
            }

            return [
                'message' => 'Flag cleared',
                'file' => $file->fresh(),
            ];
        }

        $file->flag_reason = $reason ?: 'Flagged via dashboard';
        $file->workflow_stage = ShootFile::STAGE_FLAGGED;
        $file->save();

        $shoot->is_flagged = true;
        $shoot->admin_issue_notes = $file->flag_reason;
        $shoot->save();

        return [
            'message' => 'File flagged',
            'file' => $file->fresh(),
        ];
    }

    public function addComment(ShootFile $file, string $author, string $comment): array
    {
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $comments = is_array($metadata['comments'] ?? null) ? $metadata['comments'] : [];
        $comments[] = [
            'author' => $author,
            'comment' => trim($comment),
            'timestamp' => now()->toIso8601String(),
        ];

        $file->metadata = array_merge($metadata, ['comments' => $comments]);
        $file->save();
        $shoot = $file->relationLoaded('shoot') ? $file->shoot : Shoot::find($file->shoot_id);
        if ($shoot) {
            $this->shootMediaMutationSupportService->clearShootFilesCache($shoot, auth()->user());
        }

        return [
            'message' => 'Comment added',
            'file' => $file->fresh(),
        ];
    }

    /**
     * Normalize + validate a display filename. Storage path / stored_filename stay put;
     * only shoot_files.filename is meant to change.
     *
     * Keeps the original extension (appends it when omitted; rejects mismatches).
     * Removes unsupported characters and path syntax from display names.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function normalizeDisplayFilename(string $incoming, string $originalFilename): string
    {
        // Keep this cleanup in sync with the frontend rename preview and input.
        $incoming = preg_replace('/[^\p{L}\p{N}_. ()\[\]-]/u', '', trim($incoming)) ?? '';
        $incoming = preg_replace('/\.{2,}/', '', $incoming) ?? '';
        if (preg_match('/^\.[^.]+$/', trim($incoming))) {
            $incoming = '';
        }
        $incoming = trim($incoming, ' .');

        if ($incoming === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'filename' => ['Enter a filename with supported characters.'],
            ]);
        }

        $originalExt = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $incomingExt = strtolower((string) pathinfo($incoming, PATHINFO_EXTENSION));

        if ($incomingExt === '') {
            if ($originalExt !== '') {
                $incoming .= '.'.$originalExt;
            }
        } elseif ($originalExt !== '' && $incomingExt !== $originalExt) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'filename' => ['Extension must match the original ('.$originalExt.').'],
            ]);
        }

        if (strlen($incoming) > 255) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'filename' => ['Filename may not be greater than 255 characters.'],
            ]);
        }

        return $incoming;
    }

    /**
     * Update the display filename only. Storage object / stored_filename stay put
     * so downloads keep resolving by path and emit the new name via Content-Disposition.
     *
     * @return array{message: string, data: array{id: int, filename: string, stored_filename: ?string}}
     */
    public function renameFile(ShootFile $file, string $filename, bool $clearCache = true): array
    {
        \App\Support\LockedWrite::run(function () use ($file, $filename) {
            $file->filename = $filename;
            $file->save();
        }, 'media.rename');

        $shoot = $file->relationLoaded('shoot') ? $file->shoot : Shoot::find($file->shoot_id);
        if ($clearCache && $shoot) {
            $this->shootMediaMutationSupportService->clearShootFilesCache($shoot, auth()->user());
        }

        $fresh = $file->fresh();

        return [
            'message' => 'Filename updated',
            'data' => [
                'id' => (int) $fresh->id,
                'filename' => (string) $fresh->filename,
                'stored_filename' => $fresh->stored_filename,
            ],
            'media_revision' => $shoot
                ? $this->shootMediaMutationSupportService->currentMediaRevision($shoot)
                : 0,
        ];
    }

    /**
     * Batch-rename display filenames in one short write: every valid rename applies, or
     * none does. Separate saves could each lose the SQLite writer to a queue worker,
     * which silently skipped some files. Caller handles auth gating and name validation.
     *
     * @param  list<array{file: ShootFile, filename: string}>  $renames
     * @return array{updated: list<array{id: int, filename: string, stored_filename: ?string}>, failed: list<array{id: int, error: string}>}
     */
    public function batchRenameFiles(Shoot $shoot, array $renames): array
    {
        $updated = \App\Support\LockedWrite::run(fn () => DB::transaction(function () use ($renames) {
            $updated = [];
            foreach ($renames as $item) {
                /** @var ShootFile $file */
                $file = $item['file'];
                $file->filename = $item['filename'];
                $file->save();
                $updated[] = ['id' => (int) $file->id, 'filename' => (string) $file->filename, 'stored_filename' => $file->stored_filename];
            }

            return $updated;
        }), 'media.batch-rename');

        if ($updated !== []) {
            $this->shootMediaMutationSupportService->clearShootFilesCache($shoot, auth()->user());
        }

        return [
            'updated' => $updated,
            'failed' => [],
        ];
    }

    public function bulkDelete(Shoot $shoot, iterable $files): array
    {
        $errors = [];
        $deletedIds = [];
        $actor = auth()->user();

        foreach ($files as $file) {
            try {
                $result = $this->deleteShootMediaAction->execute($shoot, $file);
                $deletedIds[] = (int) $file->id;
                $shoot = $shoot->fresh() ?? $shoot;
                if ($actor) {
                    app(\App\Services\AuditLogService::class)->record('media.delete', $actor, $shoot, [
                        'shoot_file_id' => (int) $file->id,
                        'filename' => $file->filename ?? null,
                        'bulk' => true,
                    ]);
                }
                // Prefer counters from the action when present.
                if (isset($result['raw_photo_count'])) {
                    $shoot->raw_photo_count = $result['raw_photo_count'];
                    $shoot->edited_photo_count = $result['edited_photo_count'];
                }
            } catch (\Exception $e) {
                $errors[] = $file->id;
            }
        }

        $shoot = $this->shootMediaMutationSupportService->refreshMediaCounters($shoot->fresh());
        $this->shootMediaMutationSupportService->clearShootFilesCache($shoot, $actor);

        return [
            'payload' => [
                'message' => empty($errors) ? 'Files deleted' : 'Some files failed to delete',
                'failed_ids' => $errors,
                'deleted_ids' => $deletedIds,
                'counts' => [
                    'raw_photo_count' => $shoot->raw_photo_count,
                    'edited_photo_count' => $shoot->edited_photo_count,
                    'extra_photo_count' => $shoot->extra_photo_count,
                    'raw_missing_count' => $shoot->raw_missing_count,
                    'edited_missing_count' => $shoot->edited_missing_count,
                ],
                'media_revision' => $this->shootMediaMutationSupportService->currentMediaRevision($shoot),
            ],
            'status' => empty($errors) ? 200 : 207,
        ];
    }

    public function reorderFiles(Shoot $shoot, array $fileIds, ?User $user = null): array
    {
        // 1-based so that "has a saved order" is distinguishable from "never
        // ordered". With 0-based positions the first file was written as 0, which
        // the client could not tell apart from an unset column (it reads
        // `sort_order ?? 0`), so a manual arrangement starting at position 0 was
        // silently treated as absent and re-derived from filename/capture time.
        // scopeInDeliveryOrder() relies on the same invariant on the server.
        $version = DB::transaction(function () use ($shoot, $fileIds) {
            foreach ($fileIds as $index => $fileId) {
                ShootFile::where('shoot_id', $shoot->id)
                    ->where('id', $fileId)
                    ->update(['sort_order' => $index + 1]);
            }

            // Bumping inside the transaction keeps the version and the positions
            // it describes atomic, so a cached archive can never be validated
            // against a version that does not match the rows on disk.
            $version = $this->deliveryMediaOrderService->bumpVersion($shoot);

            // A shoot that already snapshotted its delivery order (i.e. has been
            // finalized) must fold this reorder into that snapshot, otherwise
            // every delivery consumer keeps replaying the pre-reorder sequence
            // and the admin's change never reaches the client.
            $this->deliveryMediaOrderService->refreshSnapshotIfPresent($shoot);

            return $version;
        });

        $this->shootMediaMutationSupportService->clearShootFilesCache($shoot, $user);

        return [
            'message' => 'File order saved',
            'count' => count($fileIds),
            'media_order_version' => $version,
        ];
    }

    public function toggleHidden(Shoot $shoot, array $fileIds, bool $hidden): array
    {
        $updated = ShootFile::where('shoot_id', $shoot->id)
            ->whereIn('id', $fileIds)
            ->update(['is_hidden' => $hidden]);

        $this->shootMediaMutationSupportService->clearShootFilesCache($shoot);

        return [
            'message' => $hidden ? "Hidden {$updated} file(s)" : "Unhidden {$updated} file(s)",
            'updated_count' => $updated,
            'hidden' => $hidden,
        ];
    }

    /**
     * Mark selected files as a media type.
     *
     * "photos" / "edited" = Main photos: clears conflicting is_extra while
     * preserving shoot_service_id, unit attribution, and workflow_stage.
     * Floorplans marked as photos become edited Main photos (leave raw alone).
     *
     * @param  list<int|string>  $fileIds
     * @return array{message:string,updated_count:int,media_type:string,counts:array,media_revision:int,files:list<array>}
     */
    public function reclassify(Shoot $shoot, array $fileIds, string $mediaType): array
    {
        $requested = strtolower(trim($mediaType));
        $isMainPhotos = in_array($requested, ['photos', 'edited', 'main', 'main_photos'], true);
        $targetType = $isMainPhotos ? 'edited' : $requested;

        $files = ShootFile::query()
            ->where('shoot_id', $shoot->id)
            ->whereIn('id', $fileIds)
            ->get();

        $updated = 0;
        $payloadFiles = [];
        foreach ($files as $file) {
            $attrs = ['media_type' => $targetType];
            if ($isMainPhotos) {
                // Clear conflicting extras when promoting to Main photos.
                if (\Illuminate\Support\Facades\Schema::hasColumn('shoot_files', 'is_extra')) {
                    $attrs['is_extra'] = false;
                }
            }
            // Preserve service / unit / stage — never rewrite those here.
            $file->fill($attrs);
            if ($file->isDirty()) {
                $file->save();
                $updated++;
            }
            $payloadFiles[] = $this->shootMediaMutationSupportService->transformFile($file->fresh());
        }

        $shoot = $this->shootMediaMutationSupportService->refreshMediaCounters($shoot->fresh());
        $this->shootMediaMutationSupportService->clearShootFilesCache($shoot, auth()->user());
        $label = $isMainPhotos ? 'Main photos' : $targetType;

        return [
            'message' => "Reclassified {$updated} file(s) as {$label}",
            'updated_count' => $updated,
            'media_type' => $targetType,
            'counts' => [
                'raw_photo_count' => $shoot->raw_photo_count,
                'edited_photo_count' => $shoot->edited_photo_count,
                'extra_photo_count' => $shoot->extra_photo_count,
                'raw_missing_count' => $shoot->raw_missing_count,
                'edited_missing_count' => $shoot->edited_missing_count,
            ],
            'media_revision' => $this->shootMediaMutationSupportService->currentMediaRevision($shoot),
            'files' => $payloadFiles,
        ];
    }
}
