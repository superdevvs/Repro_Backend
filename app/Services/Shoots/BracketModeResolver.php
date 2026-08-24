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

    public function __construct(private readonly UploadIntakeResolver $intake) {}

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

    /**
     * Raw photo files this one service item is expected to produce.
     *
     * Returns 0 for work that owes no photos at all — a fee, a travel line, an
     * enhancement, a dedicated tour, a video-only deliverable — and null when the item
     * genuinely owes photos but the contracted number is not configured. Null is not
     * the same as zero: a variable HDR product with no count must be reported as
     * unspecified so the UI can say so, instead of inventing a denominator from
     * booking quantity the way the previous resolver did.
     */
    public function expectedRawForService(ShootService $item): ?int
    {
        if (! $this->intake->supportsPhotoIntake($item)) {
            return 0;
        }

        $photoCount = $this->photoCountFor($item);
        if ($photoCount === null) {
            return null;
        }

        // Explicit: only bracketed work multiplies. A drone set delivers one raw per
        // final photo no matter what any bracket value says.
        $mode = $this->effectiveBracketMode($item);

        return $mode === null ? $photoCount : $photoCount * $mode;
    }

    /**
     * Whether this item owes photos but has no contracted count configured.
     *
     * Callers use this to mark an aggregate as inexact rather than presenting a sum
     * that quietly omits an unknown component.
     */
    public function expectedRawUnspecified(ShootService $item): bool
    {
        return $this->expectedRawForService($item) === null;
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

        return (int) $items->sum(fn (ShootService $item) => $this->expectedRawForService($item) ?? 0);
    }

    /**
     * Whether any item contributes an unknown quantity to the shoot's expectation.
     *
     * When true, the shoot total is a floor rather than an exact figure and must be
     * presented that way.
     */
    public function expectedRawIsExactForShoot(Shoot $shoot): bool
    {
        $items = $shoot->relationLoaded('serviceItems')
            ? $shoot->getRelation('serviceItems')
            : $shoot->serviceItems()->with('service')->get();

        return ! $items->contains(fn (ShootService $item) => $this->expectedRawUnspecified($item));
    }

    /**
     * Whether bracketed capture applies to this deliverable.
     *
     * Requires both halves: the service must be photo-capable, and the catalogue must
     * flag it as exposure-stacked. Requiring photo capability here is what keeps a
     * video-only or non-intake row from ever acquiring a stack size, so no downstream
     * caller has to special-case it.
     */
    public function serviceUsesBrackets(ShootService $item): bool
    {
        if (! $this->columnExists('services', 'uses_hdr_brackets')) {
            return false;
        }

        if (! $this->intake->supportsPhotoIntake($item)) {
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
     * Final photos this item is contracted to deliver, or null when unspecified.
     *
     * Only the catalogue's photo_count counts. `quantity` is deliberately not a
     * fallback: it defaults to 1 on every booked item, so treating it as a photo count
     * invented a raw expectation for floor plans, virtual staging and drone rows whose
     * real counts were never stored there.
     */
    private function photoCountFor(ShootService $item): ?int
    {
        return $this->intake->contractedPhotoCount($item);
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
