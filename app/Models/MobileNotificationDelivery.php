<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MobileNotificationDelivery extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['expires_at' => 'datetime', 'not_before' => 'datetime', 'attempts' => 'integer'];
}
