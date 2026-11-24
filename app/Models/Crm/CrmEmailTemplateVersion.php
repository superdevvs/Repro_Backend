<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The editable parts of a branded template: subject, body, one image and one button. Logo, colours and footer are locked. */
class CrmEmailTemplateVersion extends Model
{
    protected $table = 'crm_email_template_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'content_check' => 'array'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CrmEmailTemplate::class, 'template_id');
    }
}
