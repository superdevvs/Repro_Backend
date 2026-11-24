<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A branded email template. Content lives in versions; emails use the approved
 * version until a newer one is approved.
 */
class CrmEmailTemplate extends Model
{
    protected $table = 'crm_email_templates';

    protected $guarded = ['id'];

    public const KINDS = ['one_off', 'drip', 'campaign', 'recruiting'];

    public function versions(): HasMany
    {
        return $this->hasMany(CrmEmailTemplateVersion::class, 'template_id')->orderByDesc('version_no');
    }

    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(CrmEmailTemplateVersion::class, 'approved_version_id');
    }

    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(CrmEmailTemplateVersion::class, 'latest_version_id');
    }
}
