<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

/**
 * Something Robbie suggests doing (a note, follow-up, stage move, email or drip
 * enrolment). Frozen when proposed; nothing happens until its owner confirms.
 */
class CrmAiProposal extends Model
{
    protected $table = 'crm_ai_proposals';

    protected $guarded = ['id'];

    public const KINDS = ['note', 'event', 'stage', 'email', 'drip'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
