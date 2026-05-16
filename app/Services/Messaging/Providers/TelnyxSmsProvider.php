<?php

namespace App\Services\Messaging\Providers;

use App\Models\SmsNumber;
use App\Services\Messaging\Contracts\SmsProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelnyxSmsProvider implements SmsProviderInterface
{
    /**
     * Send an outbound SMS via the Telnyx Messaging API.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(SmsNumber $number, array $payload): string
    {
        $to = $this->formatPhoneNumber((string) ($payload['to'] ?? ''));
        $body = trim((string) ($payload['text'] ?? $payload['body_text'] ?? ''));

        if ($body === '') {
            throw new RuntimeException('SMS body cannot be empty.');
        }

        $messagingProfileId = $this->resolveMessagingProfileId($number);
        $from = $this->resolveFromNumber($number);

        // Per Telnyx docs:
        //  - If sending from a specific phone number, send { from, to, text } and messaging_profile_id is optional.
        //  - If sending via a number pool / messaging profile only, send { messaging_profile_id, to, text } and omit from.
        $request = ['to' => $to, 'text' => $body];
        if ($from !== null) {
            $request['from'] = $from;
            if ($messagingProfileId !== null) {
                $request['messaging_profile_id'] = $messagingProfileId;
            }
        } elseif ($messagingProfileId !== null) {
            $request['messaging_profile_id'] = $messagingProfileId;
        } else {
            throw new RuntimeException('Telnyx send requires either a from number or a messaging_profile_id.');
        }

        try {
            $response = $this->client()->post('/messages', $request);
        } catch (RequestException $exception) {
            $this->logFailure($number, $from, $to, $exception);
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        } catch (\Throwable $exception) {
            $this->logFailure($number, $from, $to, $exception);
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        $messageId = data_get($response->json(), 'data.id');
        if (!is_string($messageId) || $messageId === '') {
            $this->logFailure($number, $from, $to, new RuntimeException('Telnyx response missing data.id'));
            throw new RuntimeException('Telnyx response missing message id.');
        }

        Log::info('Telnyx SMS sent successfully', [
            'sms_number_id' => $number->id,
            'provider_message_id' => $messageId,
            'from' => $from,
            'to' => $to,
        ]);

        return $messageId;
    }

    /**
     * Verify Telnyx connectivity. Prefer messaging_profile lookup, fall back to phone_numbers, else a lightweight ping.
     *
     * @return array<string, mixed>
     */
    public function testConnection(?SmsNumber $number = null): array
    {
        try {
            $messagingProfileId = $number?->messaging_profile_id
                ?: (string) config('services.telnyx.messaging_profile_id');

            if ($messagingProfileId !== '') {
                $response = $this->client()->get('/messaging_profiles/' . urlencode($messagingProfileId));
                $data = data_get($response->json(), 'data', []);

                return [
                    'success' => true,
                    'message' => 'Telnyx connection successful',
                    'messaging_profile_id' => data_get($data, 'id'),
                    'messaging_profile_name' => data_get($data, 'name'),
                ];
            }

            $from = $number?->phone_number ?: (string) config('services.telnyx.from_number');
            if ($from !== '') {
                $response = $this->client()->get('/phone_numbers', [
                    'filter[phone_number]' => $this->formatPhoneNumber($from),
                ]);
                $first = data_get($response->json(), 'data.0', []);

                return [
                    'success' => true,
                    'message' => 'Telnyx connection successful',
                    'phone_number' => data_get($first, 'phone_number'),
                    'phone_number_id' => data_get($first, 'id'),
                ];
            }

            // Lightweight ping
            $this->client()->get('/messaging_profiles', ['page[size]' => 1]);

            return [
                'success' => true,
                'message' => 'Telnyx connection successful',
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function formatPhoneNumber(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        return '+' . ltrim($digits, '+');
    }

    protected function resolveFromNumber(SmsNumber $number): ?string
    {
        $configured = $number->phone_number ?: config('services.telnyx.from_number');

        if (!$configured) {
            return null;
        }

        return $this->formatPhoneNumber((string) $configured);
    }

    protected function resolveMessagingProfileId(SmsNumber $number): ?string
    {
        $perNumber = $number->messaging_profile_id ?? null;
        if (is_string($perNumber) && $perNumber !== '') {
            return $perNumber;
        }

        $global = (string) config('services.telnyx.messaging_profile_id');

        return $global !== '' ? $global : null;
    }

    protected function client(): PendingRequest
    {
        $apiKey = (string) config('services.telnyx.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('Telnyx API key is not configured.');
        }

        $baseUrl = (string) config('services.telnyx.api_base', 'https://api.telnyx.com/v2');

        return Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->baseUrl($baseUrl)
            ->timeout(15)
            ->retry(3, 250, function ($exception, $request) {
                if ($exception instanceof RequestException) {
                    $status = $exception->response?->status();
                    return $status === 429 || ($status !== null && $status >= 500);
                }

                return true; // network errors
            }, throw: false)
            ->throw();
    }

    protected function logFailure(SmsNumber $number, ?string $from, string $to, \Throwable $exception): void
    {
        $context = [
            'sms_number_id' => $number->id,
            'from' => $from,
            'to' => $to,
            'error' => $exception->getMessage(),
        ];

        if ($exception instanceof RequestException) {
            $context['status'] = $exception->response?->status();
            $context['body'] = $exception->response?->json();
        }

        Log::error('Telnyx SMS failed', $context);
    }
}
