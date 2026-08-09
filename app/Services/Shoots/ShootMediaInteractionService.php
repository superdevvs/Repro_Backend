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
    ) {
    }

    public function toggleFavorite(ShootFile $file): array
    {
        $file->is_favorite = !$file->is_favorite;
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

    public function bulkDelete(Shoot $shoot, iterable $files): array
    {
        $errors = [];

        foreach ($files as $file) {
            try {
                $this->deleteShootMediaAction->execute($shoot, $file);
            } catch (\Exception $e) {
                $errors[] = $file->id;
            }
        }

        return [
            'payload' => [
                'message' => empty($errors) ? 'Files deleted' : 'Some files failed to delete',
                'failed_ids' => $errors,
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

    public function reclassify(Shoot $shoot, array $fileIds, string $mediaType): array
    {
        $updated = ShootFile::where('shoot_id', $shoot->id)
            ->whereIn('id', $fileIds)
            ->update(['media_type' => $mediaType]);

        $shoot = $this->shootMediaMutationSupportService->refreshMediaCounters($shoot->fresh());
        $this->shootMediaMutationSupportService->clearShootFilesCache($shoot, auth()->user());

        return [
            'message' => "Reclassified {$updated} file(s) as {$mediaType}",
            'updated_count' => $updated,
        ];
    }
}
