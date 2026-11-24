<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmContact;
use App\Models\Crm\EmailSuppression;
use App\Services\Crm\CrmActivityLogger;
use App\Services\Crm\CrmNormalizer;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Unsubscribes and the global do-not-email list. A suppressed address never
 * gets campaign, drip or one-off email again. Unsubscribe links carry an
 * encrypted token with the address; no login is needed to use them.
 */
class CrmSuppression
{
    public function __construct(private readonly CrmActivityLogger $activity) {}

    public function isSuppressed(?string $email): bool
    {
        $normalized = CrmNormalizer::email($email);

        return $normalized !== null && EmailSuppression::query()->where('email_normalized', $normalized)->exists();
    }

    public function suppress(string $email, string $reason, string $source, ?int $contactId = null): void
    {
        $normalized = CrmNormalizer::email($email);
        if (! $normalized) {
            return;
        }
        EmailSuppression::query()->updateOrCreate(['email_normalized' => $normalized], ['reason' => $reason, 'source' => $source, 'contact_id' => $contactId]);

        $contacts = CrmContact::unscopedForSystem()->where('email_normalized', $normalized)->get();
        foreach ($contacts as $contact) {
            $changes = $reason === 'unsubscribe' ? ['email_opt_out_at' => $contact->email_opt_out_at ?? now(), 'marketing_consent_at' => null, 'marketing_consent_email' => null, 'marketing_consent_source' => null] : ['email_status' => $reason === 'complaint' ? 'complained' : 'bounced'];
            $contact->forceFill($changes)->save();
            $this->activity->log($contact, 'email_suppressed', null, match ($reason) {
                'unsubscribe' => 'Unsubscribed from email',
                'bounce' => 'Email address bounced; campaign email stopped',
                'complaint' => 'Marked an email as spam; campaign email stopped',
                default => 'Email stopped by an admin',
            }, ['reason' => $reason, 'source' => $source]);
            event(new \App\Events\Crm\CrmEmailBlocked($contact, $reason));
        }
    }

    /** Resubscribe works only for people who unsubscribed themselves, not bounces or complaints. */
    public function resubscribe(string $email): bool
    {
        $normalized = CrmNormalizer::email($email);
        $row = $normalized ? EmailSuppression::query()->where('email_normalized', $normalized)->first() : null;
        if (! $row || $row->reason !== 'unsubscribe') {
            return false;
        }
        $row->delete();
        foreach (CrmContact::unscopedForSystem()->where('email_normalized', $normalized)->get() as $contact) {
            $contact->forceFill(['email_opt_out_at' => null])->save();
            app(CrmMarketingConsent::class)->record($contact, true, 'resubscribe');
            $this->activity->log($contact, 'email_resubscribed', null, 'Resubscribed to email');
        }

        return true;
    }

    public function token(string $email, ?int $sendId = null): string
    {
        $payload = Crypt::encryptString(json_encode(['e' => CrmNormalizer::email($email), 's' => $sendId]));

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /** @return array{email: string, send_id: ?int}|null */
    public function decode(string $token): ?array
    {
        try {
            $payload = base64_decode(strtr($token, '-_', '+/'), true);
            $data = json_decode(Crypt::decryptString((string) $payload), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && ! empty($data['e']) ? ['email' => (string) $data['e'], 'send_id' => isset($data['s']) ? (int) $data['s'] : null] : null;
    }

    public function url(string $email, ?int $sendId = null): string
    {
        $base = rtrim((string) config('crm.email.public_base_url', config('app.frontend_url', config('app.url'))), '/');

        return $base.'/unsubscribe/'.$this->token($email, $sendId);
    }
}
