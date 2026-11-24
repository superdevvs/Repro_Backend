<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A published (or draft) set of drip steps. Published versions never change. */
class CrmDripCampaignVersion extends Model
{
    protected $table = 'crm_drip_campaign_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CrmDripStep::class, 'campaign_version_id')->orderBy('send_day')->orderBy('id');
    }
}
