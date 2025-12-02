<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootRescheduleRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'requested_by',
        'approved_by',
        'original_date',
        'requested_date',
        'requested_time',
        'reason',
        'status',
        'reviewed_at',
    ];

    protected $casts = [
        'original_date' => 'date',
        'requested_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}





