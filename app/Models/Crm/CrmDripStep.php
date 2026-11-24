<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email in a drip: a send day and an approved template. A step without a template is a placeholder and is skipped. */
class CrmDripStep extends Model
{
    protected $table = 'crm_drip_steps';

    protected $guarded = ['id'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(CrmEmailTemplate::class, 'template_id');
    }
}
