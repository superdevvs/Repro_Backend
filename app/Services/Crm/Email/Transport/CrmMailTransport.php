<?php

namespace App\Services\Crm\Email\Transport;

/** Hands one rendered CRM email to a provider. */
interface CrmMailTransport
{
    public function name(): string;

    /**
     * @param  array{from_email: string, from_name: ?string, reply_to: ?string, to: string, subject: string, html: string, text: string, headers: array<string, string>, idempotency_key: string}  $message
     * @return array{status: string, provider_message_id: ?string, error: ?string}
     *         status: sent | logged | failed | blocked
     */
    public function send(array $message): array;
}
