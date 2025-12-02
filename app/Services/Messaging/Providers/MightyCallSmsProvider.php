<?php

namespace App\Services\Messaging\Providers;

use App\Models\SmsNumber;
use App\Services\Messaging\Contracts\SmsProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MightyCallSmsProvider implements SmsProviderInterface
{
    protected string $baseUrl = 'https://ccapi.mightycall.com/v4';

    public function send(SmsNumber $number, array $payload): string
    {
        if (empty($number->mighty_call_key)) {
            Log::warning('MightyCall configuration missing for number', ['sms_number_id' => $number->id]);
            return (string) Str::uuid();
        }

        // Format phone number to E.164 format if needed
        $from = $this->formatPhoneNumber($number->phone_number);
        $to = $this->formatPhoneNumber($payload['to']);

        $response = Http::withHeaders([
            'X-API-Key' => $number->mighty_call_key,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/contactcenter/message/send", [
            'from' => $from,
            'to' => [$to], // MightyCall expects an array
            'message' => $payload['text'] ?? $payload['body_text'] ?? '',
        ]);

        if ($response->failed()) {
            Log::error('MightyCall SMS failed', [
                'sms_number_id' => $number->id,
                'status' => $response->status(),
                'body' => $response->body(),
                'response' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to send SMS: ' . ($response->json()['message'] ?? $response->body()));
        }

        $responseData = $response->json();
        return (string) ($responseData['id'] ?? $responseData['messageId'] ?? Str::uuid()->toString());
    }

    /**
     * Fetch conversations/messages from MightyCall API
     */
    public function fetchConversations(SmsNumber $number, array $filters = []): array
    {
        if (empty($number->mighty_call_key)) {
            Log::warning('MightyCall configuration missing for number', ['sms_number_id' => $number->id]);
            return [];
        }

        $params = [];
        if (!empty($filters['phone_number'])) {
            $params['phoneNumber'] = $this->formatPhoneNumber($filters['phone_number']);
        }
        if (!empty($filters['limit'])) {
            $params['limit'] = $filters['limit'];
        }
        if (!empty($filters['offset'])) {
            $params['offset'] = $filters['offset'];
        }

        $response = Http::withHeaders([
            'X-API-Key' => $number->mighty_call_key,
            'Content-Type' => 'application/json',
        ])->get("{$this->baseUrl}/contactcenter/message", $params);

        if ($response->failed()) {
            Log::error('MightyCall fetch conversations failed', [
                'sms_number_id' => $number->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Format phone number to E.164 format (+1XXXXXXXXXX)
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);

        // If it doesn't start with 1, add it (assuming US numbers)
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        // Add + prefix
        return '+' . $digits;
    }
}





