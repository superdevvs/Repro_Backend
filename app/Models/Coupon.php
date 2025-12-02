<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'amount',
        'max_uses',
        'current_uses',
        'is_active',
        'valid_until',
        'created_by',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'is_active' => 'boolean',
        'amount' => 'decimal:2',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}





