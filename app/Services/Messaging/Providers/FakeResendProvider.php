<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;

class FakeResendProvider extends ResendProvider
{
    use RecordsFakeEmail;

    public function send(MessageChannel $channel, array $payload): string
    {
        $this->recordEnvelope($channel, $payload);

        return 'fake-resend-'.count(self::sent());
    }

    public function testConnection(): array
    {
        return ['success' => true, 'error' => null];
    }
}
