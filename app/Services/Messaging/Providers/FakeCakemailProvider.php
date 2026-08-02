<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;

/**
 * Test stand-in for {@see CakemailProvider}.
 *
 * Bound over the real provider in the `testing` environment by
 * {@see \App\Providers\MessagingSafetyServiceProvider}. Extends the real class
 * because `MessagingService` type-hints it. Reads no credentials and opens no
 * connection.
 */
class FakeCakemailProvider extends CakemailProvider
{
    use RecordsFakeEmail;

    public function send(MessageChannel $channel, array $payload): string
    {
        $this->recordEnvelope($channel, $payload);

        return 'fake-cakemail-' . count(self::sent());
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        $this->recordEnvelope($channel, $payload, scheduled: true);

        return 'fake-cakemail-scheduled-' . count(self::scheduled());
    }

    public function sendWithTemplate(int $templateId, string $toEmail, array $customAttributes = []): string
    {
        return 'fake-cakemail-template-' . $templateId;
    }
}
