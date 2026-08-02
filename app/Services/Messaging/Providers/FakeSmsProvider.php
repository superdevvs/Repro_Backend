<?php

namespace App\Services\Messaging\Providers;

use App\Models\SmsNumber;
use RuntimeException;

/**
 * Stand-in for {@see TelnyxSmsProvider} used in automated tests.
 *
 * Bound over the real provider in the `testing` environment by
 * {@see \App\Providers\MessagingSafetyServiceProvider}, so a test can exercise
 * the full send path — message record, status, thread — while it is structurally
 * impossible to reach Telnyx. It reads no credentials at all.
 *
 * Sends are recorded in memory so tests can assert on what would have gone out.
 */
class FakeSmsProvider extends TelnyxSmsProvider
{
    /** @var list<array{sms_number_id: int|string|null, to: string, text: string}> */
    private static array $sent = [];

    public function send(SmsNumber $number, array $payload): string
    {
        $to = (string) ($payload['to'] ?? '');
        $text = trim((string) ($payload['text'] ?? $payload['body_text'] ?? ''));

        if ($text === '') {
            // Matches the real provider so tests still cover this failure mode.
            throw new RuntimeException('SMS body cannot be empty.');
        }

        self::$sent[] = [
            'sms_number_id' => $number->id ?? null,
            'to' => $to,
            'text' => $text,
        ];

        return 'fake-sms-' . count(self::$sent);
    }

    public function testConnection(?SmsNumber $number = null): array
    {
        return [
            'success' => true,
            'message' => 'Fake SMS provider (no network call performed)',
        ];
    }

    /** @return list<array{sms_number_id: int|string|null, to: string, text: string}> */
    public static function sent(): array
    {
        return self::$sent;
    }

    public static function reset(): void
    {
        self::$sent = [];
    }
}
