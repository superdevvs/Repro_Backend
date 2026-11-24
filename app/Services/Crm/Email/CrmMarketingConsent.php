<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmContact;
use App\Models\User;
use App\Services\Crm\CrmActivityLogger;
use App\Services\Crm\CrmNormalizer;
use Illuminate\Support\Facades\DB;

final class CrmMarketingConsent
{
    public function allows(CrmContact $contact): bool
    {
        return $contact->marketing_consent_at !== null && filled($contact->email_normalized)
            && $contact->marketing_consent_email === $contact->email_normalized;
    }

    public function record(CrmContact $contact, bool $consent, string $source, ?User $actor = null, ?string $submittedEmail = null): void
    {
        $address = $contact->email_normalized;
        if ($consent && (! $address || ($submittedEmail !== null && CrmNormalizer::email($submittedEmail) !== $address))) {
            return;
        }

        DB::transaction(function () use ($contact, $consent, $source, $actor, $address) {
            $contact->forceFill([
                'marketing_consent_email' => $consent ? $address : null,
                'marketing_consent_at' => $consent ? now() : null,
                'marketing_consent_source' => $consent ? $source : null,
            ])->save();
            app(CrmActivityLogger::class)->log($contact, 'marketing_consent', $actor,
                $consent ? 'Marketing email consent recorded' : 'Marketing email consent withdrawn',
                ['source' => $source, 'email' => $address]);
            if (! $consent) {
                app(CrmDripService::class)->stopForContact($contact, 'consent_withdrawn', $actor);
            }
        });
    }
}
