<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AddressProviderSettingsController extends Controller
{
    /**
     * Get current address provider setting
     */
    public function getProvider(): JsonResponse
    {
        try {
            $setting = DB::table('settings')
                ->where('key', 'address_provider')
                ->first();

            $provider = $setting ? json_decode($setting->value, true) : 'zillow';

            return response()->json([
                'success' => true,
                'data' => [
                    'provider' => $provider,
                    'available_providers' => $this->getAvailableProviders(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get address provider setting',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update address provider setting
     */
    public function updateProvider(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'required|string|in:locationiq,geoapify,zillow',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $provider = $request->input('provider');

            // Check if API keys are required
            $providers = $this->getAvailableProviders();
            $selectedProvider = collect($providers)->firstWhere('id', $provider);

            if ($selectedProvider && $selectedProvider['requires_api_key']) {
                $apiKey = $this->getApiKeyForProvider($provider);
                if (empty($apiKey)) {
                    return response()->json([
                        'success' => false,
                        'message' => "API key required for {$selectedProvider['name']}. Please configure it in your .env file.",
                    ], 400);
                }
            }

            DB::table('settings')->updateOrInsert(
                ['key' => 'address_provider'],
                [
                    'value' => json_encode($provider),
                    'type' => 'string',
                    'description' => 'Address autocomplete provider',
                    'updated_at' => now(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Address provider updated successfully',
                'data' => [
                    'provider' => $provider,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update address provider',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of available providers with their details
     */
    public function getAvailableProviders(): array
    {
        return [
            [
                'id' => 'locationiq',
                'name' => 'LocationIQ',
                'description' => 'Commercial service with free tier. Requires API key. Based on OpenStreetMap data. Good for general address lookup.',
                'requires_api_key' => true,
                'is_open_source' => false,
                'rate_limit' => 'Depends on plan (free tier: 60 requests/day)',
                'cost' => 'Free tier available, paid plans available',
            ],
            [
                'id' => 'geoapify',
                'name' => 'Geoapify',
                'description' => 'Geocoding and address autocomplete API. Provides accurate address data with good coverage. Free tier available.',
                'requires_api_key' => true,
                'is_open_source' => false,
                'rate_limit' => 'Depends on plan (free tier: 3,000 requests/day)',
                'cost' => 'Free tier available, paid plans available',
            ],
            [
                'id' => 'zillow',
                'name' => 'Zillow (Bridge Data Output)',
                'description' => 'Real estate data API with address lookup. Provides property information along with addresses. Best for real estate related applications. Note: Does not support autocomplete, used for property enrichment.',
                'requires_api_key' => true,
                'is_open_source' => false,
                'rate_limit' => 'Based on subscription plan',
                'cost' => 'Paid subscription',
            ],
        ];
    }

    /**
     * Get API key/token for a provider from config
     */
    private function getApiKeyForProvider(string $provider): ?string
    {
        return match ($provider) {
            'locationiq' => config('services.locationiq.key'),
            'geoapify' => config('services.geoapify.key'),
            'zillow' => config('services.zillow.server_token'),
            default => null,
        };
    }
}


