<?php

namespace App\Services\Shoots;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The single owner of "may this booked service receive files through this lane".
 *
 * A commercial catalogue entry is not automatically an upload target. Booking,
 * invoicing, photographer assignment, provider eligibility and the dedicated tour
 * workflows all legitimately reference services that never accept camera output —
 * fees, travel, digital enhancements, floor plans and 3D tour products. Upload
 * eligibility is therefore its own capability (`services.upload_intake_type`) rather
 * than something inferred from a category or a name.
 *
 * Two lanes exist, and a service opts into either, both, or neither:
 *
 *   photo lane   accepts `photo` and `photo_video`
 *   video lane   accepts `video` and `photo_video`
 *   none         accepts nothing
 *
 * Bracket capability is deliberately downstream of this: exposure stacking only ever
 * applies to a service that is photo-capable AND flagged `uses_hdr_brackets`. That
 * keeps drone (real photo capture, no stacking) and video-only work correct without
 * either one being a special case.
 *
 * Nothing here reads a service name.
 */
class UploadIntakeResolver
{
    /**
     * @var array<string, bool>
     */
    private static array $columnCache = [];

    /**
     * The declared capability for a booked execution row.
     *
     * Falls back to `none` when the capability column has not been migrated yet, so a
     * partially-migrated database refuses to widen access rather than silently
     * treating every service as uploadable.
     */
    public function intakeTypeFor(ShootService $item): string
    {
        if (! $this->capabilityAvailable()) {
            return Service::INTAKE_NONE;
        }

        return $this->serviceFor($item)?->uploadIntakeType() ?? Service::INTAKE_NONE;
    }

    public function supportsLane(ShootService $item, string $lane): bool
    {
        if (! $this->capabilityAvailable()) {
            return false;
        }

        return (bool) $this->serviceFor($item)?->supportsIntakeLane($lane);
    }

    public function supportsPhotoIntake(ShootService $item): bool
    {
        return $this->supportsLane($item, Service::LANE_PHOTO);
    }

    public function supportsVideoIntake(ShootService $item): bool
    {
        return $this->supportsLane($item, Service::LANE_VIDEO);
    }

    /**
     * Booked execution rows on this shoot that can receive the given lane.
     *
     * @return Collection<int, ShootService>
     */
    public function eligibleItemsForLane(Shoot $shoot, string $lane): Collection
    {
        $items = $shoot->relationLoaded('serviceItems')
            ? $shoot->getRelation('serviceItems')
            : $shoot->serviceItems()->with('service')->get();

        return $items
            ->filter(fn (ShootService $item) => $this->supportsLane($item, $lane))
            ->values();
    }

    /**
     * Whether a lane name is one the product actually has.
     */
    public function isKnownLane(mixed $lane): bool
    {
        return is_string($lane) && in_array($lane, Service::UPLOAD_LANES, true);
    }

    /**
     * The lane a single file belongs to.
     *
     * Mime type is the only signal used. Filenames and service names are not
     * consulted: a lane decision that depended on naming is exactly the guessing this
     * capability replaced.
     */
    public function laneForMimeType(?string $mimeType): string
    {
        return is_string($mimeType) && str_starts_with(strtolower($mimeType), 'video/')
            ? Service::LANE_VIDEO
            : Service::LANE_PHOTO;
    }

    /**
     * Every lane represented in an upload batch, plus any explicitly declared lane.
     *
     * The union is intentional. A batch that carries a video file must satisfy the
     * video lane even if the caller declared `photo`, and a caller's declared lane is
     * still honoured for an empty or ambiguous batch. A service must support every
     * returned lane for the upload to proceed.
     *
     * @param  iterable<mixed>  $files
     * @return list<string>
     */
    public function requiredLanes(mixed $declaredLane, iterable $files = []): array
    {
        $lanes = [];

        if ($this->isKnownLane($declaredLane)) {
            $lanes[] = $declaredLane;
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $lanes[] = $this->laneForMimeType($file->getMimeType());
        }

        $lanes = array_values(array_unique($lanes));

        // With nothing to go on, treat the batch as photo intake. That is the
        // conservative choice here: it still demands an explicitly photo-capable
        // service rather than admitting anything that is merely "not video".
        return $lanes === [] ? [Service::LANE_PHOTO] : $lanes;
    }

    /**
     * Lanes in a batch that the given execution row cannot accept.
     *
     * @param  list<string>  $lanes
     * @return list<string>
     */
    public function unsupportedLanes(ShootService $item, array $lanes): array
    {
        return array_values(array_filter(
            $lanes,
            fn (string $lane) => ! $this->supportsLane($item, $lane)
        ));
    }

    public function serviceFor(ShootService $item): ?Service
    {
        return $item->relationLoaded('service') ? $item->getRelation('service') : $item->service;
    }

    /**
     * Final photos this execution row is contracted to deliver, or null when the
     * product does not fix a number.
     *
     * Returns null for anything that is not photo-capable as well, so a video-only or
     * fee row is never treated as owing photos.
     */
    public function contractedPhotoCount(ShootService $item): ?int
    {
        if (! $this->supportsPhotoIntake($item)) {
            return null;
        }

        return $this->serviceFor($item)?->contractedPhotoCount();
    }

    public function capabilityAvailable(): bool
    {
        return $this->columnExists('services', 'upload_intake_type');
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        return self::$columnCache[$key] ??= Schema::hasColumn($table, $column);
    }

    /** Test seam: schema lookups are cached for the request. */
    public static function flushColumnCache(): void
    {
        self::$columnCache = [];
    }
}
