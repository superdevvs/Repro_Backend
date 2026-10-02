<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Service extends Model
{
    use HasFactory;

    protected $appends = ['booking_duration_defaults'];

    public function getBookingDurationDefaultsAttribute(): array
    {
        return [
            'default_minutes' => $this->getShootDurationMinutes(),
            'min_minutes' => (int) config('availability.min_shoot_duration_minutes', 5),
            'max_minutes' => (int) config('availability.max_shoot_duration_minutes', 300),
        ];
    }

    public function getShootDurationMinutesAttribute($value): int
    {
        return $this->getShootDurationMinutes();
    }

    protected $fillable = [
        'name',
        'description',
        'price',
        'pricing_type',
        'allow_multiple',
        'delivery_time',
        'shoot_duration_minutes',
        'category_id',
        'icon',
        'photographer_required',
        'requires_editing',
        'photographer_pay',
        'photographer_pay_type',
        'photographer_pay_percent',
        'exclude_from_sales_commission',
        'photo_count',
        'uses_hdr_brackets',
        'upload_intake_type',
        'quantity',
    ];
    
    protected $casts = [
        'price' => 'decimal:2',
        'delivery_time' => 'integer',
        'shoot_duration_minutes' => 'integer',
        'category_id' => 'integer',
        'photographer_required' => 'boolean',
        'requires_editing' => 'boolean',
        'photographer_pay' => 'decimal:2',
        'photographer_pay_percent' => 'decimal:2',
        'exclude_from_sales_commission' => 'boolean',
        'photo_count' => 'integer',
        'uses_hdr_brackets' => 'boolean',
        'allow_multiple' => 'boolean',
        'quantity' => 'integer',
    ];

    /** Capture arrives through the photo raw lane. */
    public const INTAKE_PHOTO = 'photo';

    /** Capture arrives through the video raw lane. */
    public const INTAKE_VIDEO = 'video';

    /** One execution row legitimately receives both raw lanes. */
    public const INTAKE_PHOTO_VIDEO = 'photo_video';

    /** Never selectable as an upload target. */
    public const INTAKE_NONE = 'none';

    public const UPLOAD_INTAKE_TYPES = [
        self::INTAKE_PHOTO,
        self::INTAKE_VIDEO,
        self::INTAKE_PHOTO_VIDEO,
        self::INTAKE_NONE,
    ];

    /** The photo raw lane. */
    public const LANE_PHOTO = 'photo';

    /** The video raw lane. */
    public const LANE_VIDEO = 'video';

    public const UPLOAD_LANES = [self::LANE_PHOTO, self::LANE_VIDEO];

    /**
     * The declared intake capability, falling back to "not selectable".
     *
     * An unrecognised or missing value resolves to `none` on purpose. Unknown
     * capability must mean "not selectable" rather than "probably photo": the old
     * resolver assumed anything not obviously video was photo-like, which is exactly
     * how fees, floor plans and tour products reached the raw upload selector.
     */
    public function uploadIntakeType(): string
    {
        $value = $this->getAttribute('upload_intake_type');

        if (! is_string($value)) {
            return self::INTAKE_NONE;
        }

        $value = trim($value);

        return in_array($value, self::UPLOAD_INTAKE_TYPES, true) ? $value : self::INTAKE_NONE;
    }

    public function supportsPhotoIntake(): bool
    {
        return in_array($this->uploadIntakeType(), [self::INTAKE_PHOTO, self::INTAKE_PHOTO_VIDEO], true);
    }

    public function supportsVideoIntake(): bool
    {
        return in_array($this->uploadIntakeType(), [self::INTAKE_VIDEO, self::INTAKE_PHOTO_VIDEO], true);
    }

    /** Whether this service may receive files through the given raw lane. */
    public function supportsIntakeLane(string $lane): bool
    {
        return match ($lane) {
            self::LANE_PHOTO => $this->supportsPhotoIntake(),
            self::LANE_VIDEO => $this->supportsVideoIntake(),
            default => false,
        };
    }

    /** Whether any upload lane can select this service at all. */
    public function supportsAnyIntake(): bool
    {
        return $this->uploadIntakeType() !== self::INTAKE_NONE;
    }

    /**
     * Final photos this service is contracted to deliver, or null when unspecified.
     *
     * Booking `quantity` is deliberately not consulted. It is 1 on effectively every
     * booked row, so treating it as a count fabricated a raw expectation for services
     * that deliver no photos at all. A variable or unconfigured product returns null
     * so callers can report "not set" instead of inventing a denominator.
     */
    public function contractedPhotoCount(): ?int
    {
        $value = $this->getAttribute('photo_count');

        if ($value === null || $value === '') {
            return null;
        }

        $count = (int) $value;

        return $count > 0 ? $count : null;
    }

    /**
     * Whether this service is worked by the in-house photo/video editing lanes.
     *
     * Non-editing extras (drone, floor plans, 3D tours, virtual staging, etc.) are
     * produced by external/automated pipelines and must stay hidden from editors
     * (QA #13). Defaults to true when the column is absent so behaviour is preserved
     * on databases that have not run the migration yet.
     */
    public function requiresEditing(): bool
    {
        $value = $this->getAttribute('requires_editing');

        return $value === null ? true : (bool) $value;
    }

    public function requiresPhotographer(): bool
    {
        return (bool) $this->getAttribute('photographer_required');
    }

    /** Preserve the pre-policy occupation of already-booked fee rows only. */
    public function requiresPhotographerForBooking(mixed $createdAt = null): bool
    {
        if ($this->requiresPhotographer() || ! $createdAt) {
            return $this->requiresPhotographer();
        }
        $snapshot = $this->durationPolicySnapshot('services', (int) $this->id);
        if (! $snapshot || empty($snapshot['before']['photographer_required'])) {
            return false;
        }
        try {
            return \Carbon\Carbon::parse($createdAt)->lte(\Carbon\Carbon::parse($snapshot['applied_at']));
        } catch (\Throwable) {
            return false;
        }
    }

    private function durationPolicySnapshot(string $table, int $id): ?array
    {
        if (! $id || ! Schema::hasTable('service_duration_policy_snapshots')) {
            return null;
        }
        $snapshot = DB::table('service_duration_policy_snapshots')
            ->where('source_table', $table)->where('source_id', $id)->first();

        return $snapshot ? [
            'before' => json_decode($snapshot->before_json, true, 512, JSON_THROW_ON_ERROR),
            'applied_at' => $snapshot->applied_at,
        ] : null;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the sqft ranges for variable pricing.
     */
    public function sqftRanges()
    {
        return $this->hasMany(ServiceSqftRange::class)->orderBy('sqft_from');
    }

    public function serviceGroups()
    {
        return $this->belongsToMany(ServiceGroup::class, 'service_group_service', 'service_id', 'service_group_id')
            ->withTimestamps()
            ->orderBy('name');
    }

    /** Historical prices may be read through booked relations, never selected for a new line. */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('services.is_migration_only', false);
    }

    public function scopeVisibleToClient(Builder $query, ?User $client): Builder
    {
        $query->bookable();
        if (!$this->serviceGroupsFeatureAvailable()) {
            return $query;
        }

        if (!$client || $client->role !== 'client') {
            return $query;
        }

        $groupIds = $client->relationLoaded('serviceGroups')
            ? $client->serviceGroups->pluck('id')
            : $client->serviceGroups()->pluck('service_groups.id');

        if ($groupIds->isEmpty()) {
            return $query;
        }

        return $query->whereHas('serviceGroups', function (Builder $groupQuery) use ($groupIds) {
            $groupQuery->whereIn('service_groups.id', $groupIds);
        });
    }

    public static function visibleIdsForClient(User $client, ?array $candidateIds = null): Collection
    {
        if (!static::serviceGroupsFeatureAvailable()) {
            return collect($candidateIds ?? []);
        }

        $query = static::query()->select('services.id')->visibleToClient($client);

        if (is_array($candidateIds) && !empty($candidateIds)) {
            $query->whereIn('services.id', $candidateIds);
        }

        return $query->pluck('services.id');
    }

    protected static function serviceGroupsFeatureAvailable(): bool
    {
        try {
            if (!class_exists(ServiceGroup::class)) {
                return false;
            }

            return ServiceGroup::isFeatureAvailable();
        } catch (\Throwable $exception) {
            Log::warning('Service groups unavailable while reading services.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the price for a given square footage.
     * Returns the base price if pricing_type is 'fixed' or no matching range is found.
     */
    public function getPriceForSqft(?int $sqft): float
    {
        if ($this->pricing_type !== 'variable' || $sqft === null) {
            return (float) $this->price;
        }

        $range = $this->sqftRanges()
            ->where('sqft_from', '<=', $sqft)
            ->where('sqft_to', '>=', $sqft)
            ->first();

        return $range ? (float) $range->price : (float) $this->price;
    }

    /** Pay is a flat dollar amount (the historical behaviour). */
    public const PAY_TYPE_FIXED = 'fixed';

    /** Pay is a percentage of the applicable price. */
    public const PAY_TYPE_PERCENT = 'percent';

    /**
     * Resolve a payout from either a flat amount or a percentage of a price.
     *
     * Accepts the service itself or one of its sqft ranges — both carry the same
     * trio of columns — so the flat/percent decision lives in exactly one place.
     * Returns null when nothing is configured, which callers treat as "no pay
     * defined" rather than zero.
     */
    private static function resolvePayFrom(object $source, float $price): ?float
    {
        $type = $source->photographer_pay_type ?? self::PAY_TYPE_FIXED;

        if ($type === self::PAY_TYPE_PERCENT) {
            $percent = $source->photographer_pay_percent ?? null;
            if ($percent === null || $percent === '') {
                return null;
            }

            return round($price * ((float) $percent / 100), 2);
        }

        return $source->photographer_pay !== null ? (float) $source->photographer_pay : null;
    }

    /**
     * Get the photographer pay for a given square footage.
     *
     * Supports both a flat amount and a percentage of the price that applies at
     * that square footage (Requirement 1.4/1.5). Services left on the default
     * `fixed` type behave exactly as before.
     */
    public function getPhotographerPayForSqft(?int $sqft): ?float
    {
        if ($this->pricing_type !== 'variable' || $sqft === null) {
            return self::resolvePayFrom($this, (float) $this->price);
        }

        $range = $this->sqftRanges()
            ->where('sqft_from', '<=', $sqft)
            ->where('sqft_to', '>=', $sqft)
            ->first();

        if ($range) {
            // A percentage on the tier applies to that tier's price.
            $tierPay = self::resolvePayFrom($range, (float) ($range->price ?? $this->price));
            if ($tierPay !== null) {
                return $tierPay;
            }
        }

        return self::resolvePayFrom($this, (float) $this->getPriceForSqft($sqft));
    }

    /**
     * Get the duration for a given square footage.
     */
    public function getDurationForSqft(?int $sqft): ?int
    {
        if ($this->pricing_type !== 'variable' || $sqft === null) {
            return $this->delivery_time;
        }

        $range = $this->sqftRanges()
            ->where('sqft_from', '<=', $sqft)
            ->where('sqft_to', '>=', $sqft)
            ->first();

        return $range && $range->duration ? $range->duration : $this->delivery_time;
    }

    /**
     * Resolve the expected on-site shoot duration in minutes.
     * Falls back to the booking default unless a dedicated duration is configured.
     */
    public function getShootDurationMinutes(?int $sqft = null, bool $useCatalogDefault = true): int
    {
        // Editing, enhancements and fees consume no photographer time, even when
        // an older catalogue row still carries a nonzero duration.
        $legacyService = ! $useCatalogDefault
            ? $this->durationPolicySnapshot('services', (int) $this->id)
            : null;
        $requiresPhotographer = $legacyService['before']['photographer_required'] ?? $this->requiresPhotographer();
        if (! $requiresPhotographer) {
            return 0;
        }

        $defaultDurationMinutes = (int) config('availability.default_shoot_duration_minutes', 60);
        $minDurationMinutes = (int) config('availability.min_shoot_duration_minutes', 5);
        $maxDurationMinutes = (int) config('availability.max_shoot_duration_minutes', 300);

        // A matching property tier is more specific than the catalogue's fallback.
        if ($this->pricing_type === 'variable' && $sqft !== null) {
            $range = $this->sqftRanges()
                ->where('sqft_from', '<=', $sqft)
                ->where('sqft_to', '>=', $sqft)
                ->first();

            if ($range) {
                $legacyRange = ! $useCatalogDefault
                    ? $this->durationPolicySnapshot('service_sqft_ranges', (int) $range->id)
                    : null;
                $duration = $legacyRange ? $legacyRange['before']['duration'] : $range->duration;
                if (is_numeric($duration) && (int) $duration > 0) {
                    // NULL legacy booking rows used the old tier bounds. Preserve
                    // that result when this policy changes the catalogue tier.
                    $minimum = $legacyRange ? 30 : $minDurationMinutes;
                    $maximum = $legacyRange ? 240 : $maxDurationMinutes;
                    return min(max((int) $duration, $minimum), $maximum);
                }
            }
        }

        $explicitDuration = ($useCatalogDefault ? ($this->attributes['shoot_duration_minutes'] ?? null) : null)
            ?? $this->getAttribute('duration_minutes');
        if (is_numeric($explicitDuration) && (int) $explicitDuration > 0) {
            return min(max((int) $explicitDuration, $minDurationMinutes), $maxDurationMinutes);
        }

        return $defaultDurationMinutes;
    }
}
