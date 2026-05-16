<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'phone_number',
        'label',
        // Kept readable for one release while legacy rows are migrated; cleanup PR will drop the column.
        'twilio_phone_number_sid',
        'telnyx_phone_number_id',
        'messaging_profile_id',
        'owner_type',
        'owner_id',
        'is_default',
        'sms_ai_enabled',
        'voice_ai_enabled',
        'voice_assistant_id_override',
    ];

    protected $casts = [
        'is_default' => 'bool',
        'sms_ai_enabled' => 'bool',
        'voice_ai_enabled' => 'bool',
    ];
}





