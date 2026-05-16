<?php

namespace App\Services\Messaging\AiSms;

use App\Models\Contact;
use App\Models\User;

class SmsContextResolverService
{
    /**
     * @return array{user: ?User, contact: ?Contact, identified: bool, ambiguous: bool, role: ?string, phone_e164: string}
     */
    public function resolveByE164(?string $phone): array
    {
        $e164 = $this->normalize($phone ?? '');

        $user = $this->findUserExact($e164);
        $contact = $this->findContactExact($e164);

        if ($user || $contact) {
            return $this->payload($user, $contact, ambiguous: false, e164: $e164);
        }

        // Digits-suffix fallback (legacy dirty data only). Phones may be stored with
        // separators like (202) 555-0177, so we narrow with last-4 LIKE in SQL and then
        // filter by full digit suffix in PHP.
        $suffix = $this->digits10($e164);
        if (mb_strlen($suffix) < 10) {
            return $this->payload(null, null, ambiguous: false, e164: $e164);
        }

        $last4 = substr($suffix, -4);

        $userCandidates = User::query()
            ->where(function ($q) use ($last4) {
                $q->where('phonenumber', 'like', '%' . $last4 . '%')
                    ->orWhere('phone', 'like', '%' . $last4 . '%');
            })
            ->limit(20)
            ->get();

        $contactCandidates = Contact::query()
            ->where('phone', 'like', '%' . $last4 . '%')
            ->limit(20)
            ->get();

        $userMatches = $userCandidates->filter(function (User $u) use ($suffix) {
            return $this->phoneEndsWith((string) $u->phonenumber, $suffix)
                || $this->phoneEndsWith((string) $u->phone, $suffix);
        })->values();

        $contactMatches = $contactCandidates->filter(function (Contact $c) use ($suffix) {
            return $this->phoneEndsWith((string) $c->phone, $suffix);
        })->values();

        $totalMatches = $userMatches->count() + $contactMatches->count();

        // Multiple matches → ambiguous, treat as unidentified.
        if ($totalMatches > 1) {
            return $this->payload(null, null, ambiguous: true, e164: $e164);
        }

        if ($totalMatches === 1) {
            return $this->payload(
                $userMatches->first(),
                $contactMatches->first(),
                ambiguous: false,
                e164: $e164
            );
        }

        return $this->payload(null, null, ambiguous: false, e164: $e164);
    }

    public function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }
        return '+' . ltrim($digits, '+');
    }

    private function digits10(string $e164): string
    {
        $digits = preg_replace('/\D/', '', $e164) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    private function phoneEndsWith(string $stored, string $digitSuffix): bool
    {
        if ($stored === '' || $digitSuffix === '') {
            return false;
        }
        $storedDigits = preg_replace('/\D/', '', $stored) ?? '';
        if ($storedDigits === '') {
            return false;
        }
        return str_ends_with($storedDigits, $digitSuffix);
    }

    private function findUserExact(string $e164): ?User
    {
        if ($e164 === '') {
            return null;
        }

        return User::query()
            ->where(function ($q) use ($e164) {
                $q->where('phonenumber', $e164)->orWhere('phone', $e164);
            })
            ->first();
    }

    private function findContactExact(string $e164): ?Contact
    {
        if ($e164 === '') {
            return null;
        }

        return Contact::query()->where('phone', $e164)->first();
    }

    /**
     * @return array{user: ?User, contact: ?Contact, identified: bool, ambiguous: bool, role: ?string, phone_e164: string}
     */
    private function payload(?User $user, ?Contact $contact, bool $ambiguous, string $e164): array
    {
        return [
            'user' => $user,
            'contact' => $contact,
            'identified' => !$ambiguous && ($user !== null || $contact !== null),
            'ambiguous' => $ambiguous,
            'role' => $user?->role,
            'phone_e164' => $e164,
        ];
    }
}
