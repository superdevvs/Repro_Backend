<?php

namespace App\Services\Voice;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class VapiClient
{
    public function createOutboundCall(array $payload): array
    {
        $apiKey = (string) config('services.vapi.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('Vapi API key is not configured.');
        }

        $base = rtrim((string) config('services.vapi.api_base', 'https://api.vapi.ai'), '/');
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post("{$base}/call", $payload);

        $json = $response->json() ?: [];
        if (!$response->successful()) {
            $detail = $json['message'] ?? $json['error'] ?? $json['errors'][0]['message'] ?? $response->body();
            throw new RuntimeException('Vapi outbound call failed (' . $response->status() . '): ' . ($detail ?: 'unknown error'));
        }

        return is_array($json) ? $json : [];
    }
}
