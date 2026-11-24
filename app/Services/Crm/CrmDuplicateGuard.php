<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;

/**
 * Checks a contact against every contact in the system, including other reps'
 * and lost ones. Email or phone: always blocked. Same full name: blocked unless
 * an admin confirms it is a different person.
 */
class CrmDuplicateGuard
{
    public function find(array $data, ?int $ignoreId = null): ?CrmDuplicateMatch
    {
        $base = fn () => CrmContact::unscopedForSystem()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));

        if ($email = CrmNormalizer::email($data['email'] ?? null)) {
            if ($hit = $base()->where('email_normalized', $email)->orderBy('id')->first()) {
                return new CrmDuplicateMatch($hit, 'email');
            }
        }

        if ($phone = CrmNormalizer::phone($data['phone'] ?? null)) {
            if ($hit = $base()->where('phone_digits', $phone)->orderBy('id')->first()) {
                return new CrmDuplicateMatch($hit, 'phone');
            }
        }

        $name = CrmNormalizer::name($data['name'] ?? null);
        if ($name !== '') {
            if ($hit = $base()->where('name_normalized', $name)->orderBy('id')->first()) {
                return new CrmDuplicateMatch($hit, 'name');
            }
        }

        return null;
    }
}
