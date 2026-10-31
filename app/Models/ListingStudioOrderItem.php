<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingStudioOrderItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    public const STATUSES = ['awaiting_funding', 'queued', 'processing', 'needs_attention', 'completed', 'failed', 'cancelled'];

    protected $hidden = ['source_snapshot', 'output_descriptor', 'processing_attempt_id'];

    protected $casts = [
        'source_snapshot' => 'array', 'output_descriptor' => 'array', 'options' => 'array',
        'progress' => 'integer', 'processing_lease_expires_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ListingStudioOrder::class, 'order_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StudioWorkspace::class, 'workspace_id');
    }
}
