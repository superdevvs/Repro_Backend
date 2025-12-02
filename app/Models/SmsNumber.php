<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'mighty_call_key',
        'phone_number',
        'label',
        'owner_type',
        'owner_id',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'bool',
    ];
}





