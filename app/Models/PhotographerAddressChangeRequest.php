<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotographerAddressChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'street_address',
        'city',
        'state',
        'zip',
        'status',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPresentation(): array
    {
        return [
            'id' => $this->id,
            'street_address' => $this->street_address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
        ];
    }
}
