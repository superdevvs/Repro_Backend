<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingStudioTrialClaim extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['edit_types' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
