<?php

namespace App\Services\Messaging\Providers;

use App\Models\MessageChannel;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleOAuthProvider implements EmailProviderInterface
{
    public function send(MessageChannel $channel, array $payload): string
    {
        $config = $channel->config_json ?? [];

        if (empty($config['access_token'])) {
            Log::warning('Google OAuth access token missing', ['channel_id' => $channel->id]);
            throw new \RuntimeException('Google OAuth access token is required');
        }

        // Check if token needs refresh
        if (!empty($config['refresh_token']) && !empty($config['expires_at'])) {
            if (now()->timestamp >= $config['expires_at']) {
                $config = $this->refreshToken($channel, $config);
            }
        }

        // Construct raw email message
        $rawMessage = $this->constructRawEmail($channel, $payload);

        // Send via Gmail API
        $response = Http::withToken($config['access_token'])
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => base64_encode($rawMessage),
            ]);

        if ($response->failed()) {
            Log::error('Google OAuth send failed', [
                'channel_id' => $channel->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to send email via Google: ' . $response->body());
        }

        return $response->json('id', (string) Str::uuid());
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        // Gmail API doesn't support native scheduling, so we rely on app-level scheduling
        return $this->send($channel, $payload);
    }

    private function constructRawEmail(MessageChannel $channel, array $payload): string
    {
        $from = $channel->from_email ?? config('mail.from.address');
        $to = $payload['to'];
        $subject = $payload['subject'] ?? 'Message from Repro HQ';
        $replyTo = $payload['reply_to'] ?? $channel->reply_to_email ?? $from;

        $boundary = '----=_Part_' . uniqid();

        $headers = [
            "From: {$from}",
            "To: {$to}",
            "Reply-To: {$replyTo}",
            "Subject: {$subject}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];

        $body = "--{$boundary}\r\n";

        // Plain text part
        if (!empty($payload['text'])) {
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $body .= $payload['text'] . "\r\n\r\n";
            $body .= "--{$boundary}\r\n";
        }

        // HTML part
        if (!empty($payload['html'])) {
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $body .= $payload['html'] . "\r\n\r\n";
        }

        $body .= "--{$boundary}--";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function refreshToken(MessageChannel $channel, array $config): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $config['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            Log::error('Google OAuth token refresh failed', [
                'channel_id' => $channel->id,
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to refresh Google OAuth token');
        }

        $data = $response->json();
        $config['access_token'] = $data['access_token'];
        $config['expires_at'] = now()->addSeconds($data['expires_in'] ?? 3600)->timestamp;

        // Update channel with new token
        $channel->update(['config_json' => $config]);

        return $config;
    }
}

