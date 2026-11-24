<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CrmSmsConsent
{
    public function allows(CrmContact $contact): bool
    {
        return $contact->sms_consent && $contact->sms_consent_at !== null && filled($contact->phone_digits)
            && $contact->sms_consent_phone === $contact->phone_digits;
    }

    public function record(CrmContact $contact, bool $consent, string $source, ?User $actor = null, ?string $submittedPhone = null): void
    {
        $phone = $contact->phone_digits;
        if ($consent && (! $phone || ($submittedPhone !== null && CrmNormalizer::phone($submittedPhone) !== $phone))) {
            return;
        }
        DB::transaction(function () use ($contact, $consent, $source, $actor, $phone) {
            $contact->forceFill([
                'sms_consent' => $consent,
                'sms_consent_phone' => $consent ? $phone : null,
                'sms_consent_at' => $consent ? now() : null,
                'sms_consent_source' => $consent ? $source : null,
            ])->save();
            app(CrmActivityLogger::class)->log($contact, 'sms_consent', $actor,
                $consent ? 'SMS consent recorded' : 'SMS consent withdrawn', ['source' => $source, 'phone' => $phone]);
        });
    }
}
