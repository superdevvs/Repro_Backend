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
        'twilio_phone_number_sid',
        'owner_type',
        'owner_id',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'bool',
    ];
}





