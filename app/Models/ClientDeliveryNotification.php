<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientDeliveryNotification extends Model
{
    protected $fillable = [
        'user_id',
        'shoot_id',
        'delivery_event_key',
        'delivered_at',
        'seen_at',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }
}
