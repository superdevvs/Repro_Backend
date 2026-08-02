<?php

namespace App\Services\Messaging;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether an outbound message may reach a real provider.
 *
 * Local verification reached the live Telnyx API because the local environment
 * file holds real credentials, and every layer above assumed that credentials
 * present means delivery intended. This moves that decision to the environment:
 *
 *   production            allowed, per normal provider configuration
 *   testing               never allowed (providers are faked too — see
 *                         {@see \App\Providers\MessagingSafetyServiceProvider})
 *   local / development   blocked unless explicitly opted in, and then only to
 *                         allowlisted recipients
 *
 * Fixture recipients (555 numbers, `@example.com`, and the other reserved
 * ranges) can never reach a provider outside production even with the opt-in on,
 * so seeded test data cannot cost money or reach a stranger.
 */
class OutboundDeliveryGuard
{
    public const REASON_ALLOWED_PRODUCTION = 'allowed_production';
    public const REASON_ALLOWED_OPT_IN = 'allowed_opt_in_allowlisted';
    public const REASON_BLOCKED_TESTING = 'blocked_testing_environment';
    public const REASON_BLOCKED_NO_OPT_IN = 'blocked_non_production_default';
    public const REASON_BLOCKED_NOT_ALLOWLISTED = 'blocked_recipient_not_allowlisted';
    public const REASON_BLOCKED_FIXTURE = 'blocked_fixture_recipient';
    public const REASON_BLOCKED_NO_RECIPIENT = 'blocked_missing_recipient';

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * Whether a real provider call may be made for this recipient.
     */
    public function allows(string $channel, ?string $recipient): bool
    {
        return $this->decide($channel, $recipient)['allowed'];
    }

    /**
     * @return array{allowed: bool, reason: string, environment: string}
     */
    public function decide(string $channel, ?string $recipient): array
    {
        $environment = (string) $this->app->environment();
        $normalized = trim((string) $recipient);

        // Tests never talk to a provider. Checked before anything else so no
        // configuration mistake can opt a test run into real delivery.
        if ($this->app->environment('testing')) {
            return $this->verdict(false, self::REASON_BLOCKED_TESTING, $environment);
        }

        if ($normalized === '') {
            return $this->verdict(false, self::REASON_BLOCKED_NO_RECIPIENT, $environment);
        }

        // Production keeps its existing behaviour: provider configuration decides.
        if ($this->app->environment('production')) {
            return $this->verdict(true, self::REASON_ALLOWED_PRODUCTION, $environment);
        }

        // Fixture data must never reach a paid provider, opt-in or not.
        if ($this->isFixtureRecipient($channel, $normalized)) {
            return $this->verdict(false, self::REASON_BLOCKED_FIXTURE, $environment);
        }

        if (! $this->optInEnabled()) {
            return $this->verdict(false, self::REASON_BLOCKED_NO_OPT_IN, $environment);
        }

        if (! $this->isAllowlisted($channel, $normalized)) {
            return $this->verdict(false, self::REASON_BLOCKED_NOT_ALLOWLISTED, $environment);
        }

        return $this->verdict(true, self::REASON_ALLOWED_OPT_IN, $environment);
    }

    /**
     * Record a blocked message locally so the intent is auditable.
     *
     * Logged at warning level, not info: a withheld message is worth surfacing,
     * and many environments run with a log threshold above info (this project's
     * local default is `error`, which would drop it entirely). The durable record
     * is the `messages` row itself, which is written regardless of log level.
     *
     * Deliberately records the channel and a masked recipient only. No
     * credential, token or provider secret is read here, so none can reach a log
     * file.
     */
    public function logBlocked(string $channel, ?string $recipient, array $context = []): void
    {
        $verdict = $this->decide($channel, $recipient);

        Log::warning('outbound_message_blocked', array_merge([
            'channel' => strtoupper($channel),
            'recipient' => $this->maskRecipient((string) $recipient),
            'environment' => $verdict['environment'],
            'reason' => $verdict['reason'],
        ], $context));
    }

    /**
     * Partially mask the recipient so logs stay useful without becoming a
     * contact list.
     */
    public function maskRecipient(string $recipient): string
    {
        $recipient = trim($recipient);

        if ($recipient === '') {
            return '(none)';
        }

        if (str_contains($recipient, '@')) {
            [$local, $domain] = explode('@', $recipient, 2);
            $keep = mb_substr($local, 0, 2);

            return $keep . str_repeat('*', max(mb_strlen($local) - 2, 1)) . '@' . $domain;
        }

        $digits = preg_replace('/\D/', '', $recipient) ?? '';
        if (mb_strlen($digits) <= 4) {
            return str_repeat('*', mb_strlen($digits));
        }

        return str_repeat('*', mb_strlen($digits) - 4) . mb_substr($digits, -4);
    }

    private function optInEnabled(): bool
    {
        return filter_var(config('messaging.allow_external', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<string>
     */
    private function allowlist(): array
    {
        $raw = config('messaging.allowlist', '');

        if (is_array($raw)) {
            $entries = $raw;
        } else {
            $entries = explode(',', (string) $raw);
        }

        return array_values(array_filter(array_map(
            fn ($entry) => strtolower(trim((string) $entry)),
            $entries
        ), fn ($entry) => $entry !== ''));
    }

    private function isAllowlisted(string $channel, string $recipient): bool
    {
        $allowlist = $this->allowlist();

        if ($allowlist === []) {
            return false;
        }

        $candidate = strtolower($recipient);

        if (in_array($candidate, $allowlist, true)) {
            return true;
        }

        // Phone numbers are compared on digits so formatting differences between
        // the allowlist and the stored recipient do not matter.
        if (! str_contains($candidate, '@')) {
            $candidateDigits = $this->digits($candidate);

            if ($candidateDigits === '') {
                return false;
            }

            foreach ($allowlist as $entry) {
                if (str_contains($entry, '@')) {
                    continue;
                }

                $entryDigits = $this->digits($entry);
                if ($entryDigits !== '' && $this->digitsMatch($entryDigits, $candidateDigits)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Compare on the last 10 digits so a country-code prefix on one side only
     * does not cause a false mismatch.
     */
    private function digitsMatch(string $a, string $b): bool
    {
        $tailA = mb_substr($a, -10);
        $tailB = mb_substr($b, -10);

        return $tailA !== '' && $tailA === $tailB;
    }

    private function isFixtureRecipient(string $channel, string $recipient): bool
    {
        if (str_contains($recipient, '@')) {
            $domain = strtolower(substr(strrchr($recipient, '@') ?: '', 1));

            if ($domain === '') {
                return true;
            }

            foreach ((array) config('messaging.fixture_email_domains', []) as $fixtureDomain) {
                $fixtureDomain = strtolower((string) $fixtureDomain);
                if ($domain === $fixtureDomain || str_ends_with($domain, '.' . $fixtureDomain)) {
                    return true;
                }
            }

            return false;
        }

        $digits = $this->digits($recipient);

        if ($digits === '') {
            return true;
        }

        $normalized = '+' . $digits;

        foreach ((array) config('messaging.fixture_phone_patterns', []) as $pattern) {
            if (@preg_match((string) $pattern, $normalized) === 1) {
                return true;
            }

            if (@preg_match((string) $pattern, $digits) === 1) {
                return true;
            }
        }

        return false;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    /**
     * @return array{allowed: bool, reason: string, environment: string}
     */
    private function verdict(bool $allowed, string $reason, string $environment): array
    {
        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'environment' => $environment,
        ];
    }
}
