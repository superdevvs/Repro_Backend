<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsGroupMember extends Model
{
    protected $fillable = [
        'sms_group_id',
        'user_id',
        'name',
        'phone',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(SmsGroup::class, 'sms_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
