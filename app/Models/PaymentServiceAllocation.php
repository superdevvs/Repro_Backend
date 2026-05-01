<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentServiceAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'shoot_service_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function shootService()
    {
        return $this->belongsTo(ShootService::class, 'shoot_service_id');
    }
}
