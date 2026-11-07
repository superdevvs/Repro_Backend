<?php

namespace App\Services\Messaging;

/** Provider diagnostics are untrusted. Persist codes and reviewed guidance only. */
class SmsDeliveryFailure
{
    private const MESSAGES = [
        '40001' => 'SMS was not delivered: the recipient number cannot receive text messages (Telnyx 40001).',
        '40002' => 'SMS was blocked by a spam filter (Telnyx 40002). Review the message and contact Telnyx before retrying.',
        '40003' => 'SMS was blocked by a spam filter (Telnyx 40003). Contact Telnyx before sending again.',
        '40010' => 'SMS was not delivered: the sending number needs 10DLC registration (Telnyx 40010).',
        '40012' => 'SMS was not delivered: the recipient number is invalid (Telnyx 40012).',
        '40013' => 'SMS was not delivered: the sending number was rejected (Telnyx 40013).',
        '40015' => 'SMS was blocked by Telnyx spam filtering (Telnyx 40015). Contact Telnyx before retrying.',
        '40300' => 'SMS could not be sent: the Telnyx sending number is not verified.',
    ];

    public static function codes(array $payload): array
    {
        $errors = array_merge(
            (array) data_get($payload, 'data.payload.errors', []),
            (array) data_get($payload, 'data.payload.to.0.errors', []),
        );

        return collect($errors)->map(fn ($error) => is_array($error) && is_scalar($error['code'] ?? null)
            ? (string) $error['code'] : '')
            ->filter(fn ($code) => preg_match('/\A[0-9]{5}\z/', $code))
            ->unique()->take(10)->values()->all();
    }

    public static function message(array $codes): string
    {
        foreach ($codes as $code) {
            if (isset(self::MESSAGES[$code])) {
                return self::MESSAGES[$code];
            }
        }

        return 'SMS could not be delivered. Review the delivery details with Telnyx before retrying.';
    }

    public static function isReviewed(mixed $message): bool
    {
        return in_array($message, self::MESSAGES, true) || $message === self::message([]);
    }
}
