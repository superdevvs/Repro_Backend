<?php

namespace App\Events\Crm;

use App\Models\Crm\CrmContact;

/** Fired after a contact's stage changes and the change is committed. Drips listen for Lost and Booked. */
final class CrmContactStageChanged
{
    public function __construct(
        public readonly CrmContact $contact,
        public readonly ?string $fromStage,
        public readonly string $toStage,
        public readonly ?int $actorId,
        public readonly string $source,
    ) {}
}
