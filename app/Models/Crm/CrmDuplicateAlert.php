<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone tried to add a contact that already exists. Shown to admins in the
 * Unassigned queue; the rep who tried is only told the contact exists.
 */
class CrmDuplicateAlert extends Model
{
    protected $table = 'crm_duplicate_alerts';

    protected $guarded = ['id'];

    public const RESOLUTIONS = ['kept', 'reassigned', 'same_person', 'allowed_different'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'resolved_at' => 'datetime'];
    }

    public function attemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attempted_by');
    }

    public function matchedContact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'matched_contact_id')->withoutGlobalScopes();
    }
}
