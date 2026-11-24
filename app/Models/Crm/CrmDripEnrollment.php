<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A contact on a drip, with how far they have got. */
class CrmDripEnrollment extends Model
{
    protected $table = 'crm_drip_enrollments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'next_send_at' => 'datetime', 'claimed_at' => 'datetime', 'stopped_at' => 'datetime', 'via_robbie' => 'boolean'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CrmDripCampaign::class, 'campaign_id');
    }
}
