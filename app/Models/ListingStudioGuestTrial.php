<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioGuestTrial extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'client_id' => 'integer', 'options' => 'array',
        'started_at' => 'datetime', 'completed_at' => 'datetime',
        'worker_started_at' => 'datetime', 'first_download_requested_at' => 'datetime', 'download_request_count' => 'integer',
        'claimed_at' => 'datetime', 'expires_at' => 'datetime', 'media_purged_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(StudioWorkspace::class, 'workspace_id');
    }

    public function hasExpired(): bool
    {
        return $this->status === 'expired' || ($this->client_id === null && $this->expires_at?->isPast());
    }
}
