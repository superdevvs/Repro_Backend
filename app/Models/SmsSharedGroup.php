<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SmsSharedGroup extends Model
{
    use HasUuids;

    protected $guarded = [];
    protected $casts = ['participants' => 'array', 'last_message_at' => 'datetime'];

    public function members() { return $this->belongsToMany(User::class, 'sms_shared_group_members', 'group_id', 'user_id'); }
    public function messages() { return $this->hasMany(SmsSharedMessage::class, 'group_id'); }
    public function number() { return $this->belongsTo(SmsNumber::class, 'sms_number_id'); }
}
