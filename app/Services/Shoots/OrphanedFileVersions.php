<?php

namespace App\Services\Shoots;

use App\Models\ShootFile;
use App\Models\ShootFileVersion;
use Illuminate\Support\Collection;

/**
 * Returned edits (shoot_file_versions) that can no longer be reviewed because
 * the photo they belong to was deleted.
 *
 * The Versions panel is opened from a photo, so once that photo is gone an
 * unresolved returned edit has no screen left to clear it from, and it used to
 * block finalise permanently (shoot 6038, 812 Tritapoe Drive, 2026-11-20).
 */
class OrphanedFileVersions
{
    /**
     * The states a staff "Dismiss" may withdraw. Queued/processing versions are
     * left to ProcessMediaVersion, which records `source_deleted` itself.
     */
    public const OPEN_STATUSES = ['conflict', 'failed', 'alternative'];

    /**
     * Withdraw the deleted photo's open returned edits, the same way
     * MediaVersionController::dismiss does (saved bytes are kept). Task
     * returns stay with the editing-task workflow.
     */
    public function dismissForDeletedFile(ShootFile $file): int
    {
        $versions = ShootFileVersion::query()
            ->where('shoot_id', $file->shoot_id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereNull('dispatch_item_id')
            ->where(fn ($query) => $query->where('target_file_id', $file->id)
                ->orWhere(fn ($query) => $query->whereNull('target_file_id')->where('source_file_id', $file->id)))
            ->get();

        foreach ($versions as $version) {
            $version->update([
                'status' => 'dismissed',
                'metadata' => array_merge($version->metadata ?? [], [
                    'dismissed_reason' => 'file_deleted',
                    'dismissed_at' => now()->toIso8601String(),
                ]),
            ]);
        }

        return $versions->count();
    }

    /**
     * Keep only versions whose photo still exists. A version with no photo
     * reference at all is kept, since it cannot be judged here.
     *
     * @param  Collection<int, ShootFileVersion>  $versions
     * @return Collection<int, ShootFileVersion>
     */
    public function withoutMissingFiles(Collection $versions): Collection
    {
        $fileIdFor = fn (ShootFileVersion $version) => $version->target_file_id ?: $version->source_file_id;
        $ids = $versions->map($fileIdFor)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return $versions;
        }
        $existing = ShootFile::query()->whereKey($ids->all())->pluck('id')->map(fn ($id) => (int) $id)->flip();

        return $versions->filter(function (ShootFileVersion $version) use ($fileIdFor, $existing) {
            $fileId = $fileIdFor($version);

            return ! $fileId || $existing->has((int) $fileId);
        })->values();
    }
}
