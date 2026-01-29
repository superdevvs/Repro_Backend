<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BrightMlsService
{
    private const MAX_PHOTOS = 150;
    private const MAX_VIRTUAL_TOURS = 20;

    private $apiUrl;
    private $apiUser;
    private $apiKey;
    private $vendorId;
    private $vendorName;
    private $defaultDocVisibility;
    private $enabled;

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
        $this->enabled = $settings['enabled'] ?? config('services.bright_mls.enabled', true);
    }

    private function validateConfiguration(?array $manifestData = null): ?array
    {
        if (!$this->enabled) {
            return [
                'success' => false,
                'status' => 'disabled',
                'error' => 'Bright MLS integration is disabled',
                'response' => null,
            ];
        }

        if (empty($this->apiUser) || empty($this->apiKey)) {
            return [
                'success' => false,
                'status' => 'config_error',
                'error' => 'Bright MLS API credentials are missing',
                'response' => null,
            ];
        }

        if ($manifestData && empty($this->vendorId) && empty($manifestData['vendorId'])) {
            return [
                'success' => false,
                'status' => 'config_error',
                'error' => 'Bright MLS vendor ID is missing',
                'response' => null,
            ];
        }

        return null;
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
            if ($configError = $this->validateConfiguration($manifestData)) {
                return $configError;
            }

            if (empty($manifestData['mlsId'])) {
                return [
                    'success' => false,
                    'status' => 'validation_error',
                    'error' => 'MLS ID is required to publish a manifest',
                    'response' => null,
                ];
            }

            if (empty($manifestData['propertyAddress'])) {
                return [
                    'success' => false,
                    'status' => 'validation_error',
                    'error' => 'Property address is required to publish a manifest',
                    'response' => null,
                ];
            }

            if (empty($manifestData['listItems'])) {
                return [
                    'success' => false,
                    'status' => 'validation_error',
                    'error' => 'At least one media item is required to publish a manifest',
                    'response' => null,
                ];
            }

            $listItems = collect($manifestData['listItems'] ?? []);
            $photoCount = $listItems->where('mediaType', 'photo')->count();
            if ($photoCount > self::MAX_PHOTOS) {
                return [
                    'success' => false,
                    'status' => 'validation_error',
                    'error' => sprintf('Maximum %d photos allowed per listing (received %d).', self::MAX_PHOTOS, $photoCount),
                    'response' => ['photo_count' => $photoCount],
                ];
            }

            $virtualTourCount = $listItems->where('mediaType', 'virtual_tour')->count();
            if ($virtualTourCount > self::MAX_VIRTUAL_TOURS) {
                return [
                    'success' => false,
                    'status' => 'validation_error',
                    'error' => sprintf('Maximum %d virtual tours allowed per listing (received %d).', self::MAX_VIRTUAL_TOURS, $virtualTourCount),
                    'response' => ['virtual_tour_count' => $virtualTourCount],
                ];
            }

            $payload = [
                'propertyAddress' => $manifestData['propertyAddress'],
                'mlsId' => $manifestData['mlsId'],
                'vendorId' => $this->vendorId ?? $manifestData['vendorId'],
                'vendorName' => $this->vendorName ?? $manifestData['vendorName'],
                'dateFileCreated' => $manifestData['dateFileCreated'] ?? now()->toIso8601String(),
                'listItems' => $listItems->values()->all(),
            ];

            $response = Http::withHeaders([
                'x-api-user' => $this->apiUser ?? '',
                'x-api-key' => $this->apiKey ?? '',
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($this->apiUrl . '/manifests', $payload);

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
            if (!$this->enabled) {
                return [
                    'success' => false,
                    'status' => 'disabled',
                    'message' => 'Bright MLS integration is disabled',
                ];
            }

            if (empty($this->apiUser) || empty($this->apiKey)) {
                return [
                    'success' => false,
                    'status' => 'config_error',
                    'message' => 'Bright MLS API credentials are missing',
                ];
            }

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


