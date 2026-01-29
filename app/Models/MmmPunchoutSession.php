<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MmmPunchoutSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'user_id',
        'buyer_cookie',
        'cost_center_number',
        'employee_email',
        'username',
        'first_name',
        'last_name',
        'template_external_number',
        'order_number',
        'redirect_url',
        'status',
        'redirected_at',
        'returned_at',
        'last_error',
        'request_payload',
        'response_payload',
    ];

    protected $casts = [
        'redirected_at' => 'datetime',
        'returned_at' => 'datetime',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
