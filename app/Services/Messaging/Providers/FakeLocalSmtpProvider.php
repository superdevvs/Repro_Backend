<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;

/**
 * Test stand-in for {@see LocalSmtpProvider}.
 *
 * Bound over the real provider in the `testing` environment by
 * {@see \App\Providers\MessagingSafetyServiceProvider}. Extends the real class
 * because `MessagingService` type-hints it, and opens no SMTP connection.
 */
class FakeLocalSmtpProvider extends LocalSmtpProvider
{
    use RecordsFakeEmail;

    public function send(MessageChannel $channel, array $payload): string
    {
        $this->recordEnvelope($channel, $payload);

        return 'fake-smtp-' . count(self::sent());
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        $this->recordEnvelope($channel, $payload, scheduled: true);

        return 'fake-smtp-scheduled-' . count(self::scheduled());
    }
}
