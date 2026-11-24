<?php

namespace App\Services\Crm\Email\Transport;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Models\MessageChannel;
use App\Services\Messaging\Providers\ResendProvider;

/**
 * Real delivery through the Dashboard's Resend provider (no CakeMail
 * fallback, no shoot-notification behaviour). An unclear failure is
 * reported as "uncertain", never retried through another provider: the
 * idempotency key protects a later retry from sending twice.
 */
class ResendCrmTransport implements CrmMailTransport
{
    public function __construct(private readonly ResendProvider $provider) {}

    public function name(): string
    {
        return 'resend';
    }

    public function send(array $message): array
    {
        // An unsaved channel only carries the From name and address for this one send.
        $channel = new MessageChannel(['display_name' => $message['from_name'] ?? null, 'from_email' => $message['from_email']]);

        try {
            $id = $this->provider->send($channel, [
                'from' => $message['from_email'],
                'to' => $message['to'],
                'subject' => $message['subject'],
                'html' => $message['html'],
                'text' => $message['text'],
                'reply_to' => $message['reply_to'] ?? null,
                'headers' => $message['headers'] ?? [],
                'idempotency_key' => $message['idempotency_key'],
            ]);

            return ['status' => 'sent', 'provider_message_id' => $id, 'error' => null];
        } catch (EmailProviderRejectedException $e) {
            return ['status' => 'failed', 'provider_message_id' => null, 'error' => mb_substr($e->getMessage(), 0, 400)];
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'uncertain', 'provider_message_id' => null, 'error' => 'Resend did not confirm the send: '.mb_substr($e->getMessage(), 0, 300)];
        }
    }
}
