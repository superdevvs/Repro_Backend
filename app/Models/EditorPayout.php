<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EditorPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'editor_id',
        'shoot_id',
        'service_id',
        'service_name',
        'quantity_snapshot',
        'rate_snapshot',
        'payout_amount',
        'completed_at',
        'is_paid',
        'paid_at',
        'paid_by',
        'payout_batch_id',
    ];

    protected $casts = [
        'quantity_snapshot' => 'integer',
        'rate_snapshot' => 'decimal:2',
        'payout_amount' => 'decimal:2',
        'completed_at' => 'datetime',
        'is_paid' => 'boolean',
        'paid_at' => 'datetime',
    ];

    public function editor()
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
