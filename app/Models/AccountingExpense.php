<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingExpense extends Model
{
    use HasFactory;

    public const STATUS_UNREVIEWED = 'unreviewed';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_APPROVED = 'approved';

    public const STATUSES = [
        self::STATUS_UNREVIEWED,
        self::STATUS_REVIEWED,
        self::STATUS_APPROVED,
    ];

    public const RELATED_PHOTOGRAPHER_EQUIPMENT = 'photographer_equipment';

    protected $fillable = [
        'category',
        'description',
        'amount',
        'expense_date',
        'vendor',
        'status',
        'reimbursable',
        'notes',
        'tags',
        'related_type',
        'related_id',
        'created_by',
        'receipt_disk',
        'receipt_path',
        'receipt_original_name',
        'receipt_mime_type',
        'receipt_size',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'reimbursable' => 'boolean',
            'tags' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
