<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\ShootFile;
use App\Models\ShootService;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * The single owner of "how many exposures per stack" for a service on a shoot.
 *
 * Bracket size is execution state, not a catalogue property and not a property of
 * the whole shoot. One shoot can be Exterior HDR by photographer A at 5x and
 * Interior HDR by photographer B at 3x, so the value belongs to the shoot-service
 * assignment. `services.uses_hdr_brackets` says only whether a deliverable brackets
 * at all; `users.default_bracket_mode` is a preference that seeds a new assignment;
 * `shoot_service.bracket_mode` is the execution record that actually governs
 * stacking.
 *
 * Every consumer — upload, stacking, restack, expected counts, listing filters —
 * resolves through here so the fallback chain is not restated anywhere else.
 */
class BracketModeResolver
{
    /** Used when nothing else states a size. */
    public const DEFAULT_BRACKET_MODE = 5;

    /** The only sizes the product offers. */
    public const ALLOWED_BRACKET_MODES = [3, 5];

    /**
     * @var array<string, bool>
     */
    private static array $columnCache = [];

    /**
     * Exposures per stack for this service item, or null when it does not bracket.
     *
     * Resolution order, and why:
     *   1. the item's own snapshot — what was actually agreed for this execution
     *   2. the assigned photographer's preference — for items not yet pinned
     *   3. the legacy shoot-wide value — read-only compatibility for old rows
     *   4. 5 — the product default
     */
    public function effectiveBracketMode(ShootService $item): ?int
    {
        if (! $this->serviceUsesBrackets($item)) {
            return null;
        }

        $candidates = [
            $this->columnExists('shoot_service', 'bracket_mode') ? $item->bracket_mode : null,
            $this->photographerPreference($item),
            // Legacy only. Nothing writes shoots.bracket_mode any more; it is read
            // so shoots created before per-service brackets keep their divisor.
            $this->legacyShootBracketMode($item),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return self::DEFAULT_BRACKET_MODE;
    }

    /**
     * The value a freshly assigned item should record, ignoring any existing
     * snapshot. Used when initialising or re-initialising an assignment.
     */
    public function resolveForNewAssignment(ShootService $item, ?int $photographerId = null): ?int
    {
        if (! $this->serviceUsesBrackets($item)) {
            return null;
        }

        $preference = $photographerId !== null
            ? $this->preferenceFor($photographerId)
            : $this->photographerPreference($item);

        $candidates = [
            $preference,
            $this->legacyShootBracketMode($item),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return self::DEFAULT_BRACKET_MODE;
    }

    /**
     * Record the execution value for an assignment.
     *
     * Refuses to move once raw files exist for the item: changing the divisor under
     * files that are already stacked would silently renumber them, which has to be a
     * deliberate "Change & Restack" instead of a side effect of reassignment.
     *
     * @return bool whether the snapshot was written
     */
    public function snapshotOnAssignment(ShootService $item, ?int $photographerId = null): bool
    {
        if (! $this->columnExists('shoot_service', 'bracket_mode')) {
            return false;
        }

        if (! $this->serviceUsesBrackets($item)) {
            // NULL stays meaningful for work that never brackets.
            if ($item->bracket_mode !== null) {
                $item->bracket_mode = null;
                $item->save();

                return true;
            }

            return false;
        }

        if ($this->hasRawFiles($item)) {
            return false;
        }

        $resolved = $this->resolveForNewAssignment($item, $photographerId);
        if ($resolved === null || (int) $item->bracket_mode === (int) $resolved) {
            return false;
        }

        $item->bracket_mode = $resolved;
        $item->save();

        return true;
    }

    /** Raw files this one service item is expected to produce. */
    public function expectedRawForService(ShootService $item): int
    {
        $photoCount = $this->photoCountFor($item);
        if ($photoCount <= 0) {
            return 0;
        }

        // Explicit: only bracketed work multiplies. A floor plan or a drone set
        // delivers one raw per final photo no matter what any bracket value says.
        $mode = $this->effectiveBracketMode($item);

        return $mode === null ? $photoCount : $photoCount * $mode;
    }

    /**
     * Raw files the whole shoot is expected to produce, as the sum of its service
     * items. This cannot be expressed as one multiplication once services differ:
     * 30 finals at 5x plus 12 finals at 3x is 186, which is not any single product
     * of a shoot-wide final count and a shoot-wide bracket size.
     */
    public function expectedRawForShoot(Shoot $shoot): int
    {
        $items = $shoot->relationLoaded('serviceItems')
            ? $shoot->getRelation('serviceItems')
            : $shoot->serviceItems()->with('service')->get();

        return (int) $items->sum(fn (ShootService $item) => $this->expectedRawForService($item));
    }

    /** Whether the catalogue says this deliverable brackets at all. */
    public function serviceUsesBrackets(ShootService $item): bool
    {
        if (! $this->columnExists('services', 'uses_hdr_brackets')) {
            return false;
        }

        $service = $item->relationLoaded('service') ? $item->getRelation('service') : $item->service;

        return (bool) ($service?->uses_hdr_brackets ?? false);
    }

    public function hasRawFiles(ShootService $item): bool
    {
        return ShootFile::query()
            ->where('shoot_service_id', $item->id)
            ->where('media_type', 'raw')
            ->exists();
    }

    /** Constrain to the sizes the product offers; anything else is not a size. */
    public function normalize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $intValue = (int) $value;

        return in_array($intValue, self::ALLOWED_BRACKET_MODES, true) ? $intValue : null;
    }

    private function photographerPreference(ShootService $item): ?int
    {
        return $item->photographer_id ? $this->preferenceFor((int) $item->photographer_id) : null;
    }

    private function preferenceFor(int $photographerId): ?int
    {
        if (! $this->columnExists('users', 'default_bracket_mode')) {
            return null;
        }

        return $this->normalize(
            User::query()->whereKey($photographerId)->value('default_bracket_mode')
        );
    }

    private function legacyShootBracketMode(ShootService $item): ?int
    {
        if (! $this->columnExists('shoots', 'bracket_mode')) {
            return null;
        }

        if ($item->relationLoaded('shoot') && $item->getRelation('shoot')) {
            return $this->normalize($item->getRelation('shoot')->bracket_mode);
        }

        if (! $item->shoot_id) {
            return null;
        }

        return $this->normalize(Shoot::query()->whereKey($item->shoot_id)->value('bracket_mode'));
    }

    /**
     * Final photos this item is contracted to deliver.
     *
     * Only the catalogue's photo_count counts. `quantity` is deliberately not a
     * fallback: it defaults to 1 on every booked item, so treating it as a photo
     * count would invent a raw expectation for floor plans and virtual tours that
     * deliver no photos at all.
     */
    private function photoCountFor(ShootService $item): int
    {
        $service = $item->relationLoaded('service') ? $item->getRelation('service') : $item->service;

        return max(0, (int) ($service?->photo_count ?? 0));
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
