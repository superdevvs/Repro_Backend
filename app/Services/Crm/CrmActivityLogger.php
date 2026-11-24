<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmActivity;
use App\Models\Crm\CrmContact;
use App\Models\User;

/** Writes one line of a contact's history. */
class CrmActivityLogger
{
    public function log(CrmContact $contact, string $type, ?User $actor, string $summary, array $data = [], bool $viaRobbie = false, ?string $subjectType = null, ?int $subjectId = null): CrmActivity
    {
        return CrmActivity::create([
            'contact_id' => $contact->id,
            'type' => $type,
            'actor_id' => $actor?->id,
            'via_robbie' => $viaRobbie,
            'summary' => mb_substr($summary, 0, 255),
            'data' => $data ?: null,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'occurred_at' => now(),
        ]);
    }
}
