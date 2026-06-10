<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot record linking a photographer (User) to a ServiceArea (Req 10).
 *
 * Modeled as a first-class model so it can be queried/audited directly, while the
 * many-to-many is still exposed via User::serviceAreas() and ServiceArea::photographers().
 */
class PhotographerServiceArea extends Model
{
    use HasFactory;

    protected $table = 'photographer_service_areas';

    protected $fillable = [
        'user_id',
        'service_area_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }
}
