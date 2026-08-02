<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;

/**
 * Shared recorder for the test email provider fakes.
 *
 * `MessagingService` type-hints the concrete provider classes, so a fake must
 * extend the class it replaces rather than merely implement the interface. Both
 * fakes record through this trait so assertions have one place to look
 * regardless of which provider a channel resolves to.
 */
trait RecordsFakeEmail
{
    /** @var list<array{to: mixed, subject: ?string, provider: string}> */
    private static array $sentEnvelopes = [];

    /** @var list<array{to: mixed, subject: ?string, provider: string}> */
    private static array $scheduledEnvelopes = [];

    /** @return list<array{to: mixed, subject: ?string, provider: string}> */
    public static function sent(): array
    {
        return self::$sentEnvelopes;
    }

    /** @return list<array{to: mixed, subject: ?string, provider: string}> */
    public static function scheduled(): array
    {
        return self::$scheduledEnvelopes;
    }

    public static function reset(): void
    {
        self::$sentEnvelopes = [];
        self::$scheduledEnvelopes = [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function recordEnvelope(MessageChannel $channel, array $payload, bool $scheduled = false): void
    {
        $envelope = [
            'to' => $payload['to'] ?? null,
            'subject' => isset($payload['subject']) ? (string) $payload['subject'] : null,
            'provider' => (string) ($channel->provider ?? 'unknown'),
        ];

        if ($scheduled) {
            self::$scheduledEnvelopes[] = $envelope;
        } else {
            self::$sentEnvelopes[] = $envelope;
        }
    }
}
