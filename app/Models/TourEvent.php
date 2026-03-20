<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TourEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shoot_id',
        'event_type',
        'tour_type',
        'visitor_id',
        'ip_address',
        'user_agent',
        'referrer',
        'country',
        'city',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }
}
