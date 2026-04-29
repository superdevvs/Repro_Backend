<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotographerEquipment extends Model
{
    use HasFactory;

    protected $table = 'photographer_equipments';

    public const STATUS_PENDING = 'pending_verification';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUBMITTED,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'photographer_id',
        'name',
        'serial_number',
        'issue_date',
        'purchase_date',
        'purchase_cost',
        'vendor',
        'expense_id',
        'status',
        'verification_requested_at',
        'submitted_at',
        'verified_at',
        'rejected_at',
        'verified_by',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'verification_requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function photographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(AccountingExpense::class, 'expense_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PhotographerEquipmentPhoto::class, 'equipment_id');
    }
}
