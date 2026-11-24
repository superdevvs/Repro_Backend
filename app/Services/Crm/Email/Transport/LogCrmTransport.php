<?php

namespace App\Services\Crm\Email\Transport;

/**
 * Default transport until real delivery is switched on: the email is recorded
 * with its exact content and marked "logged", nothing leaves the server.
 */
class LogCrmTransport implements CrmMailTransport
{
    public function name(): string
    {
        return 'log';
    }

    public function send(array $message): array
    {
        return ['status' => 'logged', 'provider_message_id' => null, 'error' => null];
    }
}
