<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingStudioRequest extends Model
{
    protected $fillable = [
        'submitted_by_id', 'client_id', 'type', 'status', 'contact', 'plan_code',
        'services', 'details', 'phone', 'preferred_time', 'idempotency_key',
        'request_hash', 'review_note', 'reviewed_by_id', 'reviewed_at',
    ];

    protected $casts = ['contact' => 'array', 'services' => 'array', 'reviewed_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id')->withTrashed();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id')->withTrashed();
    }
}
