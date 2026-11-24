<?php

namespace App\Events\Crm;

use App\Models\Crm\CrmContact;

/** A contact can no longer receive campaign email (unsubscribed, bounced or complained). Drips stop. */
final class CrmEmailBlocked
{
    public function __construct(public readonly CrmContact $contact, public readonly string $reason) {}
}
