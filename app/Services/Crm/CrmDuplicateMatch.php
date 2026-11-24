<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;

/** The existing contact a new or edited one collides with, and on what. */
final class CrmDuplicateMatch
{
    public function __construct(
        public readonly CrmContact $contact,
        public readonly string $type, // email | phone | name
    ) {}

    /** Email and phone matches are always blocked; a name-only match can be allowed by an admin. */
    public function isOverridable(): bool
    {
        return $this->type === 'name';
    }
}
