<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BrightMlsService
{
    private const MAX_PHOTOS = 150;
    private const MAX_TOUR_URLS = 20;

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
        
        $this->apiUrl = rtrim($settings['apiUrl'] ?? config('services.bright_mls.api_url', 'https://agl1paz1msaasservices.bright-solutions.co'), '/');
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

        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'status' => 'config_error',
                'error' => 'Bright MLS API key is missing',
                'response' => null,
            ];
        }

        $effectiveVendorId = $this->vendorId ?? ($manifestData['vendorId'] ?? null);
        if (empty($effectiveVendorId)) {
            return [
                'success' => false,
                'status' => 'config_error',
                'error' => 'Bright MLS vendor ID is missing (required for X-API-USER header)',
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
                $data = json_decode($setting->value, true) ?? [];
                // Decrypt sensitive fields if stored encrypted
                if (str_starts_with($key, 'integrations.')) {
                    try {
                        $data = \App\Http\Controllers\API\SettingsController::decryptSensitiveFields($data);
                    } catch (\Throwable $decryptErr) {
                        Log::warning('Settings decryption failed, using raw values', [
                            'key' => $key,
                            'error' => $decryptErr->getMessage(),
                        ]);
                        // Fall through with raw data — plain-text values still work
                    }
                }
                return $data;
            }
        } catch (\Exception $e) {
            Log::warning('Could not load settings from database', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
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

            // mlsId and propertyAddress are optional per Bright MLS docs
            // We log a warning but do not reject — let Bright MLS validate
            if (empty($manifestData['mlsId'])) {
                Log::info('Bright MLS publish: mlsId is empty (optional field)');
            }
            if (empty($manifestData['propertyAddress'])) {
                Log::info('Bright MLS publish: propertyAddress is empty (optional field)');
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

            $tourUrlCount = $listItems->where('mediaType', 'tour_url')->count();
            if ($tourUrlCount > self::MAX_TOUR_URLS) {
                return [
                    'success' => false,
                    'status' => 'validation_error',
                    'error' => sprintf('Maximum %d tour URLs allowed per listing (received %d).', self::MAX_TOUR_URLS, $tourUrlCount),
                    'response' => ['tour_url_count' => $tourUrlCount],
                ];
            }

            $effectiveVendorId = $this->vendorId ?? $manifestData['vendorId'];

            // Ensure each listItem has required id and lastModified fields
            $normalizedItems = $listItems->values()->map(function ($item, $index) {
                $item['id'] = $item['id'] ?? ($index + 1);
                $item['lastModified'] = $item['lastModified'] ?? now()->toIso8601String();
                return $item;
            })->all();

            $payload = [
                'vendorId' => $effectiveVendorId,
                'vendorName' => $this->vendorName ?? ($manifestData['vendorName'] ?? 'Repro Photos'),
                'dateFileCreated' => $manifestData['dateFileCreated'] ?? now()->toIso8601String(),
                'listItems' => $normalizedItems,
            ];

            // Only include optional fields if they have values
            if (!empty($manifestData['propertyAddress'])) {
                $payload['propertyAddress'] = $manifestData['propertyAddress'];
            }
            if (!empty($manifestData['mlsId'])) {
                $payload['mlsId'] = $manifestData['mlsId'];
            }

            // X-API-USER must match vendorId per Bright MLS docs
            // Retry up to 2 times on 5xx server errors (with 1s delay)
            $maxRetries = 2;
            $response = null;
            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                $response = Http::withHeaders([
                    'X-API-USER' => $effectiveVendorId,
                    'X-API-KEY' => $this->apiKey ?? '',
                    'Content-Type' => 'application/json',
                ])->timeout(20)->post($this->apiUrl . '/manifest', $payload);

                if ($response->successful() || $response->status() < 500) {
                    break; // Don't retry on success or client errors (4xx)
                }

                if ($attempt < $maxRetries) {
                    Log::warning('Bright MLS server error, retrying', [
                        'attempt' => $attempt + 1,
                        'status' => $response->status(),
                        'mls_id' => $manifestData['mlsId'] ?? null,
                    ]);
                    sleep(1);
                }
            }

            if (!$response->successful()) {
                $errorBody = $response->json() ?? $response->body();
                Log::error('Bright MLS publish failed', [
                    'mls_id' => $manifestData['mlsId'] ?? null,
                    'status' => $response->status(),
                    'response' => $errorBody,
                ]);

                // Parse Bright MLS error format: { statusCode, message, body: [{path, message}] }
                $errorMessage = $errorBody['message'] ?? $errorBody['error'] ?? 'Unknown error';
                $validationErrors = [];
                if (is_array($errorBody) && !empty($errorBody['body']) && is_array($errorBody['body'])) {
                    foreach ($errorBody['body'] as $detail) {
                        $path = is_array($detail['path'] ?? null) ? implode('.', $detail['path']) : ($detail['path'] ?? '');
                        $validationErrors[] = ($path ? "[{$path}] " : '') . ($detail['message'] ?? 'Validation error');
                    }
                }

                return [
                    'success' => false,
                    'status' => 'error',
                    'error' => $errorMessage,
                    'validation_errors' => $validationErrors,
                    'response' => $errorBody,
                ];
            }

            $data = $response->json();

            $manifestId = $data['manifestId'] ?? $data['id'] ?? null;

            return [
                'success' => true,
                'status' => 'published',
                'manifest_id' => $manifestId,
                'redirect_url' => $manifestId ? $this->getRedirectUrl($manifestId) : null,
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

        $itemId = 1;

        // Add photos
        if (!empty($options['photos']) && is_array($options['photos'])) {
            foreach ($options['photos'] as $photo) {
                if (!empty($photo['url']) && ($photo['selected'] ?? true)) {
                    $listItems[] = [
                        'fileName' => $photo['filename'] ?? basename($photo['url']),
                        'imageUrls' => [
                            'fullSize' => $photo['url'],
                        ],
                        'lastModified' => now()->toIso8601String(),
                        'mediaType' => 'photo',
                        'description' => $photo['description'] ?? '',
                        'id' => $itemId++,
                        'roomType' => $photo['roomType'] ?? '',
                    ];
                }
            }
        }

        // Add iGUIDE tour (mediaType: tour_url per Bright MLS docs)
        if (!empty($options['iguide_tour_url'])) {
            $listItems[] = [
                'fileName' => 'iGUIDE 3D Tour',
                'tourUrl' => $options['iguide_tour_url'],
                'lastModified' => now()->toIso8601String(),
                'mediaType' => 'tour_url',
                'description' => '3D interactive tour',
                'id' => $itemId++,
            ];
        }

        // Add slideshow/video tour (mediaType: tour_url per Bright MLS docs)
        if (!empty($options['slideshow_url'])) {
            $listItems[] = [
                'fileName' => 'Property Slideshow',
                'tourUrl' => $options['slideshow_url'],
                'lastModified' => now()->toIso8601String(),
                'mediaType' => 'tour_url',
                'description' => 'Property slideshow',
                'id' => $itemId++,
            ];
        }

        // Add documents and floor plans
        if (!empty($options['documents']) && is_array($options['documents'])) {
            foreach ($options['documents'] as $doc) {
                if (!empty($doc['url'])) {
                    $fileName = $doc['filename'] ?? basename($doc['url']);
                    $isPdf = str_ends_with(strtolower($fileName), '.pdf');
                    $isFloorPlan = $isPdf && (
                        ($doc['type'] ?? '') === 'floor_plan' ||
                        stripos($fileName, 'floor') !== false ||
                        stripos($fileName, 'floorplan') !== false
                    );

                    if ($isFloorPlan) {
                        // Floor plans: mediaType floor_plan, requires docUrl + .pdf fileName
                        $listItems[] = [
                            'fileName' => $fileName,
                            'docUrl' => $doc['url'],
                            'lastModified' => now()->toIso8601String(),
                            'mediaType' => 'floor_plan',
                            'description' => $doc['description'] ?? 'Floor plan',
                            'id' => $itemId++,
                        ];
                    } else {
                        // Regular documents: requires docUrl + docVisibility
                        $listItems[] = [
                            'fileName' => $fileName,
                            'docUrl' => $doc['url'],
                            'docVisibility' => $doc['visibility'] ?? $this->defaultDocVisibility,
                            'lastModified' => now()->toIso8601String(),
                            'mediaType' => 'document',
                            'description' => $doc['description'] ?? '',
                            'id' => $itemId++,
                        ];
                    }
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
     * Sends a minimal POST to /manifest with an empty body to verify credentials.
     * A 400 (bad request / missing body) confirms the API is reachable and credentials are accepted.
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

            if (empty($this->vendorId)) {
                return [
                    'success' => false,
                    'status' => 'config_error',
                    'message' => 'Bright MLS vendor ID is missing (required for X-API-USER header)',
                ];
            }

            if (empty($this->apiKey)) {
                return [
                    'success' => false,
                    'status' => 'config_error',
                    'message' => 'Bright MLS API key is missing',
                ];
            }

            // Send a minimal POST to /manifest — a 400 "Missing request body" confirms
            // the API is reachable and credentials are valid. A 401/403 means bad creds.
            $response = Http::withHeaders([
                'X-API-USER' => $this->vendorId,
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post($this->apiUrl . '/manifest', []);

            $status = $response->status();

            // 400 = API reached, creds accepted, body validation failed (expected)
            // 200/201 = unexpected but fine
            if ($status === 400 || $response->successful()) {
                return [
                    'success' => true,
                    'status' => $status,
                    'message' => 'Connection successful — API is reachable and credentials are valid',
                ];
            }

            // 401/403 = bad credentials
            if ($status === 401 || $status === 403) {
                return [
                    'success' => false,
                    'status' => $status,
                    'message' => 'Authentication failed — check your Vendor ID and API Key',
                ];
            }

            return [
                'success' => false,
                'status' => $status,
                'message' => 'Unexpected response (HTTP ' . $status . ')',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get the Bright MLS redirect URL for a published manifest
     */
    public function getRedirectUrl(string $manifestId): string
    {
        return $this->apiUrl . '/mlsredirect/bright/' . $manifestId;
    }

    /**
     * Auto-publish manifest for a shoot when photos are delivered.
     * Called automatically when a shoot transitions to 'delivered' status.
     * Returns null if Bright MLS is disabled or shoot has no photos.
     */
    public function autoPublishForShoot(\App\Models\Shoot $shoot): ?array
    {
        try {
            if (!$this->enabled) {
                Log::info('Bright MLS auto-publish skipped: integration disabled', ['shoot_id' => $shoot->id]);
                return null;
            }

            if (empty($this->vendorId) || empty($this->apiKey)) {
                Log::info('Bright MLS auto-publish skipped: credentials not configured', ['shoot_id' => $shoot->id]);
                return null;
            }

            // Prevent duplicate submissions — skip if already published
            if (!empty($shoot->bright_mls_manifest_id)) {
                Log::info('Bright MLS auto-publish skipped: manifest already exists', [
                    'shoot_id' => $shoot->id,
                    'existing_manifest_id' => $shoot->bright_mls_manifest_id,
                ]);
                return null;
            }

            // Load files if not already loaded
            if (!$shoot->relationLoaded('files')) {
                $shoot->load('files');
            }

            // Get completed/verified photos
            $photos = $shoot->files
                ->whereIn('workflow_stage', ['completed', 'verified'])
                ->whereIn('media_type', ['image', 'edited', 'photo'])
                ->values();

            if ($photos->isEmpty()) {
                Log::info('Bright MLS auto-publish skipped: no completed photos', ['shoot_id' => $shoot->id]);
                return null;
            }

            // Build photo options from shoot files
            $photoOptions = $photos->map(function ($file) {
                $url = $file->url ?? $file->path ?? null;
                if ($url && !str_starts_with($url, 'http')) {
                    $url = \Illuminate\Support\Facades\Storage::disk('public')->url($url);
                }
                return [
                    'url' => $url,
                    'filename' => $file->filename ?? basename($url ?? ''),
                    'description' => '',
                    'roomType' => '',
                    'selected' => true,
                ];
            })->filter(fn($p) => !empty($p['url']))->values()->all();

            // Build document options (floorplans from iGUIDE)
            $documentOptions = [];
            if ($shoot->iguide_floorplans && is_array($shoot->iguide_floorplans)) {
                foreach ($shoot->iguide_floorplans as $fp) {
                    $fpUrl = is_array($fp) ? ($fp['url'] ?? null) : $fp;
                    if ($fpUrl) {
                        $documentOptions[] = [
                            'url' => $fpUrl,
                            'filename' => is_array($fp) ? ($fp['filename'] ?? 'floorplan.pdf') : 'floorplan.pdf',
                            'type' => 'floor_plan',
                            'description' => 'Floor plan',
                        ];
                    }
                }
            }

            $options = [
                'photos' => $photoOptions,
                'iguide_tour_url' => $shoot->iguide_tour_url,
                'slideshow_url' => null,
                'documents' => $documentOptions,
            ];

            $manifestData = $this->buildManifestFromShoot($shoot->toArray(), $options);
            $result = $this->publishManifest($manifestData);

            // Update shoot with publish result
            $shoot->bright_mls_publish_status = $result['status'];
            $shoot->bright_mls_last_published_at = $result['success'] ? now() : null;
            $shoot->bright_mls_response = json_encode($result);
            $shoot->bright_mls_manifest_id = $result['manifest_id'] ?? null;
            $shoot->save();

            Log::info('Bright MLS auto-publish ' . ($result['success'] ? 'succeeded' : 'failed'), [
                'shoot_id' => $shoot->id,
                'mls_id' => $shoot->mls_id,
                'manifest_id' => $result['manifest_id'] ?? null,
                'success' => $result['success'],
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Bright MLS auto-publish exception', [
                'shoot_id' => $shoot->id,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if auto-publish is available for a shoot
     */
    public function isAutoPublishAvailable(): bool
    {
        return $this->enabled && !empty($this->vendorId) && !empty($this->apiKey);
    }
}


