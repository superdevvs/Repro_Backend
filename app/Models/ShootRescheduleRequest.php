<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootRescheduleRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'shoot_id',
        'requested_by',
        'approved_by',
        'original_date',
        'original_time',
        'requested_date',
        'requested_time',
        'reason',
        'review_notes',
        'status',
        'reviewed_at',
        'applied_at',
    ];

    protected $casts = [
        'original_date' => 'date',
        'requested_date' => 'date',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
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

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Whether the requested change has already been written to the shoot.
     *
     * This is the idempotency guard: approving an already-applied request must
     * not move the shoot a second time or re-send notifications. It is tracked
     * separately from `status` because a request can be approved and applied in
     * one step (staff rescheduling directly) or in two (client requests, staff
     * approves later).
     */
    public function hasBeenApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
