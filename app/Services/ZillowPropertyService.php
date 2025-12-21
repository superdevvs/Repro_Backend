<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ZillowPropertyService
{
    private $clientId;
    private $clientSecret;
    private $serverToken;
    private $browserToken;
    private $baseUrl;

    public function __construct()
    {
        // Try to load from database settings first, fallback to config
        $settings = $this->loadSettings('integrations.zillow');
        
        $this->clientId = $settings['clientId'] ?? config('services.zillow.client_id');
        $this->clientSecret = $settings['clientSecret'] ?? config('services.zillow.client_secret');
        $this->serverToken = $settings['serverToken'] ?? config('services.zillow.server_token');
        $this->browserToken = $settings['browserToken'] ?? config('services.zillow.browser_token');
        $this->baseUrl = rtrim(config('services.zillow.base_url', 'https://api.bridgedataoutput.com/api/v2'), '/');
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
     * Fetch property details by address or MLS ID
     */
    public function fetchPropertyDetails(string $address, ?string $mlsId = null): ?array
    {
        try {
            // Use property search endpoint
            $params = [
                'access_token' => $this->serverToken,
                'address' => $address,
            ];

            if ($mlsId) {
                $params['mls'] = $mlsId;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serverToken,
                'Accept' => 'application/json',
            ])->withoutVerifying()->get($this->baseUrl . '/properties', $params);

            if (!$response->successful()) {
                Log::error('Zillow property lookup failed', [
                    'address' => $address,
                    'mls_id' => $mlsId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            
            // Handle different response structures
            $property = $data['bundle'] ?? $data['data'] ?? $data['property'] ?? ($data[0] ?? $data);

            if (!is_array($property) || empty($property)) {
                Log::warning('Zillow property lookup returned no data', [
                    'address' => $address,
                    'response' => $data,
                ]);
                return null;
            }

            return $this->parsePropertyData($property);

        } catch (\Exception $e) {
            Log::error('Zillow property lookup exception', [
                'address' => $address,
                'mls_id' => $mlsId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse property data into standardized format
     */
    private function parsePropertyData(array $property): array
    {
        $address = $property['address'] ?? $property;
        $propertyDetails = $property['propertyDetails'] ?? $property;
        $zestimate = $property['zestimate'] ?? $property['zEstimate'] ?? [];

        return [
            'mls_id' => $property['mlsId'] ?? $property['mls_id'] ?? $property['mlsNumber'] ?? null,
            'status' => $property['status'] ?? $property['listingStatus'] ?? null,
            'price' => $property['price'] ?? $zestimate['amount'] ?? null,
            'price_low' => $property['priceLow'] ?? $zestimate['low'] ?? null,
            'price_high' => $property['priceHigh'] ?? $zestimate['high'] ?? null,
            'days_on_market' => $property['daysOnMarket'] ?? $property['days_on_market'] ?? $property['dom'] ?? null,
            'beds' => $propertyDetails['bedrooms'] ?? $property['bedrooms'] ?? $property['beds'] ?? null,
            'baths' => $propertyDetails['bathrooms'] ?? $property['bathrooms'] ?? $property['baths'] ?? null,
            'sqft' => $propertyDetails['livingArea'] ?? $property['livingArea'] ?? $property['sqft'] ?? $property['squareFeet'] ?? null,
            'lot_size' => $propertyDetails['lotSize'] ?? $property['lotSize'] ?? $property['lotSizeSqft'] ?? null,
            'year_built' => $propertyDetails['yearBuilt'] ?? $property['yearBuilt'] ?? $property['year_built'] ?? null,
            'property_type' => $propertyDetails['propertyType'] ?? $property['propertyType'] ?? $property['property_type'] ?? null,
            'tax_assessed_value' => $property['taxAssessedValue'] ?? $property['tax_assessed_value'] ?? null,
            'tax_year' => $property['taxYear'] ?? $property['tax_year'] ?? null,
            'address' => [
                'street' => $address['streetNumber'] ?? $address['street_number'] ?? '',
                'street_name' => $address['streetName'] ?? $address['street_name'] ?? '',
                'city' => $address['city'] ?? '',
                'state' => $address['state'] ?? $address['stateCode'] ?? '',
                'zip' => $address['zipcode'] ?? $address['zip'] ?? '',
                'formatted' => $address['formattedStreetAddress'] ?? $address['formatted_address'] ?? '',
            ],
            'raw_data' => $property, // Store full response for reference
        ];
    }

    /**
     * Test connection to Zillow API
     */
    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serverToken,
                'Accept' => 'application/json',
            ])->timeout(5)->get($this->baseUrl . '/test', [
                'access_token' => $this->serverToken,
            ]);

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


