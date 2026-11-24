<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmEmailTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Where a template is used, shown in the template editor ("used in 12-week drip, day 7"). */
class CrmTemplateUsage
{
    public function for(CrmEmailTemplate $template): array
    {
        if (! Schema::hasTable('crm_drip_steps')) {
            return [];
        }
        $versionIds = $template->versions()->pluck('id');

        return DB::table('crm_drip_steps as s')
            ->join('crm_drip_campaign_versions as v', 'v.id', '=', 's.campaign_version_id')
            ->join('crm_drip_campaigns as c', 'c.id', '=', 'v.campaign_id')
            ->where(fn ($q) => $q->where('s.template_id', $template->id)->orWhereIn('s.template_version_id', $versionIds))
            ->whereColumn('c.published_version_id', 'v.id')
            ->select('c.id as campaign_id', 'c.name', 's.send_day')
            ->get()
            ->map(fn ($r) => ['campaign_id' => $r->campaign_id, 'campaign' => $r->name, 'send_day' => (int) $r->send_day])
            ->all();
    }
}
