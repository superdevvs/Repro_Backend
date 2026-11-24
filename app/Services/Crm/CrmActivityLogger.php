<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmActivity;
use App\Models\Crm\CrmContact;
use App\Models\User;

/** Writes one line of a contact's history. */
class CrmActivityLogger
{
    private static int $robbieDepth = 0;

    /**
     * Run a confirmed Robbie proposal: every history line written inside is
     * marked "via Robbie", whichever service writes it.
     */
    public static function asRobbie(callable $callback): mixed
    {
        self::$robbieDepth++;
        try {
            return $callback();
        } finally {
            self::$robbieDepth--;
        }
    }

    public static function inRobbie(): bool
    {
        return self::$robbieDepth > 0;
    }

    public function log(CrmContact $contact, string $type, ?User $actor, string $summary, array $data = [], bool $viaRobbie = false, ?string $subjectType = null, ?int $subjectId = null): CrmActivity
    {
        return CrmActivity::create([
            'contact_id' => $contact->id,
            'type' => $type,
            'actor_id' => $actor?->id,
            'via_robbie' => $viaRobbie || self::inRobbie(),
            'summary' => mb_substr($summary, 0, 255),
            'data' => $data ?: null,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'occurred_at' => now(),
        ]);
    }
}
