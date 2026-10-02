<?php

namespace App\Services\Messaging\Providers;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Models\MessageChannel;
use App\Services\Messaging\Contracts\EmailProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ResendProvider implements EmailProviderInterface
{
    public function send(MessageChannel $channel, array $payload): string
    {
        $key = trim((string) config('services.resend.key'));
        if ($key === '') {
            throw new EmailProviderRejectedException('Resend is not configured. Set RESEND_KEY.');
        }

        $address = (string) ($payload['from'] ?? $channel->from_email ?? config('mail.from.address'));
        $name = str_replace(["\r", "\n", '<', '>'], '', (string) ($channel->display_name ?: config('mail.from.name')));
        $body = [
            'from' => $name !== '' ? $name.' <'.$address.'>' : $address,
            'to' => [$payload['to']],
            'subject' => (string) ($payload['subject'] ?? ''),
        ];
        foreach (['html', 'text', 'reply_to'] as $field) {
            if (isset($payload[$field]) && $payload[$field] !== '') {
                $body[$field] = $payload[$field];
            }
        }
        foreach (['cc', 'bcc'] as $field) {
            if (! empty($payload[$field])) {
                $body[$field] = array_values((array) $payload[$field]);
            }
        }
        if (! empty($payload['attachments'])) {
            $body['attachments'] = array_map(static fn (array $file): array => [
                'filename' => (string) ($file['filename'] ?? $file['name'] ?? 'attachment'),
                // The messaging contract supplies raw bytes, including scheduled files.
                'content' => base64_encode((string) ($file['content'] ?? '')),
            ], $payload['attachments']);
        }

        // A persisted Message owns the key. Never retry an uncertain send via
        // another provider: a timeout or 5xx can follow successful acceptance.
        $response = Http::withOptions(['verify' => true])->withToken($key)
            ->acceptJson()->connectTimeout(10)->timeout(30)
            ->withHeaders(['Idempotency-Key' => (string) ($payload['idempotency_key'] ?? Str::uuid())])
            ->post('https://api.resend.com/emails', $body);

        if ($response->failed()) {
            $error = (string) $response->json('name', 'provider_error');
            // 409 can mean an identical request is still being processed.
            // Unknown errors and server/network failures remain ambiguous.
            $exception = in_array($response->status(), [400, 401, 403, 404, 405, 422, 429], true)
                ? EmailProviderRejectedException::class : \RuntimeException::class;
            throw new $exception('Resend send failed (HTTP '.$response->status().', '.$error.').');
        }

        $id = $response->json('id');
        if (! is_string($id) || trim($id) === '') {
            throw new \RuntimeException('Resend accepted the request without a message identifier; reconcile before retrying.');
        }

        return $id;
    }

    public function schedule(MessageChannel $channel, array $payload): string
    {
        // Scheduling stays in the existing Laravel queue, for both providers.
        return $this->send($channel, $payload);
    }

    public function testConnection(): array
    {
        $key = trim((string) config('services.resend.key'));
        if ($key === '') {
            return ['success' => false, 'error' => 'Resend is not configured. Set RESEND_KEY.'];
        }
        if (! config('services.resend.domain_verified')) {
            return ['success' => false, 'error' => 'Resend sending domain is not verified.'];
        }
        try {
            $response = Http::withOptions(['verify' => true])->withToken($key)
                ->acceptJson()->connectTimeout(5)->timeout(10)->get('https://api.resend.com/domains');
            // A sending-only key cannot list domains. Resend explicitly reports
            // that scope after authenticating it; verification is an activation prerequisite.
            $restricted = in_array($response->status(), [401, 403], true)
                && $response->json('name') === 'restricted_api_key'
                && str_contains(strtolower((string) $response->json('message')), 'only send');

            return [
                'success' => $response->successful() || $restricted,
                'error' => $response->successful() || $restricted ? null : 'Resend authentication or connectivity check failed.',
            ];
        } catch (\Throwable $exception) {
            return ['success' => false, 'error' => 'Resend connectivity check failed.'];
        }
    }
}
