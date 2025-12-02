<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class IguideService
{
    private $apiUsername;
    private $apiPassword;
    private $apiKey;
    private $baseUrl;
    private $webhookUrl;

    public function __construct()
    {
        // Try to load from database settings first, fallback to config
        $settings = $this->loadSettings('integrations.iguide');
        
        $this->apiUsername = $settings['apiUsername'] ?? config('services.iguide.api_username');
        $this->apiPassword = $settings['apiPassword'] ?? config('services.iguide.api_password');
        $this->apiKey = $settings['apiKey'] ?? config('services.iguide.api_key');
        $this->baseUrl = rtrim(config('services.iguide.api_url', 'https://api.iguide.com'), '/');
        $this->webhookUrl = config('services.iguide.webhook_url');
    }

    private function loadSettings(string $key): array
    {
        try {
            $setting = DB::table('settings')->where('key', $key)->first();
            if ($setting && $setting->type === 'json') {
                return json_decode($setting->value, true) ?? [];
            }
        } catch (\Exception $e) {
            Log::warning('Could not load settings from database', ['key' => $key]);
        }
        return [];
    }

    /**
     * Sync iGUIDE data for a property
     */
    public function syncProperty(string $propertyId): ?array
    {
        try {
            // Authenticate first
            $authToken = $this->authenticate();
            if (!$authToken) {
                Log::error('iGUIDE authentication failed');
                return null;
            }

            // Fetch property details
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $authToken,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/properties/' . $propertyId);

            if (!$response->successful()) {
                Log::error('iGUIDE property fetch failed', [
                    'property_id' => $propertyId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            return $this->parsePropertyData($data);

        } catch (\Exception $e) {
            Log::error('iGUIDE sync exception', [
                'property_id' => $propertyId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Search for iGUIDE properties by address
     */
    public function searchByAddress(string $address): ?array
    {
        try {
            $authToken = $this->authenticate();
            if (!$authToken) {
                return null;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $authToken,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/properties/search', [
                'address' => $address,
            ]);

            if (!$response->successful()) {
                Log::warning('iGUIDE property search returned no results', [
                    'address' => $address,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();
            $properties = $data['properties'] ?? $data['data'] ?? ($data ? [$data] : []);

            if (empty($properties)) {
                return null;
            }

            // Return first match
            return $this->parsePropertyData($properties[0]);

        } catch (\Exception $e) {
            Log::error('iGUIDE search exception', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Authenticate with iGUIDE API
     */
    private function authenticate(): ?string
    {
        try {
            // Try API key first if available
            if ($this->apiKey) {
                return $this->apiKey;
            }

            // Otherwise use username/password
            if (!$this->apiUsername || !$this->apiPassword) {
                Log::error('iGUIDE credentials not configured');
                return null;
            }

            $response = Http::post($this->baseUrl . '/auth/login', [
                'username' => $this->apiUsername,
                'password' => $this->apiPassword,
            ]);

            if (!$response->successful()) {
                Log::error('iGUIDE authentication failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            return $data['token'] ?? $data['access_token'] ?? null;

        } catch (\Exception $e) {
            Log::error('iGUIDE authentication exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse property data from iGUIDE API response
     */
    private function parsePropertyData(array $data): array
    {
        return [
            'property_id' => $data['propertyId'] ?? $data['property_id'] ?? $data['id'] ?? null,
            'tour_url' => $data['tourUrl'] ?? $data['tour_url'] ?? $data['url'] ?? null,
            'floorplans' => $data['floorplans'] ?? $data['floor_plans'] ?? [],
            'room_measurements' => $data['roomMeasurements'] ?? $data['room_measurements'] ?? null,
            'address' => $data['address'] ?? null,
            'raw_data' => $data,
        ];
    }

    /**
     * Test connection to iGUIDE API
     */
    public function testConnection(): array
    {
        try {
            $authToken = $this->authenticate();
            
            if (!$authToken) {
                return [
                    'success' => false,
                    'status' => 401,
                    'message' => 'Authentication failed',
                ];
            }

            // Try a simple API call
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $authToken,
                'Accept' => 'application/json',
            ])->timeout(5)->get($this->baseUrl . '/health');

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->successful() ? 'Connection successful' : 'Connection failed',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }
}


