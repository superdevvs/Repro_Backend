<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShootUnit extends Model
{
    protected $fillable = [
        'shoot_id', 'client_key', 'label', 'kind', 'sqft', 'beds', 'baths',
        'access_notes', 'sort_order', 'tour_links', 'property_details', 'provider_data',
        'include_common_area_media', 'property_status', 'listing_type',
    ];

    protected $casts = [
        'sqft' => 'integer', 'beds' => 'integer', 'baths' => 'float', 'sort_order' => 'integer',
        'tour_links' => 'array', 'property_details' => 'array', 'provider_data' => 'array',
        'include_common_area_media' => 'boolean',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function serviceItems()
    {
        return $this->hasMany(ShootService::class, 'shoot_unit_id');
    }
}
