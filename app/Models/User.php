<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'phonenumber',
        'company_name',
        'address',
        'city',
        'state',
        'zip',
        'license_number',
        'company_notes',
        'role',
        'secondary_roles',
        'avatar',
        'bio',
        'about',
        'account_status',
        'password',
        'created_by_name',
        'created_by_id',
        'metadata',
        'timezone',
        'facebook_url',
        'twitter_url',
        'linkedin_url',
        'pinterest_url',
    ];


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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'metadata' => 'array',
            'secondary_roles' => 'array',
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

    /**
     * Get the service capabilities (service IDs) for this photographer
     * Stored in metadata.specialties as array of service IDs
     */
    public function getServiceCapabilities(): array
    {
        $metadata = $this->metadata ?? [];
        $specialties = $metadata['specialties'] ?? [];
        
        // Ensure we return an array of strings (service IDs)
        if (!is_array($specialties)) {
            return [];
        }
        
        return array_map('strval', $specialties);
    }

    /**
     * Check if this photographer can perform a specific service
     */
    public function canPerformService(int|string $serviceId): bool
    {
        $capabilities = $this->getServiceCapabilities();
        return in_array((string) $serviceId, $capabilities, true);
    }

    /**
     * Check if this photographer can perform all given services
     */
    public function canPerformAllServices(array $serviceIds): bool
    {
        $capabilities = $this->getServiceCapabilities();
        foreach ($serviceIds as $serviceId) {
            if (!in_array((string) $serviceId, $capabilities, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Set service capabilities for this photographer
     */
    public function setServiceCapabilities(array $serviceIds): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['specialties'] = array_map('strval', $serviceIds);
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
