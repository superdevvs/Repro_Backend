<?php

namespace App\Services\SystemEmails;

use App\Models\Message;
use App\Services\Messaging\MessagingService;

class SystemEmailDispatcher
{
    public function __construct(
        private readonly MessagingService $messagingService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $built
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $metadata
     */
    public function dispatch(array $built, array $transport, array $metadata): Message
    {
        $payload = [
            'to' => $transport['to'],
            'cc' => $transport['cc'] ?? [],
            'bcc' => $transport['bcc'] ?? [],
            'subject' => $built['subject'],
            'body_html' => $built['body_html'],
            'body_text' => $built['body_text'],
            'send_source' => $transport['send_source'] ?? $built['definition']->alias,
            'sender_name' => 'R/E Pro Photos',
            'related_account_id' => $transport['related_account_id'] ?? null,
            'related_shoot_id' => $transport['related_shoot_id'] ?? null,
            'related_invoice_id' => $transport['related_invoice_id'] ?? null,
            'template_id' => $transport['template_id'] ?? null,
            'contact_email' => $transport['contact_email'] ?? $transport['to'],
            'contact_name' => $transport['contact_name'] ?? ($built['payload']['recipient']['name'] ?? 'Recipient'),
            'contact_type' => $transport['contact_type'] ?? ($built['payload']['meta']['recipient_type'] ?? 'other'),
            'tags_json' => $transport['tags_json'] ?? null,
            'attachments_json' => $transport['attachments_json'] ?? null,
            'metadata' => $metadata,
        ];

        return $this->messagingService->sendEmail($payload);
    }
}
