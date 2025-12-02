<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MailchimpProvider implements EmailProviderInterface
{
    public function send(MessageChannel $channel, array $payload): string
    {
        $config = $channel->config_json ?? [];

        if (empty($config['api_key']) || empty($config['server_prefix'])) {
            Log::warning('Mailchimp configuration missing', ['channel_id' => $channel->id]);
            return (string) Str::uuid();
        }

        $response = Http::withBasicAuth('anystring', $config['api_key'])
            ->baseUrl("https://{$config['server_prefix']}.api.mailchimp.com/3.0")
            ->post('/messages/send', [
                'from_email' => $channel->from_email,
                'subject' => $payload['subject'],
                'html' => $payload['html'],
                'text' => $payload['text'],
                'to' => [
                    [
                        'email' => $payload['to'],
                        'type' => 'to',
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Mailchimp send failed', [
                'channel_id' => $channel->id,
                'body' => $response->body(),
            ]);
            return (string) Str::uuid();
        }

        return $response->json('id', (string) Str::uuid());
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        return $this->send($channel, $payload);
    }
}





