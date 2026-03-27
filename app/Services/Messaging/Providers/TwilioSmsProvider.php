<?php

namespace App\Services\Messaging\Providers;

use App\Models\SmsNumber;
use App\Services\Messaging\Contracts\SmsProviderInterface;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

class TwilioSmsProvider implements SmsProviderInterface
{
    private ?Client $client = null;

    public function send(SmsNumber $number, array $payload): string
    {
        $to = $this->formatPhoneNumber((string) ($payload['to'] ?? ''));
        $body = trim((string) ($payload['text'] ?? $payload['body_text'] ?? ''));
        $from = $this->resolveFromNumber($number);

        if ($body === '') {
            throw new \RuntimeException('SMS body cannot be empty.');
        }

        try {
            $message = $this->client()->messages->create($to, [
                'from' => $from,
                'body' => $body,
                'statusCallback' => route('webhooks.twilio.status'),
            ]);
        } catch (TwilioException $exception) {
            Log::error('Twilio SMS failed', [
                'sms_number_id' => $number->id,
                'from' => $from,
                'to' => $to,
                'error' => $exception->getMessage(),
            ]);

            throw new \RuntimeException($exception->getMessage(), previous: $exception);
        }

        Log::info('Twilio SMS sent successfully', [
            'sms_number_id' => $number->id,
            'message_sid' => $message->sid,
            'from' => $from,
            'to' => $to,
        ]);

        return $message->sid;
    }

    public function testConnection(?SmsNumber $number = null): array
    {
        try {
            $account = $this->client()->api->v2010->account->fetch();

            if ($number?->twilio_phone_number_sid) {
                $phoneNumber = $this->client()->incomingPhoneNumbers($number->twilio_phone_number_sid)->fetch();

                return [
                    'success' => true,
                    'message' => 'Twilio connection successful',
                    'account_sid' => $account->sid,
                    'phone_number_sid' => $phoneNumber->sid,
                    'phone_number' => $phoneNumber->phoneNumber,
                ];
            }

            return [
                'success' => true,
                'message' => 'Twilio connection successful',
                'account_sid' => $account->sid,
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

    protected function resolveFromNumber(SmsNumber $number): string
    {
        $configured = $number->phone_number ?: config('services.twilio.from_number');

        if (!$configured) {
            throw new \RuntimeException('Twilio from number is not configured.');
        }

        return $this->formatPhoneNumber($configured);
    }

    protected function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $accountSid = (string) config('services.twilio.account_sid');
        $authToken = (string) config('services.twilio.auth_token');

        if ($accountSid === '' || $authToken === '') {
            throw new \RuntimeException('Twilio credentials are not configured.');
        }

        return $this->client = new Client($accountSid, $authToken);
    }
}
