<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class LocalSmtpProvider implements EmailProviderInterface
{
    public function send(MessageChannel $channel, array $payload): string
    {
        $callback = function ($message) use ($channel, $payload): void {
            $message->from(
                $channel->from_email,
                $channel->display_name ?: config('mail.from.name')
            )->to((string) $payload['to'])
                ->subject((string) ($payload['subject'] ?? ''));

            foreach ($this->normalizeRecipients($payload['cc'] ?? []) as $cc) {
                $message->cc($cc);
            }

            foreach ($this->normalizeRecipients($payload['bcc'] ?? []) as $bcc) {
                $message->bcc($bcc);
            }

            if (!empty($payload['reply_to'])) {
                $message->replyTo((string) $payload['reply_to']);
            }
        };

        $html = trim((string) ($payload['html'] ?? ''));
        $text = (string) ($payload['text'] ?? strip_tags($html));

        if ($html !== '') {
            Mail::html($html, $callback);
        } else {
            Mail::raw($text, $callback);
        }

        Log::info('LocalSmtpProvider: dispatched email through Laravel mailer', [
            'to' => $payload['to'] ?? null,
            'subject' => $payload['subject'] ?? null,
        ]);

        return (string) Str::uuid();
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        return $this->send($channel, $payload);
    }

    /**
     * @param  mixed  $recipients
     * @return array<int, string>
     */
    private function normalizeRecipients(mixed $recipients): array
    {
        if (is_string($recipients) && trim($recipients) !== '') {
            return [trim($recipients)];
        }

        if (!is_array($recipients)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($recipient) => is_string($recipient) ? trim($recipient) : '',
            $recipients
        )));
    }
}





