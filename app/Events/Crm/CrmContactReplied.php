<?php

namespace App\Events\Crm;

use App\Models\Crm\CrmContact;

/** A contact replied to a CRM email. Drips stop. */
final class CrmContactReplied
{
    public function __construct(public readonly CrmContact $contact, public readonly ?int $sendId) {}
}
