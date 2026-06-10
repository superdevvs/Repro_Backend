<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServiceArea extends Model
{
    use HasFactory;

    // Service-area granularity (Req 10).
    public const KIND_REGION = 'region';
    public const KIND_STATE = 'state';
    public const KIND_AREA = 'area';

    public const KINDS = [
        self::KIND_REGION,
        self::KIND_STATE,
        self::KIND_AREA,
    ];

    protected $fillable = [
        'kind',
        'value',
        'label',
    ];

    /**
     * Photographers assigned to this service area.
     */
    public function photographers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'photographer_service_areas')
            ->withTimestamps();
    }
}
