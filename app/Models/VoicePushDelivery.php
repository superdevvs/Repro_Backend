<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoicePushDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime'];

    public function subscription()
    {
        return $this->belongsTo(VoicePushSubscription::class);
    }
}
