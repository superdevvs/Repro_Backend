<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    private const CATEGORY_SPECIALTY_PREFIX = 'category:';
    private const CATEGORY_NAME_SPECIALTY_PREFIX = 'category-name:';

    public const ACCOUNT_STATUS_ACTIVE = 'active';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'email_verified_at',
        'email_status',
        'verification_sent_at',
        'email_last_delivery_attempt_at',
        'email_last_bounced_at',
        'email_bounce_reason',
        'email_warning_code',
        'email_warning_message',
        'email_suggested_correction',
        'phone',
        'phonenumber',
        'company_name',
        'address',
        'city',
        'state',
        'zip',
        'license_number',
        'company_notes',
        'shoot_cc_emails',
        'client_discount_type',
        'client_discount_value',
        'role',
        'secondary_roles',
        'avatar',
        'bio',
        'about',
        'account_status',
        'locked_at',
        'password_reset_required',
        'password',
        'created_by_name',
        'created_by_id',
        'metadata',
        'timezone',
        'facebook_url',
        'twitter_url',
        'linkedin_url',
        'pinterest_url',
        'sms_opt_out',
        'sms_opt_out_at',
        'sms_ai_enabled',
    ];

    /**
     * Cache schema checks so legacy attribute fallbacks stay cheap.
     *
     * @var array<string, bool>
     */
    protected static array $usersTableColumnCache = [];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Accessor-backed attributes that should always be serialized.
     *
     * @var list<string>
     */
    protected $appends = [
        'about',
        'email_health',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verification_sent_at' => 'datetime',
            'email_last_delivery_attempt_at' => 'datetime',
            'email_last_bounced_at' => 'datetime',
            'deleted_at' => 'datetime',
            'locked_at' => 'datetime',
            'password_reset_required' => 'boolean',
            'password' => 'hashed',
            'metadata' => 'array',
            'secondary_roles' => 'array',
            'shoot_cc_emails' => 'array',
            'client_discount_value' => 'decimal:2',
            'sms_opt_out' => 'boolean',
            'sms_opt_out_at' => 'datetime',
            'sms_ai_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            $user->revokeAllApiTokens();
        });

        static::updated(function (User $user): void {
            if ($user->wasChanged('account_status') && !$user->isAccountEligibleForAuthentication()) {
                $user->revokeAllApiTokens();
            }
        });
    }

    public function isAccountEligibleForAuthentication(): bool
    {
        return !$this->trashed()
            && $this->locked_at === null
            && strtolower(trim((string) ($this->account_status ?: self::ACCOUNT_STATUS_ACTIVE))) === self::ACCOUNT_STATUS_ACTIVE;
    }

    public function revokeAllApiTokens(): void
    {
        try {
            $this->tokens()->delete();
        } catch (\Throwable $exception) {
            Log::warning('Unable to revoke user API tokens.', [
                'user_id' => $this->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function usersTableHasColumn(string $column): bool
    {
        if (array_key_exists($column, self::$usersTableColumnCache)) {
            return self::$usersTableColumnCache[$column];
        }

        try {
            return self::$usersTableColumnCache[$column] = Schema::hasColumn($this->getTable(), $column);
        } catch (\Throwable $exception) {
            Log::warning('Unable to inspect users table column metadata.', [
                'column' => $column,
                'error' => $exception->getMessage(),
            ]);

            return self::$usersTableColumnCache[$column] = false;
        }
    }

    public function getAboutAttribute($value): ?string
    {
        if ($value !== null || $this->usersTableHasColumn('about')) {
            return $value;
        }

        $metadata = $this->metadata;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }

        $about = is_array($metadata) ? ($metadata['about'] ?? null) : null;

        return is_string($about) ? $about : null;
    }

    public function setAboutAttribute($value): void
    {
        if ($this->usersTableHasColumn('about')) {
            $this->attributes['about'] = $value;
            return;
        }

        $metadata = $this->metadata;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        $metadata['about'] = $value;
        $this->attributes['metadata'] = json_encode($metadata);
    }

    public function getEmailHealthAttribute(): array
    {
        return [
            'status' => $this->attributes['email_status'] ?? null,
            'verification_sent_at' => optional($this->verification_sent_at)?->toIso8601String(),
            'email_verified_at' => optional($this->email_verified_at)?->toIso8601String(),
            'last_delivery_attempt_at' => optional($this->email_last_delivery_attempt_at)?->toIso8601String(),
            'last_bounce_at' => optional($this->email_last_bounced_at)?->toIso8601String(),
            'bounce_reason' => $this->attributes['email_bounce_reason'] ?? null,
            'warning_code' => $this->attributes['email_warning_code'] ?? null,
            'warning_message' => $this->attributes['email_warning_message'] ?? null,
            'suggested_correction' => $this->attributes['email_suggested_correction'] ?? null,
        ];
    }

    public function getFirstNameAttribute(): string
    {
        $attributeFirstName = $this->attributes['first_name'] ?? null;
        if (is_string($attributeFirstName) && trim($attributeFirstName) !== '') {
            return trim($attributeFirstName);
        }

        $name = trim((string) ($this->attributes['name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        return $parts[0] ?? $name;
    }

    /**
     * Get shoots where this user is the client
     */
    public function shoots()
    {
        return $this->hasMany(Shoot::class, 'client_id');
    }

    /**
     * Get shoots where this user is the photographer
     */
    public function photographerShoots()
    {
        return $this->hasMany(Shoot::class, 'photographer_id');
    }

    public function photographerEquipments()
    {
        return $this->hasMany(PhotographerEquipment::class, 'photographer_id');
    }

    public function googleCalendarConnection()
    {
        return $this->hasOne(GoogleCalendarConnection::class);
    }

    public function googleCalendarEventMappings()
    {
        return $this->hasMany(GoogleCalendarEventMapping::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(UserActivityLog::class);
    }

    /**
     * Service areas (region/state/area) assigned to this photographer (Req 10).
     */
    public function serviceAreas()
    {
        return $this->belongsToMany(ServiceArea::class, 'photographer_service_areas')
            ->withTimestamps();
    }

    public function ghostAccessibleShoots()
    {
        return $this->belongsToMany(Shoot::class, 'shoot_ghost_users')
            ->withTimestamps();
    }

    /**
     * Get the service capabilities for this photographer.
     * Supports category capability keys and legacy service IDs.
     */
    public function getServiceCapabilities(): array
    {
        $metadata = $this->metadata ?? [];
        $specialties = $metadata['specialties'] ?? [];
        
        if (!is_array($specialties)) {
            return [];
        }
        
        return array_map('strval', $specialties);
    }

    private static function normalizeCategoryNameForCapability(?string $name): string
    {
        $normalized = strtolower(trim((string) $name));
        $normalized = preg_replace('/\s+/', '-', $normalized) ?? '';
        $normalized = preg_replace('/[^a-z0-9-]/', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : 'other';
    }

    private static function categoryCapabilityKeysForService(int|string $serviceId): array
    {
        $service = Service::with('category:id,name')->find($serviceId);

        if (!$service) {
            return [];
        }

        $keys = [];

        if ($service->category_id) {
            $keys[] = self::CATEGORY_SPECIALTY_PREFIX . (string) $service->category_id;
        }

        $categoryName = $service->category?->name;
        if ($categoryName) {
            $keys[] = self::CATEGORY_NAME_SPECIALTY_PREFIX . self::normalizeCategoryNameForCapability($categoryName);
        }

        return $keys;
    }

    /**
     * Check if this photographer can perform a specific service
     */
    public function canPerformService(int|string $serviceId): bool
    {
        $capabilities = $this->getServiceCapabilities();
        if (in_array((string) $serviceId, $capabilities, true)) {
            return true;
        }

        foreach (self::categoryCapabilityKeysForService($serviceId) as $categoryKey) {
            if (in_array($categoryKey, $capabilities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this photographer can perform all given services
     */
    public function canPerformAllServices(array $serviceIds): bool
    {
        foreach ($serviceIds as $serviceId) {
            if (!$this->canPerformService($serviceId)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Set service/category capabilities for this photographer
     */
    public function setServiceCapabilities(array $capabilities): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['specialties'] = array_map('strval', $capabilities);
        $this->metadata = $metadata;
    }

    public function getEditingCapabilities(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $capabilities = $metadata['editing_capabilities'] ?? [];

        if (!is_array($capabilities)) {
            $capabilities = [];
        }

        $normalized = collect($capabilities)
            ->map(function ($capability) {
                $value = strtolower(trim((string) $capability));

                return match ($value) {
                    'photos', 'photo', 'p' => 'photo',
                    'videos', 'video' => 'video',
                    default => null,
                };
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($normalized) && $this->role === 'editor') {
            return ['photo', 'video'];
        }

        return $normalized;
    }

    public function canEditLane(string $lane): bool
    {
        return in_array(strtolower($lane), $this->getEditingCapabilities(), true);
    }

    public function setEditingCapabilities(array $capabilities): void
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $metadata['editing_capabilities'] = collect($capabilities)
            ->map(fn ($capability) => strtolower(trim((string) $capability)))
            ->filter(fn ($capability) => in_array($capability, ['photo', 'video'], true))
            ->unique()
            ->values()
            ->all();
        $this->metadata = $metadata;
    }

    public function serviceGroups()
    {
        return $this->belongsToMany(ServiceGroup::class, 'service_group_user', 'user_id', 'service_group_id')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function hasServiceGroupRestrictions(): bool
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return false;
        }

        if ($this->relationLoaded('serviceGroups')) {
            return $this->serviceGroups->isNotEmpty();
        }

        return $this->serviceGroups()->exists();
    }

    public function getAssignedServiceGroupIds(): array
    {
        if (!$this->serviceGroupsFeatureAvailable()) {
            return [];
        }

        if ($this->relationLoaded('serviceGroups')) {
            return $this->serviceGroups
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();
        }

        return $this->serviceGroups()
            ->pluck('service_groups.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    protected function serviceGroupsFeatureAvailable(): bool
    {
        try {
            if (!class_exists(ServiceGroup::class)) {
                return false;
            }

            return ServiceGroup::isFeatureAvailable();
        } catch (\Throwable $exception) {
            Log::warning('Service groups unavailable while reading users.', [
                'user_id' => $this->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
