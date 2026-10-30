<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SmsSharedMessage extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $casts = ['recipient_statuses' => 'array', 'provider_attempted_at' => 'datetime'];

    public function group() { return $this->belongsTo(SmsSharedGroup::class, 'group_id'); }
}
