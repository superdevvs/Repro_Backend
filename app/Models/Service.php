<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'pricing_type',
        'allow_multiple',
        'delivery_time',
        'category_id',
        'icon',
        'photographer_required',
        'photographer_pay',
        'photo_count',
        'quantity',
    ];
    
    protected $casts = [
        'price' => 'decimal:2',
        'delivery_time' => 'integer',
        'category_id' => 'integer',
        'photographer_required' => 'boolean',
        'photographer_pay' => 'decimal:2',
        'photo_count' => 'integer',
        'allow_multiple' => 'boolean',
        'quantity' => 'integer',
    ];

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
        return $this->belongsToMany(ServiceGroup::class)
            ->withTimestamps()
            ->orderBy('name');
    }

    public function scopeVisibleToClient(Builder $query, ?User $client): Builder
    {
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
        $query = static::query()->select('services.id')->visibleToClient($client);

        if (is_array($candidateIds) && !empty($candidateIds)) {
            $query->whereIn('services.id', $candidateIds);
        }

        return $query->pluck('services.id');
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

    /**
     * Get the photographer pay for a given square footage.
     */
    public function getPhotographerPayForSqft(?int $sqft): ?float
    {
        if ($this->pricing_type !== 'variable' || $sqft === null) {
            return $this->photographer_pay !== null ? (float) $this->photographer_pay : null;
        }

        $range = $this->sqftRanges()
            ->where('sqft_from', '<=', $sqft)
            ->where('sqft_to', '>=', $sqft)
            ->first();

        if ($range && $range->photographer_pay !== null) {
            return (float) $range->photographer_pay;
        }

        return $this->photographer_pay !== null ? (float) $this->photographer_pay : null;
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
}
