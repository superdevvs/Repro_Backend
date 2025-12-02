<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BrightMlsService
{
    private $apiUrl;
    private $apiUser;
    private $apiKey;
    private $vendorId;
    private $vendorName;
    private $defaultDocVisibility;

    public function __construct()
    {
        // Try to load from database settings first, fallback to config
        $settings = $this->loadSettings('integrations.bright_mls');
        
        $this->apiUrl = rtrim($settings['apiUrl'] ?? config('services.bright_mls.api_url', 'https://bright-manifestservices.tst.brightmls.com'), '/');
        $this->apiUser = $settings['apiUser'] ?? config('services.bright_mls.api_user');
        $this->apiKey = $settings['apiKey'] ?? config('services.bright_mls.api_key');
        $this->vendorId = $settings['vendorId'] ?? config('services.bright_mls.vendor_id');
        $this->vendorName = $settings['vendorName'] ?? config('services.bright_mls.vendor_name', 'Repro Photos');
        $this->defaultDocVisibility = $settings['defaultDocVisibility'] ?? config('services.bright_mls.default_doc_visibility', 'private');
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
     * Publish media manifest to Bright MLS
     */
    public function publishManifest(array $manifestData): array
    {
        try {
            $payload = [
                'propertyAddress' => $manifestData['propertyAddress'],
                'mlsId' => $manifestData['mlsId'],
                'vendorId' => $this->vendorId ?? $manifestData['vendorId'],
                'vendorName' => $this->vendorName ?? $manifestData['vendorName'],
                'dateFileCreated' => $manifestData['dateFileCreated'] ?? now()->toIso8601String(),
                'listItems' => $manifestData['listItems'] ?? [],
            ];

            $response = Http::withHeaders([
                'x-api-user' => $this->apiUser ?? '',
                'x-api-key' => $this->apiKey ?? '',
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/manifests', $payload);

            if (!$response->successful()) {
                $errorBody = $response->json() ?? $response->body();
                Log::error('Bright MLS publish failed', [
                    'mls_id' => $manifestData['mlsId'],
                    'status' => $response->status(),
                    'response' => $errorBody,
                ]);

                return [
                    'success' => false,
                    'status' => 'error',
                    'error' => $errorBody['message'] ?? $errorBody['error'] ?? 'Unknown error',
                    'response' => $errorBody,
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'status' => 'published',
                'manifest_id' => $data['id'] ?? $data['manifestId'] ?? null,
                'response' => $data,
            ];

        } catch (\Exception $e) {
            Log::error('Bright MLS publish exception', [
                'mls_id' => $manifestData['mlsId'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
                'response' => null,
            ];
        }
    }

    /**
     * Build manifest data from shoot data
     */
    public function buildManifestFromShoot(array $shoot, array $options = []): array
    {
        $listItems = [];

        // Add photos
        if (!empty($options['photos']) && is_array($options['photos'])) {
            foreach ($options['photos'] as $photo) {
                if (!empty($photo['url']) && ($photo['selected'] ?? true)) {
                    $listItems[] = [
                        'fileName' => $photo['filename'] ?? basename($photo['url']),
                        'imageUrls' => [
                            'fullSize' => $photo['url'],
                        ],
                        'mediaType' => 'photo',
                        'description' => $photo['description'] ?? '',
                        'roomType' => $photo['roomType'] ?? '',
                    ];
                }
            }
        }

        // Add iGUIDE tour
        if (!empty($options['iguide_tour_url'])) {
            $listItems[] = [
                'fileName' => 'iGUIDE 3D Tour',
                'tourUrl' => $options['iguide_tour_url'],
                'mediaType' => 'virtual_tour',
                'description' => '3D interactive tour',
            ];
        }

        // Add slideshow/video tour
        if (!empty($options['slideshow_url'])) {
            $listItems[] = [
                'fileName' => 'Property Slideshow',
                'tourUrl' => $options['slideshow_url'],
                'mediaType' => 'virtual_tour',
                'description' => 'Property slideshow',
            ];
        }

        // Add documents (floorplans, etc.)
        if (!empty($options['documents']) && is_array($options['documents'])) {
            foreach ($options['documents'] as $doc) {
                if (!empty($doc['url'])) {
                    $listItems[] = [
                        'fileName' => $doc['filename'] ?? basename($doc['url']),
                        'docUrl' => $doc['url'],
                        'docVisibility' => $doc['visibility'] ?? $this->defaultDocVisibility,
                        'mediaType' => 'document',
                        'description' => $doc['description'] ?? '',
                    ];
                }
            }
        }

        return [
            'propertyAddress' => ($shoot['address'] ?? '') . ', ' . 
                                ($shoot['city'] ?? '') . ', ' . 
                                ($shoot['state'] ?? '') . ' ' . 
                                ($shoot['zip'] ?? ''),
            'mlsId' => $shoot['mls_id'] ?? '',
            'vendorId' => $this->vendorId,
            'vendorName' => $this->vendorName,
            'dateFileCreated' => now()->toIso8601String(),
            'listItems' => $listItems,
        ];
    }

    /**
     * Test connection to Bright MLS API
     */
    public function testConnection(): array
    {
        try {
            // Try to make a minimal test request
            $response = Http::withHeaders([
                'x-api-user' => $this->apiUser ?? '',
                'x-api-key' => $this->apiKey ?? '',
                'Content-Type' => 'application/json',
            ])->timeout(5)->get($this->apiUrl . '/health');

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


