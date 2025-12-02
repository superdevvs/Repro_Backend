<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class LocalSmtpProvider implements EmailProviderInterface
{
    public function send(MessageChannel $channel, array $payload): string
    {
        Mail::send([], [], function ($message) use ($channel, $payload) {
            $message->to($payload['to']);

            if (!empty($payload['reply_to'])) {
                $message->replyTo($payload['reply_to']);
            }

            $fromEmail = $channel->from_email ?? config('mail.from.address');
            $fromName = $channel->display_name ?? config('mail.from.name');
            $message->from($fromEmail, $fromName);
            $message->subject($payload['subject'] ?? 'Message from Repro HQ');

            if (!empty($payload['html'])) {
                $message->setBody($payload['html'], 'text/html');
                if (!empty($payload['text'])) {
                    $message->addPart($payload['text'], 'text/plain');
                }
            } else {
                $message->setBody($payload['text'] ?? '', 'text/plain');
            }
        });

        return (string) Str::uuid();
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        // Local SMTP cannot queue remotely, so just send immediately.
        return $this->send($channel, $payload);
    }
}





