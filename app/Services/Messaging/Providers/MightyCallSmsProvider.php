<?php

namespace App\Services\Messaging\Providers;

use App\Models\SmsNumber;
use App\Services\Messaging\Contracts\SmsProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MightyCallSmsProvider implements SmsProviderInterface
{
    protected string $baseUrl;
    protected string $accountApiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.mightycall.base_url', 'https://ccapi.mightycall.com/v4');
        $this->accountApiKey = config('services.mightycall.api_key', '');
    }

    /**
     * Send SMS via MightyCall API
     */
    public function send(SmsNumber $number, array $payload): string
    {
        $accountApiKey = $this->accountApiKey;
        $numberKey = $number->mighty_call_key;

        if (empty($accountApiKey)) {
            Log::warning('MightyCall account API key missing', ['sms_number_id' => $number->id]);
            throw new \RuntimeException('MightyCall account API key not configured');
        }

        if (empty($numberKey)) {
            Log::warning('MightyCall user key missing for number', ['sms_number_id' => $number->id]);
            throw new \RuntimeException('MightyCall user key not configured for this number');
        }

        $from = $this->formatPhoneNumber($number->phone_number);
        $to = $this->formatPhoneNumber($payload['to']);
        $message = $payload['text'] ?? $payload['body_text'] ?? '';

        Log::info('MightyCall: Sending SMS', [
            'from' => $from,
            'to' => $to,
            'message_length' => strlen($message),
        ]);

        // Try with Bearer token first, fallback to API key only
        $token = $this->getAccessToken($accountApiKey, $numberKey);

        $headers = [
            'x-api-key' => $accountApiKey,
            'Content-Type' => 'application/json',
        ];
        
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $endpoint = '/api/contactcenter/messages/send';
        $payload = [
            'from' => $from,
            'to' => [$to],
            'message' => $message,
        ];

        Log::info('MightyCall: Sending SMS request', [
            'url' => "{$this->baseUrl}{$endpoint}",
            'payload' => $payload,
        ]);

        $response = Http::withoutVerifying()
            ->withHeaders($headers)
            ->timeout(30)
            ->post("{$this->baseUrl}{$endpoint}", $payload);

        if ($response->failed()) {
            Log::error('MightyCall SMS failed', [
                'sms_number_id' => $number->id,
                'status' => $response->status(),
                'body' => $response->body(),
                'response' => $response->json(),
            ]);
            
            $errorMessage = $response->json()['message'] 
                ?? $response->json()['error'] 
                ?? $response->body();
            throw new \RuntimeException('Failed to send SMS: ' . $errorMessage);
        }

        $responseData = $response->json();
        $messageId = $responseData['id'] 
            ?? $responseData['messageId'] 
            ?? $responseData['data']['id'] 
            ?? Str::uuid()->toString();

        Log::info('MightyCall: SMS sent successfully', [
            'message_id' => $messageId,
            'from' => $from,
            'to' => $to,
        ]);

        return (string) $messageId;
    }

    /**
     * Get access token via OAuth flow
     * Uses POST /auth/token with client_id (API key) and client_secret (secret key)
     */
    protected function getAccessToken(string $apiKey, ?string $clientSecret = null): ?string
    {
        $clientSecret = $clientSecret ?: config('services.mightycall.secret_key');
        $cacheKey = 'mightycall_token_' . md5($apiKey . '|' . ($clientSecret ?? ''));
        
        // Check cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (empty($clientSecret)) {
            Log::info('MightyCall: No user key configured, cannot obtain access token');
            return null;
        }

        try {
            // Use the documented /auth/token endpoint with x-api-key header
            $response = Http::withoutVerifying()
                ->withHeaders(['x-api-key' => $apiKey])
                ->asForm()
                ->post("{$this->baseUrl}/auth/token", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $apiKey,
                    'client_secret' => $clientSecret,
                ]);

            Log::info('MightyCall: Auth token response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['access_token'] ?? null;
                $expiresIn = $data['expires_in'] ?? 86400; // Default 24 hours

                if ($token) {
                    // Cache token for slightly less than expiry time
                    Cache::put($cacheKey, $token, now()->addSeconds($expiresIn - 300));
                    
                    // Store refresh token if available
                    if (!empty($data['refresh_token'])) {
                        Cache::put($cacheKey . '_refresh', $data['refresh_token'], now()->addDays(30));
                    }
                    
                    Log::info('MightyCall: Access token obtained and cached');
                    return $token;
                }
            } else {
                Log::warning('MightyCall: Auth failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('MightyCall: Failed to get access token', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Fetch conversations/messages from MightyCall Journal API
     */
    public function fetchConversations(SmsNumber $number, array $filters = []): array
    {
        $accountApiKey = $this->accountApiKey;
        $numberKey = $number->mighty_call_key;

        if (empty($accountApiKey) || empty($numberKey)) {
            Log::warning('MightyCall configuration missing for number', ['sms_number_id' => $number->id]);
            return [];
        }

        $params = [
            'pageSize' => $filters['limit'] ?? 50,
            'skip' => $filters['offset'] ?? 0,
        ];
        
        if (!empty($filters['start_date'])) {
            $params['startUtc'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $params['endUtc'] = $filters['end_date'];
        }

        $token = $this->getAccessToken($accountApiKey, $numberKey);
        $headers = [
            'x-api-key' => $accountApiKey,
            'Content-Type' => 'application/json',
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // Try Journal API for message history
        $response = Http::withoutVerifying()
            ->withHeaders($headers)
            ->timeout(30)
            ->get("{$this->baseUrl}/api/journal", $params);

        if ($response->failed()) {
            // Fallback to contactcenter endpoint
            $response = Http::withoutVerifying()
                ->withHeaders($headers)
                ->timeout(30)
                ->get("{$this->baseUrl}/contactcenter/message", $params);
        }

        if ($response->failed()) {
            Log::error('MightyCall fetch conversations failed', [
                'sms_number_id' => $number->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        $data = $response->json();
        return $data['data'] ?? $data ?? [];
    }

    /**
     * Fetch message threads for a specific phone number
     */
    public function fetchThreads(SmsNumber $number, ?string $contactPhone = null): array
    {
        $accountApiKey = $this->accountApiKey;
        $numberKey = $number->mighty_call_key;

        if (empty($accountApiKey) || empty($numberKey)) {
            return [];
        }

        $token = $this->getAccessToken($accountApiKey, $numberKey);
        $headers = [
            'x-api-key' => $accountApiKey,
            'Content-Type' => 'application/json',
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $params = ['pageSize' => 100];
        if ($contactPhone) {
            $params['phoneNumber'] = $this->formatPhoneNumber($contactPhone);
        }

        $response = Http::withoutVerifying()
            ->withHeaders($headers)
            ->timeout(30)
            ->get("{$this->baseUrl}/contactcenter/message/threads", $params);

        if ($response->failed()) {
            Log::error('MightyCall fetch threads failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        $data = $response->json();
        return $data['data'] ?? $data ?? [];
    }

    /**
     * Get a single message by ID
     */
    public function getMessage(SmsNumber $number, string $messageId): ?array
    {
        $accountApiKey = $this->accountApiKey;
        $numberKey = $number->mighty_call_key;

        if (empty($accountApiKey) || empty($numberKey)) {
            return null;
        }

        $token = $this->getAccessToken($accountApiKey, $numberKey);
        $headers = [
            'x-api-key' => $accountApiKey,
            'Content-Type' => 'application/json',
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = Http::withoutVerifying()
            ->withHeaders($headers)
            ->timeout(30)
            ->get("{$this->baseUrl}/api/journal/{$messageId}");

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        return $data['data'] ?? $data ?? null;
    }

    /**
     * Test API connection with given credentials
     */
    public function testConnection(?string $apiKey = null, ?string $clientSecret = null): array
    {
        $apiKey = $apiKey ?: $this->accountApiKey;
        $clientSecret = $clientSecret ?: config('services.mightycall.secret_key');
        
        if (empty($apiKey)) {
            return [
                'success' => false,
                'error' => 'API key not configured',
            ];
        }

        if (empty($clientSecret)) {
            return [
                'success' => false,
                'error' => 'User key (client_secret) not configured',
            ];
        }

        try {
            $token = $this->getAccessToken($apiKey, $clientSecret);

            if (!$token) {
                return [
                    'success' => false,
                    'error' => 'Unable to obtain access token. Verify API key and user key.',
                ];
            }

            // Build headers with token if available
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            
            $headers['Authorization'] = 'Bearer ' . $token;
            $headers['x-api-key'] = $apiKey;

            // Try API endpoints to verify connection
            $endpoints = [
                '/users/current',
                '/account',
                '/phonenumbers',
            ];

            foreach ($endpoints as $endpoint) {
                $response = Http::withoutVerifying()
                    ->withHeaders($headers)
                    ->timeout(10)
                    ->get("{$this->baseUrl}{$endpoint}");

                Log::info('MightyCall test connection attempt', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                ]);

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'message' => 'Connection successful',
                        'endpoint' => $endpoint,
                        'data' => $response->json()['data'] ?? $response->json(),
                    ];
                }
            }

            // All endpoints failed - return the last error
            return [
                'success' => false,
                'error' => 'API authentication failed. Please verify your API key is correct.',
                'api_key_preview' => substr($apiKey, 0, 8) . '...',
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Format phone number to E.164 format (+1XXXXXXXXXX)
     */
    public function formatPhoneNumber(string $phone): string
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





