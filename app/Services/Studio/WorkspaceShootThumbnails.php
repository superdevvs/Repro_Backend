<?php

namespace App\Services\Studio;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootClientReleaseAccessService;
use Illuminate\Support\Facades\DB;

/** Resolve card images through the same authenticated previews as the source picker. */
final class WorkspaceShootThumbnails
{
    private const CANDIDATES_PER_SHOOT = 8;

    public function __construct(
        private ShootAuthorizationSupport $authorization,
        private ShootClientReleaseAccessService $releaseAccess,
    ) {}

    /** @return array<int, string> Only the requested, accessible page is considered. */
    public function forShoots(array $ids, User $user): array
    {
        if ($ids === []) {
            return [];
        }

        $shoots = $this->authorization->scopeAccessibleShootMedia(Shoot::query(), $user)
            ->whereKey($ids)->with('serviceItems.service')->get()->keyBy('id');
        $pending = $shoots->modelKeys();
        $thumbnails = [];
        $offset = 0;

        while ($pending !== []) {
            // Rank in SQL, then hydrate a bounded batch for each unresolved card.
            // Continue past inaccessible files rather than letting one editor's
            // cover hide another editor's permitted photo further down the shoot.
            $ranked = ShootFile::query()->whereIn('shoot_id', $pending)
                ->where('is_hidden', false)
                ->where(fn ($query) => $query->whereNull('scan_status')->orWhere('scan_status', ShootFile::SCAN_STATUS_CLEAN))
                ->select(['id', 'shoot_id'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY shoot_id ORDER BY is_cover DESC, '
                    ."CASE WHEN workflow_stage IN ('completed', 'verified') THEN 0 ELSE 1 END, "
                    .'CASE WHEN sort_order IS NULL OR sort_order <= 0 THEN 1 ELSE 0 END, sort_order, id) AS source_rank');
            $candidates = DB::query()->fromSub($ranked, 'source_candidates')
                ->whereBetween('source_rank', [$offset + 1, $offset + self::CANDIDATES_PER_SHOOT])
                ->orderBy('source_rank')->get();
            $files = ShootFile::query()->whereKey($candidates->pluck('id'))->get()->keyBy('id');

            foreach ($candidates as $candidate) {
                if (isset($thumbnails[$candidate->shoot_id])) {
                    continue;
                }
                $file = $files->get($candidate->id);
                $shoot = $shoots->get($candidate->shoot_id);
                if (! $file || ! $shoot || ! $file->isClearedForProcessing()
                    || $file->is_hidden || $file->is_extra
                    || in_array(strtolower((string) $file->media_type), ['floorplan', 'video', 'extra', 'reference', 'additional'], true)
                    || (! $this->authorization->isImageMediaFile($file) && ! $this->authorization->isRawCameraFile($file))) {
                    continue;
                }
                $file->setRelation('shoot', $shoot);
                $file->setRelation('serviceItem', $shoot->serviceItems->find($file->shoot_service_id));
                if (! $this->authorization->canInteractWithShootMediaFile($shoot, $file, $user)
                    || ($user->role === 'client' && (! $this->authorization->canDownloadShootMediaFile($shoot, $file, $user)
                        || $this->releaseAccess->isFileReleaseLocked($shoot, $file, $user)))) {
                    continue;
                }
                $thumbnails[$shoot->id] = url("/api/studio/workspaces/sources/files/{$file->id}/preview").'?size=thumbnail';
            }

            $counts = $candidates->countBy('shoot_id');
            $pending = array_values(array_filter($pending, fn ($id) => ! isset($thumbnails[$id])
                && $counts->get($id, 0) === self::CANDIDATES_PER_SHOOT));
            $offset += self::CANDIDATES_PER_SHOOT;
        }

        return $thumbnails;
    }
}
