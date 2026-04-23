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
    private ?DropboxWorkflowService $dropboxService;

    public function __construct(?DropboxWorkflowService $dropboxService = null)
    {
        $this->dropboxService = $dropboxService;
        // Try to load from database settings first, fallback to config
        $settings = $this->loadSettings('integrations.bright_mls');

        $this->mode = $this->resolveConfiguredMode($settings);

        $this->environment = strtolower((string) ($settings['environment'] ?? config('services.bright_mls.environment', 't1')));
        $defaults = $this->resolveEnvironmentDefaults($this->mode, $this->environment);
        
        $this->apiUrl = $this->normalizeConfiguredUrl(
            $settings['apiUrl'] ?? config('services.bright_mls.api_url', $defaults['api_url']),
            $defaults,
            'api_url'
        );
        $this->apiUser = $settings['apiUser'] ?? config('services.bright_mls.api_user');
        $this->apiKey = $settings['apiKey'] ?? config('services.bright_mls.api_key');
        $this->vendorId = $settings['vendorId'] ?? config('services.bright_mls.vendor_id');
        $this->vendorName = $settings['vendorName'] ?? config('services.bright_mls.vendor_name', 'Repro Photos');
        $this->defaultDocVisibility = $settings['defaultDocVisibility'] ?? config('services.bright_mls.default_doc_visibility', 'private');
        $this->importUrlBase = $this->normalizeConfiguredUrl(
            $settings['importUrlBase'] ?? config('services.bright_mls.import_url_base', $defaults['import_url_base']),
            $defaults,
            'import_url_base'
        );
        $this->enabled = $settings['enabled'] ?? config('services.bright_mls.enabled', true);
        $this->strategy = $this->buildStrategy();
    }

    private function resolveConfiguredMode(array $settings): string
    {
        $savedMode = strtolower((string) ($settings['apiMode'] ?? ''));
        if (in_array($savedMode, [self::MODE_LEGACY, self::MODE_NEW], true)) {
            return $savedMode;
        }

        $savedApiUrl = strtolower(trim((string) ($settings['apiUrl'] ?? '')));
        $savedImportUrlBase = strtolower(trim((string) ($settings['importUrlBase'] ?? '')));
        $savedCombinedUrls = $savedApiUrl . ' ' . $savedImportUrlBase;

        if (str_contains($savedCombinedUrls, 'bright-solutions.co')) {
            return self::MODE_NEW;
        }

        if (str_contains($savedCombinedUrls, 'brightmls.com')) {
            return self::MODE_LEGACY;
        }

        $configMode = strtolower((string) config('services.bright_mls.api_mode', self::MODE_NEW));
        if (!in_array($configMode, [self::MODE_LEGACY, self::MODE_NEW], true)) {
            return self::MODE_NEW;
        }

        return $configMode;
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

    private function normalizeConfiguredUrl($value, array $defaults, string $key): string
    {
        $normalized = is_string($value) ? rtrim(trim($value), '/') : '';
        $default = rtrim((string) ($defaults[$key] ?? ''), '/');

        if ($normalized === '') {
            return $default;
        }

        if ($this->mode === self::MODE_LEGACY) {
            $legacyDefaults = array_map(
                static fn (array $config): string => rtrim((string) ($config[$key] ?? ''), '/'),
                self::ENVIRONMENT_DEFAULTS[self::MODE_LEGACY]
            );

            if (in_array($normalized, $legacyDefaults, true)) {
                return $default;
            }

            $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));
            if ($host !== '' && str_contains($host, 'bright-solutions.co')) {
                return $default;
            }
        }

        return $normalized;
    }

    private function resolveAuthUser(?array $manifestData = null): ?string
    {
        $candidates = [
            $this->vendorId,
            $manifestData['vendorId'] ?? null,
            $this->apiUser,
            $manifestData['apiUser'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function resolveCandidateApiUrls(): array
    {
        $configUrl = config('services.bright_mls.api_url');
        $modeDefaults = $this->resolveEnvironmentDefaults($this->mode, $this->environment);
        $defaultUrl = $modeDefaults['api_url'] ?? null;

        $urls = array_filter([
            is_string($this->apiUrl) ? rtrim($this->apiUrl, '/') : null,
            is_string($configUrl) ? rtrim($configUrl, '/') : null,
            is_string($defaultUrl) ? rtrim($defaultUrl, '/') : null,
        ]);

        // For legacy mode, also try the alternate production URL variant and T1 as fallback
        if ($this->mode === self::MODE_LEGACY) {
            $urls[] = 'https://bright-manifestservices.brightmls.com';
            $urls[] = 'https://bright-manifestservices.prd.brightmls.com';
            $urls[] = 'https://bright-manifestservices.tst.brightmls.com';
        }

        return array_values(array_unique(array_filter($urls)));
    }

    private function shouldRetryWithFallback(?int $status): bool
    {
        return $status === null || $status === 401 || $status === 403;
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
                'error' => 'Bright MLS vendor ID is missing',
                'response' => null,
            ];
        }

        if (empty($this->resolveAuthUser($manifestData))) {
            return [
                'success' => false,
                'status' => 'config_error',
                'error' => 'Bright MLS API user or vendor ID is missing for authentication',
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
            $authUser = $this->resolveAuthUser($manifestData);

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

            // Bright MLS docs require X-API-USER to be the vendor ID. We still keep
            // apiUser as a compatibility fallback for older saved configurations.
            // If a custom saved API URL fails authentication, also try the configured/default endpoint.
            $maxRetries = 2;
            $response = null;
            $effectiveApiUrl = $this->apiUrl;
            $candidateUrls = $this->resolveCandidateApiUrls();
            $lastHttpResponse = null;

            foreach ($candidateUrls as $candidateUrlIndex => $candidateUrl) {
                for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                    $effectiveApiUrl = $candidateUrl;
                    try {
                        $response = Http::withHeaders([
                            'x-api-user' => $authUser,
                            'x-api-key' => $this->apiKey ?? '',
                            'Content-Type' => 'application/json',
                        ])->timeout(20)->post($candidateUrl . '/manifest', $payload);
                    } catch (\Throwable $requestError) {
                        Log::warning('Bright MLS request failed for endpoint', [
                            'url' => $candidateUrl,
                            'error' => $requestError->getMessage(),
                            'mls_id' => $manifestData['mlsId'] ?? null,
                        ]);

                        if (isset($candidateUrls[$candidateUrlIndex + 1])) {
                            break;
                        }

                        if ($lastHttpResponse) {
                            $response = $lastHttpResponse;
                            break 2;
                        }

                        throw $requestError;
                    }

                    $lastHttpResponse = $response;

                    if ($response->successful()) {
                        break 2;
                    }

                    $status = $response->status();
                    if ($status < 500) {
                        if ($this->shouldRetryWithFallback($status) && isset($candidateUrls[$candidateUrlIndex + 1])) {
                            Log::warning('Bright MLS auth failed on endpoint, trying fallback URL', [
                                'attempted_url' => $candidateUrl,
                                'next_url' => $candidateUrls[$candidateUrlIndex + 1],
                                'status' => $status,
                                'mls_id' => $manifestData['mlsId'] ?? null,
                            ]);
                            break;
                        }

                        break 2;
                    }

                    if ($attempt < $maxRetries) {
                        Log::warning('Bright MLS server error, retrying', [
                            'attempt' => $attempt + 1,
                            'status' => $status,
                            'url' => $candidateUrl,
                            'mls_id' => $manifestData['mlsId'] ?? null,
                        ]);
                        sleep(1);
                    }
                }
            }

            if (!$response->successful()) {
                $errorBody = $response->json() ?? $response->body();
                Log::error('Bright MLS publish failed', [
                    'mls_id' => $manifestData['mlsId'] ?? null,
                    'status' => $response->status(),
                    'response' => $errorBody,
                ]);

                // Parse Bright MLS error format:
                // New API: { success, message, description, error: [{path, message}] }
                // Legacy:  { statusCode, message, body: [{path, message}] }
                $errorMessage = $errorBody['description'] ?? $errorBody['message'] ?? $errorBody['error'] ?? 'Unknown error';

                // Detect auth rejection
                if ($response->status() === 401 || $response->status() === 403) {
                    $errorMessage = 'Authentication failed — check your API Key and Vendor/Customer ID. '
                        . 'HTTP ' . $response->status() . ': ' . ($errorBody['message'] ?? 'Unauthorized');
                }

                $validationErrors = [];
                // New API uses 'error' array, Legacy uses 'body' array
                $errorDetails = $errorBody['error'] ?? $errorBody['body'] ?? [];
                if (is_array($errorDetails)) {
                    foreach ($errorDetails as $detail) {
                        if (!is_array($detail)) continue;
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
                    'api_url' => $effectiveApiUrl,
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
                'api_url' => $effectiveApiUrl,
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
                'api_url' => $this->apiUrl,
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
                        'fileName' => $this->normalizeBrightMlsPhotoFilename(
                            $photo['filename'] ?? null,
                            $photo['url'],
                            $itemId
                        ),
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

        // Add CubiCasa floor plan link (mediaType: tour_url)
        if (!empty($options['cubicasa_url'])) {
            $listItems[] = [
                'fileName' => $this->trimText('CubiCasa Floor Plan', 25),
                'tourUrl' => $options['cubicasa_url'],
                'lastModified' => now()->toIso8601String(),
                'mediaType' => 'tour_url',
                'description' => $this->trimText('Interactive floor plan', 50),
                'id' => $itemId++,
            ];
        }

        // Add Matterport 3D tour (mediaType: tour_url)
        if (!empty($options['matterport_url'])) {
            $listItems[] = [
                'fileName' => $this->trimText('Matterport 3D Tour', 25),
                'tourUrl' => $options['matterport_url'],
                'lastModified' => now()->toIso8601String(),
                'mediaType' => 'tour_url',
                'description' => $this->trimText('3D virtual tour', 50),
                'id' => $itemId++,
            ];
        }

        // Add any additional tour URLs (generic key-value pairs)
        if (!empty($options['additional_tour_urls']) && is_array($options['additional_tour_urls'])) {
            foreach ($options['additional_tour_urls'] as $label => $url) {
                if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $listItems[] = [
                        'fileName' => $this->trimText((string) $label, 25),
                        'tourUrl' => $url,
                        'lastModified' => now()->toIso8601String(),
                        'mediaType' => 'tour_url',
                        'description' => $this->trimText((string) $label, 50),
                        'id' => $itemId++,
                    ];
                }
            }
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
                            'docVisibility' => $doc['visibility'] ?? $this->defaultDocVisibility,
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
            'propertyAddress' => $this->buildPropertyAddressFromShoot($shoot),
            'mlsId' => $this->extractMlsIdFromShoot($shoot),
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
                    'message' => 'Bright MLS vendor ID is missing',
                ];
            }

            if (empty($this->apiKey)) {
                return [
                    'success' => false,
                    'status' => 'config_error',
                    'message' => 'Bright MLS API key is missing',
                ];
            }

            $authUser = $this->resolveAuthUser();
            if (empty($authUser)) {
                return [
                    'success' => false,
                    'status' => 'config_error',
                    'message' => 'Bright MLS API user or vendor ID is missing for authentication',
                ];
            }

            // Send a minimal POST to /manifest — a 400 "Missing request body" confirms
            // the API is reachable and credentials are valid. If a saved custom URL fails
            // auth, try the configured/default endpoint as a compatibility fallback.
            $response = null;
            $status = null;
            $effectiveApiUrl = $this->apiUrl;
            $lastHttpResponse = null;

            $candidateUrls = $this->resolveCandidateApiUrls();
            foreach ($candidateUrls as $candidateUrlIndex => $candidateUrl) {
                $effectiveApiUrl = $candidateUrl;
                try {
                    $response = Http::withHeaders([
                        'x-api-user' => $authUser,
                        'x-api-key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ])->timeout(10)->post($candidateUrl . '/manifest', []);
                } catch (\Throwable $requestError) {
                    if (isset($candidateUrls[$candidateUrlIndex + 1])) {
                        continue;
                    }

                    if ($lastHttpResponse) {
                        $response = $lastHttpResponse;
                        $status = $response->status();
                        break;
                    }

                    throw $requestError;
                }

                $lastHttpResponse = $response;
                $status = $response->status();
                if (!$this->shouldRetryWithFallback($status) || !isset($candidateUrls[$candidateUrlIndex + 1])) {
                    break;
                }
            }

            // 400 = API reached, creds accepted, body validation failed (expected)
            // 200/201 = unexpected but fine
            if ($status === 400 || $response->successful()) {
                return [
                    'success' => true,
                    'status' => $status,
                    'message' => 'Connection successful — API is reachable and credentials are valid',
                    'mode' => $this->mode,
                    'environment' => $this->environment,
                    'api_url' => $effectiveApiUrl,
                ];
            }

            // 401/403 = bad credentials
            if ($status === 401 || $status === 403) {
                $body = $response->json() ?? [];
                $serverMsg = $body['message'] ?? 'Unauthorized';
                $message = 'Authentication failed (HTTP ' . $status . ': ' . $serverMsg . '). '
                    . 'Check your API Key and Customer/Vendor ID.';
                return [
                    'success' => false,
                    'status' => $status,
                    'message' => $message,
                    'mode' => $this->mode,
                    'environment' => $this->environment,
                    'api_url' => $effectiveApiUrl,
                ];
            }

            return [
                'success' => false,
                'status' => $status,
                'message' => 'Unexpected response (HTTP ' . $status . ')',
                'mode' => $this->mode,
                'environment' => $this->environment,
                'api_url' => $effectiveApiUrl,
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
            $dropbox = $this->dropboxService;
            $dropboxEnabled = $dropbox && config('services.dropbox.enabled', false);
            $photoOptions = $photos->map(function ($file) use ($dropboxEnabled, $dropbox) {
                $commentDescription = $this->latestMediaCommentDescription($file);
                // Try fields that are already full HTTP URLs
                foreach (['url', 'web_path', 'storage_path', 'path'] as $field) {
                    $val = $file->{$field} ?? null;
                    if ($val && str_starts_with($val, 'http')) {
                        return [
                            'url' => $val,
                            'filename' => $this->normalizeBrightMlsPhotoFilename(
                                $file->filename ?? null,
                                $val,
                                $file->id
                            ),
                            'description' => $commentDescription,
                            'roomType' => '',
                            'selected' => true,
                        ];
                    }
                }

                // When Dropbox is enabled, files live in Dropbox — get a temp link
                if ($dropboxEnabled && $file->dropbox_path) {
                    $tempUrl = $dropbox->getTemporaryLink($file->dropbox_path);
                    if ($tempUrl) {
                        return [
                            'url' => $tempUrl,
                            'filename' => $this->normalizeBrightMlsPhotoFilename(
                                $file->filename ?? null,
                                $tempUrl,
                                $file->id
                            ),
                            'description' => $commentDescription,
                            'roomType' => '',
                            'selected' => true,
                        ];
                    }
                }

                // Fallback: convert relative path to storage URL
                $url = $file->storage_path ?? $file->path ?? null;
                if ($url && !str_starts_with($url, 'http')) {
                    $url = \Illuminate\Support\Facades\Storage::disk('public')->url($url);
                }
                return [
                    'url' => $url,
                    'filename' => $this->normalizeBrightMlsPhotoFilename(
                        $file->filename ?? null,
                        $url,
                        $file->id
                    ),
                    'description' => $commentDescription,
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

            $tourOptions = $this->extractTourOptionsFromShoot($shoot);

            $options = [
                'photos' => $photoOptions,
                'iguide_tour_url' => $tourOptions['iguide_tour_url'],
                'slideshow_url' => $tourOptions['slideshow_url'],
                'cubicasa_url' => $tourOptions['cubicasa_url'],
                'matterport_url' => $tourOptions['matterport_url'],
                'additional_tour_urls' => $tourOptions['additional_tour_urls'],
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

        if (($item['mediaType'] ?? null) === 'photo') {
            $item['fileName'] = $this->normalizeBrightMlsPhotoFilename(
                $item['fileName'] ?? null,
                $item['imageUrls']['fullSize'] ?? null,
                $item['id'] ?? $id
            );
        }

        if (in_array($item['mediaType'] ?? null, ['document', 'floor_plan'], true)) {
            $item['docVisibility'] = $item['docVisibility'] ?? $this->defaultDocVisibility;
        }

        return $item;
    }

    private function normalizeBrightMlsPhotoFilename(?string $preferredName, ?string $photoUrl, int|string|null $fallbackId = null): string
    {
        $preferredName = is_string($preferredName) ? trim($preferredName) : '';
        $urlPath = is_string($photoUrl) ? (parse_url($photoUrl, PHP_URL_PATH) ?: $photoUrl) : '';
        $urlFilename = $urlPath !== '' ? trim(basename($urlPath)) : '';

        if (preg_match('/\.jpe?g$/i', $preferredName)) {
            return $preferredName;
        }

        if (preg_match('/\.jpe?g$/i', $urlFilename)) {
            return $urlFilename;
        }

        $candidate = $preferredName !== '' ? $preferredName : $urlFilename;
        $baseName = trim((string) pathinfo($candidate, PATHINFO_FILENAME));

        if ($baseName === '' || $baseName === '.' || $baseName === '..') {
            $baseName = 'photo' . ($fallbackId !== null ? '-' . $fallbackId : '');
        }

        return $baseName . '.jpg';
    }

    private function buildPropertyAddressFromShoot(array $shoot): string
    {
        $propertyDetails = $this->normalizeShootPropertyDetails($shoot['property_details'] ?? null);
        $address = $this->normalizeTextValue($shoot['address'] ?? ($propertyDetails['address'] ?? null));
        $city = $this->normalizeTextValue($shoot['city'] ?? ($propertyDetails['city'] ?? null));
        $state = $this->normalizeTextValue($shoot['state'] ?? ($propertyDetails['state'] ?? null));
        $zip = $this->normalizeTextValue($shoot['zip'] ?? ($propertyDetails['zip'] ?? $propertyDetails['zip_code'] ?? null));

        $parts = array_values(array_filter([$address, $city, $state]));
        $propertyAddress = implode(', ', $parts);

        if ($zip !== '') {
            $propertyAddress = trim($propertyAddress . ' ' . $zip);
        }

        return trim($propertyAddress, ", \t\n\r\0\x0B");
    }

    private function extractMlsIdFromShoot(array $shoot): string
    {
        $propertyDetails = $this->normalizeShootPropertyDetails($shoot['property_details'] ?? null);

        return $this->normalizeTextValue(
            $shoot['mls_id']
                ?? $propertyDetails['mls_id']
                ?? $propertyDetails['mlsId']
                ?? $propertyDetails['mlsNumber']
                ?? null
        );
    }

    private function normalizeShootPropertyDetails(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeTextValue(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function normalizeShootTourLinks(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function firstValidUrl(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $candidate = trim($value);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return null;
    }

    private function formatTourLabel(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', trim($key)));
    }

    /**
     * Tour link keys that should be forwarded to Bright MLS with explicit,
     * human-friendly labels. The corresponding `video_link` (the in-page video
     * embed) and the `embeds` iframe array are intentionally excluded per
     * product requirements.
     */
    private const BRIGHT_MLS_BROADCAST_TOUR_KEYS = [
        'branded' => 'Branded Tour',
        'mls' => 'MLS Tour',
        'generic_mls' => 'MLS Tour',
        'genericMls' => 'MLS Tour',
        'zillow_3d' => 'Zillow 3D Home Tour',
        'matterport_branded' => 'Matterport 3D Tour (Branded)',
        'matterport_mls' => 'Matterport 3D Tour (MLS)',
        'iguide_branded' => 'iGUIDE 3D Tour (Branded)',
        'iguide_mls' => 'iGUIDE 3D Tour (MLS)',
        'video_branded' => 'Branded Video',
        'video_mls' => 'MLS Video',
        'video_generic' => 'Property Video',
    ];

    private function extractAdditionalEmbedTourUrls(array $tourLinks, array $handledKeys, array $dedupeUrls = []): array
    {
        $additionalTourUrls = [];
        $seenUrls = array_flip(array_filter($dedupeUrls));

        foreach (self::BRIGHT_MLS_BROADCAST_TOUR_KEYS as $key => $label) {
            $candidate = $this->firstValidUrl($tourLinks[$key] ?? null);
            if (!$candidate || isset($seenUrls[$candidate]) || isset($additionalTourUrls[$label])) {
                continue;
            }

            $additionalTourUrls[$label] = $candidate;
            $seenUrls[$candidate] = true;
        }

        foreach ($tourLinks as $key => $value) {
            // Skip the `embeds` iframe array entirely.
            if ($key === 'embeds') {
                continue;
            }

            if (in_array($key, $handledKeys, true) || !is_string($value)) {
                continue;
            }

            $candidate = trim($value);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_URL) || isset($seenUrls[$candidate])) {
                continue;
            }

            $label = $this->formatTourLabel((string) $key);
            if (!isset($additionalTourUrls[$label])) {
                $additionalTourUrls[$label] = $candidate;
                $seenUrls[$candidate] = true;
            }
        }

        return $additionalTourUrls;
    }

    private function extractTourOptionsFromShoot(object|array $shoot): array
    {
        $shootData = is_array($shoot) ? $shoot : $shoot->toArray();
        $tourLinks = $this->normalizeShootTourLinks($shootData['tour_links'] ?? ($shoot->tour_links ?? []));

        $handledKeys = [
            // Dedicated payload slots
            'iguide_mls', 'iguide_branded', 'iguide', 'iGuide',
            'cubicasa', 'cubicasa_url',
            'matterport_mls', 'matterport_branded', 'matterport',
            'slideshow', 'slideshow_url', 'neo_tour', 'neotour',
            // Broadcast keys emitted explicitly by extractAdditionalEmbedTourUrls()
            'branded', 'mls', 'generic_mls', 'genericMls', 'zillow_3d',
            'video_branded', 'video_mls', 'video_generic',
            // Intentionally excluded from Bright MLS sync
            'video_link', 'embeds', 'tour_style', 'featured_embed_id', 'featured_embed',
            'realtor_client', 'realtor_client_id', 'realtorClient', 'realtorClientId',
        ];

        $iguideTourUrl = $this->firstValidUrl(
            $shootData['iguide_tour_url'] ?? ($shoot->iguide_tour_url ?? null),
            $tourLinks['iguide_branded'] ?? null,
            $tourLinks['iguide_mls'] ?? null,
            $tourLinks['iguide'] ?? null,
            $tourLinks['iGuide'] ?? null,
        );
        $slideshowUrl = $this->firstValidUrl(
            $tourLinks['slideshow'] ?? null,
            $tourLinks['slideshow_url'] ?? null,
            $tourLinks['neo_tour'] ?? null,
            $tourLinks['neotour'] ?? null,
        );
        $matterportUrl = $this->firstValidUrl(
            $tourLinks['matterport_branded'] ?? null,
            $tourLinks['matterport'] ?? null,
            $tourLinks['matterport_mls'] ?? null,
        );
        $cubicasaUrl = $this->firstValidUrl(
            $tourLinks['cubicasa_url'] ?? null,
            $tourLinks['cubicasa'] ?? null,
        );

        return [
            'iguide_tour_url' => $iguideTourUrl,
            'slideshow_url' => $slideshowUrl,
            'matterport_url' => $matterportUrl,
            'cubicasa_url' => $cubicasaUrl,
            'additional_tour_urls' => $this->extractAdditionalEmbedTourUrls(
                $tourLinks,
                $handledKeys,
                [$iguideTourUrl, $slideshowUrl, $matterportUrl, $cubicasaUrl],
            ),
        ];
    }

    private function trimText(?string $value, int $maxLength): string
    {
        $trimmed = trim((string) ($value ?? ''));
        if (strlen($trimmed) <= $maxLength) {
            return $trimmed;
        }

        return substr($trimmed, 0, $maxLength);
    }

    private function latestMediaCommentDescription(\App\Models\ShootFile $file): string
    {
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $comments = $metadata['comments'] ?? null;
        if (!is_array($comments)) {
            return '';
        }

        for ($index = count($comments) - 1; $index >= 0; $index--) {
            $entry = $comments[$index];
            if (!is_array($entry)) {
                continue;
            }

            $comment = trim((string) ($entry['comment'] ?? ''));
            if ($comment !== '') {
                return $this->trimText($comment, 50);
            }
        }

        return '';
    }
}


