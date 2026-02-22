<?php

namespace App\Services;

use App\Services\BrightMls\BrightMlsStrategyInterface;
use App\Services\BrightMls\LegacyBrightMlsStrategy;
use App\Services\BrightMls\NewBrightMlsStrategy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BrightMlsService
{
    private const MAX_PHOTOS = 150;
    private const MAX_TOUR_URLS = 20;
    private const MODE_LEGACY = 'legacy';
    private const MODE_NEW = 'new';
    private const ENVIRONMENT_DEFAULTS = [
        self::MODE_LEGACY => [
            't1' => [
                'api_url' => 'https://bright-manifestservices.tst.brightmls.com',
                'import_url_base' => 'https://lmsedit.tst.brightmls.com',
            ],
            'p1' => [
                'api_url' => 'https://bright-manifestservices.brightmls.com',
                'import_url_base' => 'https://lmsedit.brightmls.com',
            ],
        ],
        self::MODE_NEW => [
            't1' => [
                'api_url' => 'https://agl1paz1msaasservices.bright-solutions.co',
                'import_url_base' => 'https://agl1paz1msaasservices.bright-solutions.co',
            ],
            'p1' => [
                'api_url' => 'https://agl1paz1msaasservices.bright-solutions.co',
                'import_url_base' => 'https://agl1paz1msaasservices.bright-solutions.co',
            ],
        ],
    ];

    private $apiUrl;
    private $apiUser;
    private $apiKey;
    private $vendorId;
    private $vendorName;
    private $defaultDocVisibility;
    private $mode;
    private $environment;
    private $importUrlBase;
    private $enabled;
    private BrightMlsStrategyInterface $strategy;

    public function __construct()
    {
        // Try to load from database settings first, fallback to config
        $settings = $this->loadSettings('integrations.bright_mls');

        $this->mode = strtolower((string) ($settings['apiMode'] ?? config('services.bright_mls.api_mode', self::MODE_LEGACY)));
        if (!in_array($this->mode, [self::MODE_LEGACY, self::MODE_NEW], true)) {
            $this->mode = self::MODE_LEGACY;
        }

        $this->environment = strtolower((string) ($settings['environment'] ?? config('services.bright_mls.environment', 't1')));
        $defaults = $this->resolveEnvironmentDefaults($this->mode, $this->environment);
        
        $this->apiUrl = rtrim($settings['apiUrl'] ?? config('services.bright_mls.api_url', $defaults['api_url']), '/');
        $this->apiUser = $settings['apiUser'] ?? config('services.bright_mls.api_user');
        $this->apiKey = $settings['apiKey'] ?? config('services.bright_mls.api_key');
        $this->vendorId = $settings['vendorId'] ?? config('services.bright_mls.vendor_id');
        $this->vendorName = $settings['vendorName'] ?? config('services.bright_mls.vendor_name', 'Repro Photos');
        $this->defaultDocVisibility = $settings['defaultDocVisibility'] ?? config('services.bright_mls.default_doc_visibility', 'private');
        $this->importUrlBase = rtrim($settings['importUrlBase'] ?? config('services.bright_mls.import_url_base', $defaults['import_url_base']), '/');
        $this->enabled = $settings['enabled'] ?? config('services.bright_mls.enabled', true);
        $this->strategy = $this->buildStrategy();
    }

    private function buildStrategy(): BrightMlsStrategyInterface
    {
        if ($this->mode === self::MODE_NEW) {
            return new NewBrightMlsStrategy($this->apiUrl);
        }

        return new LegacyBrightMlsStrategy($this->apiUrl, $this->importUrlBase);
    }

    private function resolveEnvironmentDefaults(string $mode, string $environment): array
    {
        return self::ENVIRONMENT_DEFAULTS[$mode][$environment]
            ?? self::ENVIRONMENT_DEFAULTS[$mode]['t1']
            ?? self::ENVIRONMENT_DEFAULTS[self::MODE_LEGACY]['t1'];
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

        if (empty($this->apiUrl)) {
            return [
                'success' => false,
                'status' => 'config_error',
                'error' => 'Bright MLS API URL is missing',
                'response' => null,
            ];
        }

        if ($this->mode === self::MODE_LEGACY && empty($this->importUrlBase)) {
            return [
                'success' => false,
                'status' => 'config_error',
                'error' => 'Bright MLS import URL base is missing for legacy mode',
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
                return $this->normalizeListItem($item, $index + 1);
            })->all();

            $manifestPayloadData = [
                'vendorId' => $effectiveVendorId,
                'vendorName' => $this->vendorName ?? ($manifestData['vendorName'] ?? 'Repro Photos'),
                'dateFileCreated' => $manifestData['dateFileCreated'] ?? now()->toIso8601String(),
                'listItems' => $normalizedItems,
            ];

            // Only include optional fields if they have values
            if (!empty($manifestData['propertyAddress'])) {
                $manifestPayloadData['propertyAddress'] = $manifestData['propertyAddress'];
            }
            if (!empty($manifestData['mlsId'])) {
                $manifestPayloadData['mlsId'] = $manifestData['mlsId'];
            }

            $payload = $this->strategy->buildManifest($manifestPayloadData);
            $localValidationErrors = $this->strategy->validatePayload($payload);
            if (!empty($localValidationErrors)) {
                return [
                    'success' => false,
                    'status' => 'validation_error',
                    'error' => 'Manifest validation failed',
                    'validation_errors' => $localValidationErrors,
                    'response' => ['payload' => $payload],
                    'mode' => $this->mode,
                    'environment' => $this->environment,
                    'payload_snapshot' => $payload,
                    'published_at' => now()->toIso8601String(),
                ];
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
                    'mode' => $this->mode,
                    'environment' => $this->environment,
                    'payload_snapshot' => $payload,
                    'published_at' => now()->toIso8601String(),
                ];
            }

            $data = $response->json();

            $manifestId = $data['manifestId'] ?? $data['id'] ?? null;

            return [
                'success' => true,
                'status' => 'published',
                'manifest_id' => $manifestId,
                'manifest_uuid' => $manifestId,
                'redirect_url' => $manifestId ? $this->getRedirectUrl($manifestId) : null,
                'response' => $data,
                'mode' => $this->mode,
                'environment' => $this->environment,
                'payload_snapshot' => $payload,
                'published_at' => now()->toIso8601String(),
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
                'mode' => $this->mode,
                'environment' => $this->environment,
                'published_at' => now()->toIso8601String(),
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
                        'description' => $this->trimText($photo['description'] ?? '', 50),
                        'id' => $itemId++,
                        'roomType' => $photo['roomType'] ?? '',
                    ];
                }
            }
        }

        // Add iGUIDE tour (mediaType: tour_url per Bright MLS docs)
        if (!empty($options['iguide_tour_url'])) {
            $listItems[] = [
                'fileName' => $this->trimText('iGUIDE 3D Tour', 25),
                'tourUrl' => $options['iguide_tour_url'],
                'lastModified' => now()->toIso8601String(),
                'mediaType' => 'tour_url',
                'description' => $this->trimText('3D interactive tour', 50),
                'id' => $itemId++,
            ];
        }

        // Add slideshow/video tour (mediaType: tour_url per Bright MLS docs)
        if (!empty($options['slideshow_url'])) {
            $listItems[] = [
                'fileName' => $this->trimText('Property Slideshow', 25),
                'tourUrl' => $options['slideshow_url'],
                'lastModified' => now()->toIso8601String(),
                'mediaType' => 'tour_url',
                'description' => $this->trimText('Property slideshow', 50),
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
                            'description' => $this->trimText($doc['description'] ?? 'Floor plan', 50),
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
                            'description' => $this->trimText($doc['description'] ?? '', 50),
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
                    'mode' => $this->mode,
                    'environment' => $this->environment,
                ];
            }

            // 401/403 = bad credentials
            if ($status === 401 || $status === 403) {
                return [
                    'success' => false,
                    'status' => $status,
                    'message' => 'Authentication failed — check your Vendor ID and API Key',
                    'mode' => $this->mode,
                    'environment' => $this->environment,
                ];
            }

            return [
                'success' => false,
                'status' => $status,
                'message' => 'Unexpected response (HTTP ' . $status . ')',
                'mode' => $this->mode,
                'environment' => $this->environment,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Connection error: ' . $e->getMessage(),
                'mode' => $this->mode,
                'environment' => $this->environment,
            ];
        }
    }

    /**
     * Get the Bright MLS redirect URL for a published manifest
     */
    public function getRedirectUrl(string $manifestId): string
    {
        return $this->strategy->buildImportUrl($manifestId);
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function applyPublishResultToShoot(\App\Models\Shoot $shoot, array $result): void
    {
        $publishedAtIso = $result['published_at'] ?? now()->toIso8601String();

        $shoot->bright_mls_publish_status = $result['status'] ?? ($result['success'] ? 'published' : 'error');
        $shoot->bright_mls_last_published_at = $result['success'] ? now() : $shoot->bright_mls_last_published_at;
        $shoot->bright_mls_response = json_encode($result);

        if (!empty($result['manifest_id'])) {
            $shoot->bright_mls_manifest_id = $result['manifest_id'];
        }

        $integrationFlags = is_array($shoot->integration_flags) ? $shoot->integration_flags : [];
        $integrationFlags['bright_mls_mode'] = $result['mode'] ?? $this->mode;
        $integrationFlags['bright_mls_environment'] = $result['environment'] ?? $this->environment;
        $integrationFlags['bright_mls_last_payload_snapshot'] = $result['payload_snapshot'] ?? null;
        $integrationFlags['bright_mls_last_uuid'] = $result['manifest_id'] ?? ($integrationFlags['bright_mls_last_uuid'] ?? null);
        $integrationFlags['bright_mls_last_attempted_at'] = $publishedAtIso;
        $shoot->integration_flags = $integrationFlags;
        $shoot->save();
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
            $this->applyPublishResultToShoot($shoot, $result);

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

    private function normalizeListItem(array $item, int $id): array
    {
        $item['id'] = $item['id'] ?? $id;
        $item['lastModified'] = $item['lastModified'] ?? now()->toIso8601String();
        $item['description'] = $this->trimText($item['description'] ?? '', 50);

        if (($item['mediaType'] ?? null) === 'tour_url') {
            $item['fileName'] = $this->trimText($item['fileName'] ?? '', 25);
        }

        return $item;
    }

    private function trimText(?string $value, int $maxLength): string
    {
        $trimmed = trim((string) ($value ?? ''));
        if (strlen($trimmed) <= $maxLength) {
            return $trimmed;
        }

        return substr($trimmed, 0, $maxLength);
    }
}


