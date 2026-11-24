<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pre-approved drip: 12 weeks, 6 months or 12 months of scheduled emails. */
class CrmDripCampaign extends Model
{
    protected $table = 'crm_drip_campaigns';

    protected $guarded = ['id'];

    public const LENGTHS = ['12_weeks' => 84, '6_months' => 182, '12_months' => 365];
    public const LENGTH_LABELS = ['12_weeks' => '12 weeks', '6_months' => '6 months', '12_months' => '12 months'];

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(CrmDripCampaignVersion::class, 'published_version_id');
    }

    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(CrmDripCampaignVersion::class, 'draft_version_id');
    }
}
