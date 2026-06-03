<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OauthToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'user_id',
        'account_type',
        'access_token',
        'refresh_token',
        'expires_at',
        'scopes',
        'provider_account_id',
        'provider_account_email',
        'provider_account_name',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];
}
