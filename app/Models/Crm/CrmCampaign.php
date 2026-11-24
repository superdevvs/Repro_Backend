<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom email campaign. Draft → Submitted → Approved or Sent back →
 * Scheduled → Sent. Any edit after approval returns it to Submitted.
 */
class CrmCampaign extends Model
{
    protected $table = 'crm_campaigns';

    protected $guarded = ['id'];

    public const STATUSES = ['draft', 'submitted', 'sent_back', 'approved', 'scheduled', 'sending', 'sent'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'content_check' => 'array', 'reviewed_at' => 'datetime', 'submitted_at' => 'datetime', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CrmEmailTemplate::class, 'template_id');
    }
}
