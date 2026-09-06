<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Str;

class EmailHealthService
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_RISKY = 'risky';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_INVALID = 'invalid';

    /**
     * @var array<string, string>
     */
    protected const DOMAIN_SUGGESTIONS = [
        'gmail.con' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gmial.com' => 'gmail.com',
        'gmal.com' => 'gmail.com',
        'gail.com' => 'gmail.com',
        'gnail.com' => 'gmail.com',
        'outlok.com' => 'outlook.com',
        'outlook.con' => 'outlook.com',
        'hotnail.com' => 'hotmail.com',
        'hotmial.com' => 'hotmail.com',
        'yaho.com' => 'yahoo.com',
        'yahoo.con' => 'yahoo.com',
        'icloud.con' => 'icloud.com',
        'icloud.co' => 'icloud.com',
        'test.con' => 'test.com',
    ];

    /**
     * @var array<string, string>
     */
    protected const COMMON_TLD_CORRECTIONS = [
        'con' => 'com',
        'cmo' => 'com',
        'cm' => 'com',
        'vom' => 'com',
        'ogr' => 'org',
        'ogn' => 'org',
        'nte' => 'net',
        'nte.' => 'net',
    ];

    /**
     * @var array<int, string>
     */
    protected const COMMON_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'msn.com',
        'icloud.com',
        'me.com',
        'mac.com',
        'yahoo.com',
        'aol.com',
        'proton.me',
        'protonmail.com',
        'comcast.net',
        'att.net',
        'verizon.net',
    ];

    /**
     * Send sources that must always be deliverable for account access/recovery
     * even when an address is hard-failing (invalid/bounced).
     *
     * @var array<int, string>
     */
    protected const ALLOWLISTED_SEND_SOURCES = [
        'ACCOUNT_CREATED',
        'CLIENT_EMAIL_VERIFICATION',
        'PASSWORD_RESET',
    ];

    /**
     * Client-facing transactional send sources that should still be delivered
     * when an address is soft-flagged (unverified/risky). These are service
     * notifications tied to a real business event (a shoot, invoice, payment,
     * etc.) so suppressing them would confuse clients who are mid-transaction.
     *
     * @var array<int, string>
     */
    protected const TRANSACTIONAL_SEND_SOURCES = [
        'ACCOUNT_CREATED',
        'CLIENT_EMAIL_VERIFICATION',
        'PHOTOGRAPHER_EQUIPMENT_VERIFICATION',
        'PASSWORD_RESET',
        'TERMS_ACCEPTED',
        'SHOOT_SCHEDULED',
        'SHOOT_UPDATED',
        'SHOOT_REMINDER',
        'SHOOT_READY',
        'SHOOT_DELIVERED',
        'SHOOT_REMOVED',
        'SHOOT_CANCELLED',
        'SHOOT_CANCELED',
        'SHOOT_REQUESTED',
        'SHOOT_REQUEST_MODIFIED',
        'SHOOT_REQUEST_DECLINED',
        'SHOOT_CANCELLATION_REQUESTED',
        'SHOOT_PAID',
        'PAYMENT_CONFIRMATION',
        'INVOICE_GENERATED',
        'INVOICE_APPROVED',
        'INVOICE_PENDING_APPROVAL',
        'INVOICE_REJECTED',
        'CANCELLATION_FEE_INVOICE',
        'EDITING_REQUEST',
        'CONTACT_CONFIRMATION',
        'CONTACT_NOTIFICATION',
        'OFFLINE_PAYMENT_INTENT_SUBMITTED',
        'OFFLINE_PAYMENT_INTENT_DECLINED',
    ];

    /**
     * @return array{
     *   valid: bool,
     *   normalized_email: string,
     *   status: string,
     *   warning_code: string|null,
     *   warning_message: string|null,
     *   suggested_correction: string|null,
     *   requires_confirmation: bool,
     *   error_message: string|null
     * }
     */
    public function analyzeForSave(string $email): array
    {
        $normalized = Str::lower(trim($email));

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return [
                'valid' => false,
                'normalized_email' => $normalized,
                'status' => self::STATUS_INVALID,
                'warning_code' => 'invalid_syntax',
                'warning_message' => 'Please enter a valid email address.',
                'suggested_correction' => null,
                'requires_confirmation' => false,
                'error_message' => 'Please enter a valid email address.',
            ];
        }

        [, $domain] = explode('@', $normalized, 2);
        $suggestedEmail = $this->detectSuggestedCorrection($normalized);

        if ($suggestedEmail !== null && $suggestedEmail !== $normalized) {
            $suggestedDomain = explode('@', $suggestedEmail, 2)[1] ?? $suggestedEmail;

            return [
                'valid' => true,
                'normalized_email' => $normalized,
                'status' => self::STATUS_RISKY,
                'warning_code' => 'common_typo',
                'warning_message' => sprintf('%s looks like a typo. Use %s instead?', $domain, $suggestedDomain),
                'suggested_correction' => $suggestedEmail,
                'requires_confirmation' => true,
                'error_message' => null,
            ];
        }

        if (! $this->domainCanReceiveMail($domain)) {
            return [
                'valid' => false,
                'normalized_email' => $normalized,
                'status' => self::STATUS_INVALID,
                'warning_code' => 'domain_no_mail',
                'warning_message' => 'This email domain cannot receive mail. Please check the address.',
                'suggested_correction' => null,
                'requires_confirmation' => false,
                'error_message' => 'This email domain cannot receive mail. Please check the address.',
            ];
        }

        return [
            'valid' => true,
            'normalized_email' => $normalized,
            'status' => self::STATUS_UNVERIFIED,
            'warning_code' => null,
            'warning_message' => null,
            'suggested_correction' => null,
            'requires_confirmation' => false,
            'error_message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    public function buildAttributesForSave(string $email, array $analysis): array
    {
        return [
            'email' => $analysis['normalized_email'] ?? Str::lower(trim($email)),
            'email_status' => $analysis['status'] ?? self::STATUS_UNVERIFIED,
            'verification_sent_at' => null,
            'email_verified_at' => null,
            'email_last_delivery_attempt_at' => null,
            'email_last_bounced_at' => null,
            'email_bounce_reason' => null,
            'email_warning_code' => $analysis['warning_code'] ?? null,
            'email_warning_message' => $analysis['warning_message'] ?? null,
            'email_suggested_correction' => $analysis['suggested_correction'] ?? null,
        ];
    }

    public function markVerificationSent(User $user): void
    {
        $status = strtolower((string) ($user->email_status ?? ''));
        $attributes = [
            'verification_sent_at' => now(),
        ];
        if ($status === '' || $status === self::STATUS_UNVERIFIED) {
            $attributes['email_status'] = self::STATUS_UNVERIFIED;
        }

        $user->forceFill($attributes)->save();
    }

    public function markVerified(User $user): void
    {
        $user->forceFill([
            'email_status' => self::STATUS_VERIFIED,
            'email_verified_at' => now(),
            'email_verified_email' => mb_strtolower(trim($user->email)),
            'email_warning_code' => null,
            'email_warning_message' => null,
            'email_suggested_correction' => null,
            'email_bounce_reason' => null,
        ])->save();
    }

    public function markDelivered(User $user): void
    {
        $attributes = [
            'email_last_delivery_attempt_at' => now(),
        ];

        if (blank($user->email_status)) {
            $attributes['email_status'] = $user->email_verified_at
                ? self::STATUS_VERIFIED
                : self::STATUS_UNVERIFIED;
        }

        $user->forceFill($attributes)->save();
    }

    public function markBounced(User $user, ?string $reason = null): void
    {
        $user->forceFill([
            'email_status' => self::STATUS_BOUNCED,
            'email_last_bounced_at' => now(),
            'email_bounce_reason' => $reason,
            'email_warning_code' => 'bounced',
            'email_warning_message' => 'Delivery failed for this email address. Please correct it before sending again.',
        ])->save();
    }

    public function markRisky(User $user, ?string $reason = null): void
    {
        if ($user->email_status === self::STATUS_BOUNCED || $user->email_status === self::STATUS_INVALID) {
            return;
        }

        $user->forceFill([
            'email_status' => self::STATUS_RISKY,
            'email_warning_code' => 'delivery_risky',
            'email_warning_message' => $reason ?: 'Recent delivery results suggest this email may be risky.',
        ])->save();
    }

    public function extractSalesRepId(User $user): ?int
    {
        $metadata = is_array($user->metadata) ? $user->metadata : [];
        $candidate = $metadata['accountRepId']
            ?? $metadata['account_rep_id']
            ?? $metadata['repId']
            ?? $metadata['rep_id']
            ?? $user->created_by_id
            ?? null;

        return is_numeric($candidate) ? (int) $candidate : null;
    }

    public function automatedSendBlockedReason(?User $recipient, string $sendSource): ?string
    {
        if (! $recipient || $recipient->role !== 'client') {
            return null;
        }

        $status = strtolower((string) ($recipient->email_status ?? ''));
        if ($status === '') {
            return null;
        }

        // Hard delivery failures: block everything except explicit account recovery emails.
        if (in_array($status, [self::STATUS_INVALID, self::STATUS_BOUNCED], true)) {
            if (in_array($sendSource, self::ALLOWLISTED_SEND_SOURCES, true)) {
                return null;
            }

            return 'This client email is blocked because it is invalid or has bounced.';
        }

        // Soft delivery concerns: still allow critical transactional emails through,
        // only suppress non-transactional automation so clients mid-shoot/mid-payment
        // keep receiving service notifications even before verifying their address.
        if (in_array($status, [self::STATUS_UNVERIFIED, self::STATUS_RISKY], true)) {
            if (in_array($sendSource, self::TRANSACTIONAL_SEND_SOURCES, true)) {
                return null;
            }

            return 'This client email is not verified yet and automated sending is currently suppressed.';
        }

        return null;
    }

    protected function detectSuggestedCorrection(string $email): ?string
    {
        [$local, $domain] = explode('@', Str::lower(trim($email)), 2);

        if (isset(self::DOMAIN_SUGGESTIONS[$domain])) {
            return $local.'@'.self::DOMAIN_SUGGESTIONS[$domain];
        }

        $closestCommonDomain = $this->detectClosestCommonDomain($domain);
        if ($closestCommonDomain !== null) {
            return $local.'@'.$closestCommonDomain;
        }

        $domainParts = explode('.', $domain);
        if (count($domainParts) < 2) {
            return null;
        }

        $tld = array_pop($domainParts);
        if (isset(self::COMMON_TLD_CORRECTIONS[$tld])) {
            return $local.'@'.implode('.', $domainParts).'.'.self::COMMON_TLD_CORRECTIONS[$tld];
        }

        return null;
    }

    protected function isCommonDomain(string $domain): bool
    {
        return in_array(Str::lower($domain), self::COMMON_DOMAINS, true);
    }

    protected function detectClosestCommonDomain(string $domain): ?string
    {
        $normalizedDomain = Str::lower(trim($domain));
        if ($normalizedDomain === '' || $this->isCommonDomain($normalizedDomain)) {
            return null;
        }

        $candidateParts = explode('.', $normalizedDomain);
        if (count($candidateParts) !== 2) {
            return null;
        }

        [$candidateRoot, $candidateTld] = $candidateParts;
        if ($candidateRoot === '' || $candidateTld === '') {
            return null;
        }

        foreach (self::COMMON_DOMAINS as $commonDomain) {
            $commonParts = explode('.', $commonDomain);
            if (count($commonParts) !== 2) {
                continue;
            }

            [$commonRoot, $commonTld] = $commonParts;
            if ($candidateTld !== $commonTld) {
                continue;
            }

            if (($candidateRoot[0] ?? null) !== ($commonRoot[0] ?? null)) {
                continue;
            }

            if (abs(strlen($candidateRoot) - strlen($commonRoot)) > 1) {
                continue;
            }

            if (levenshtein($candidateRoot, $commonRoot) <= 1) {
                return $commonDomain;
            }
        }

        return null;
    }

    protected function domainCanReceiveMail(string $domain): bool
    {
        try {
            return checkdnsrr($domain, 'MX')
                || checkdnsrr($domain, 'A')
                || checkdnsrr($domain, 'AAAA');
        } catch (\Throwable) {
            return true;
        }
    }
}
