<?php

namespace App\Services;

use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * An upload whose bytes are already on disk but whose media record is not yet saved.
 *
 * Intake used to do everything inside one database transaction: the duplicate
 * lookup, EXIF extraction, the ClamAV stream, moving a 50MB RAW into place, and
 * only then the INSERT. On SQLite in WAL mode that is the worst possible shape.
 * The transaction begins deferred, the lookup pins a read snapshot, several
 * seconds of I/O pass while the queue workers commit thumbnails and scan
 * verdicts to the same file, and the INSERT is then refused with
 * SQLITE_BUSY_SNAPSHOT — reported as a bare "database is locked" and never
 * subject to busy_timeout. One raw frame in every ten or so came back as
 * "media record could not be saved" for no reason the photographer could act on.
 *
 * Splitting intake in two fixes the shape: the slow work happens here with no
 * transaction open, and {@see ShootMediaStorageService::persistStagedUpload()}
 * then does the database writes in a transaction short enough to retry.
 */
final class StagedShootUpload
{
    /**
     * @param  array<string, mixed>  $identity  Attributes a brand-new row is created with.
     * @param  array<string, mixed>  $attributes  Attributes filled on the new or replaced row.
     */
    public function __construct(
        public readonly Shoot $shoot,
        public readonly ?ShootFile $existingFile,
        public readonly array $identity,
        public readonly array $attributes,
        public readonly string $stage,
        public readonly string $originalFilename,
        public readonly string $storedFilename,
        public readonly string $storedPath,
        public readonly string $storageDisk,
        public readonly string $syncScanVerdict,
        public readonly bool $isOpaqueIguidePackage,
        public readonly bool $requiresImageProcessing,
    ) {}

    public function isReplacement(): bool
    {
        return $this->existingFile !== null;
    }

    /**
     * Remove the staged bytes when the record could not be saved.
     *
     * A replacement is deliberately left alone: its previous bytes were already
     * removed to make room, so deleting the new ones as well would leave the
     * existing row with nothing at all. Legacy behaviour, kept on purpose.
     */
    public function discard(): void
    {
        if ($this->isReplacement()) {
            return;
        }

        try {
            $disk = Storage::disk($this->storageDisk);
            if ($disk->exists($this->storedPath)) {
                $disk->delete($this->storedPath);
            }
        } catch (\Throwable $e) {
            Log::channel(ShootMediaStorageService::LOG_CHANNEL)->warning('Failed to discard staged upload bytes after the record could not be saved.', [
                'shoot_id' => $this->shoot->id,
                'path' => $this->storedPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
