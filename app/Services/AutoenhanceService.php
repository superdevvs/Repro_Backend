<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutoenhanceService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $retryAttempts;
    private bool $enabled;
    private bool $devMode;
    private ?string $apiVersion;

    public function __construct()
    {
        try {
            $settings = $this->loadSettings('integrations.autoenhance');
            $this->apiKey = $settings['apiKey'] ?? config('services.autoenhance.api_key') ?? env('AUTOENHANCE_API_KEY') ?? '';
            $this->baseUrl = rtrim($settings['baseUrl'] ?? config('services.autoenhance.base_url', 'https://api.autoenhance.ai') ?? 'https://api.autoenhance.ai', '/');
            $this->timeout = (int) ($settings['timeout'] ?? config('services.autoenhance.timeout', 120) ?? 120);
            $this->enabled = (bool) ($settings['enabled'] ?? env('AUTOENHANCE_ENABLED', true));
            $this->retryAttempts = (int) ($settings['retryAttempts'] ?? config('services.autoenhance.retry_attempts', 3) ?? 3);
            $this->devMode = (bool) ($settings['devMode'] ?? config('services.autoenhance.dev_mode', false) ?? false);
            $this->apiVersion = $settings['apiVersion'] ?? config('services.autoenhance.api_version', env('AUTOENHANCE_API_VERSION', '2025-05-05'));
        } catch (\Exception $e) {
            Log::warning('AutoenhanceService constructor error, using defaults', [
                'error' => $e->getMessage(),
            ]);
            $this->apiKey = config('services.autoenhance.api_key') ?? env('AUTOENHANCE_API_KEY') ?? '';
            $this->baseUrl = rtrim(config('services.autoenhance.base_url', 'https://api.autoenhance.ai'), '/');
            $this->timeout = (int) config('services.autoenhance.timeout', 120);
            $this->enabled = (bool) env('AUTOENHANCE_ENABLED', true);
            $this->retryAttempts = (int) config('services.autoenhance.retry_attempts', 3);
            $this->devMode = (bool) config('services.autoenhance.dev_mode', false);
            $this->apiVersion = config('services.autoenhance.api_version', env('AUTOENHANCE_API_VERSION', '2025-05-05'));
        }
    }

    private function loadSettings(string $key): array
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('settings')) {
                return [];
            }
            $setting = DB::table('settings')->where('key', $key)->first();
            if ($setting && isset($setting->type) && $setting->type === 'json') {
                return json_decode($setting->value, true) ?? [];
            }
        } catch (\Exception $e) {
            Log::warning('Could not load Autoenhance settings from database', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }

    public function submitEditingJob(string $imageUrl, string $editingType, array $params = []): ?array
    {
        try {
            if (!$this->apiKey) {
                Log::error('Autoenhance API key not configured');
                return null;
            }

            $imageName = $params['image_name'] ?? basename(parse_url($imageUrl, PHP_URL_PATH) ?: 'image.jpg');
            $contentType = $params['content_type'] ?? $params['mime_type'] ?? $this->guessContentType($imageName);
            $createPayload = $this->buildCreateImagePayload($imageName, $editingType, $params);

            Log::info('Autoenhance: Creating image job', [
                'editing_type' => $editingType,
                'image_url' => $imageUrl,
            ]);

            $createResponse = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->post($this->baseUrl . '/v3/images/', $createPayload);

            if (!$createResponse->successful()) {
                $failure = $this->failureFromResponse('create_image', $createResponse, [
                    'editing_type' => $editingType,
                    'request_payload' => $createPayload,
                ]);
                Log::error('Autoenhance: Image creation failed', [
                    'status' => $createResponse->status(),
                    'body' => $createResponse->body(),
                    'editing_type' => $editingType,
                    'request_payload' => $createPayload,
                    'error' => $failure['error'],
                ]);
                return $failure;
            }

            $data = $createResponse->json() ?? [];
            $imageId = $data['image_id'] ?? $data['id'] ?? null;
            $uploadUrl = $data['upload_url'] ?? $data['s3PutObjectUrl'] ?? null;
            $usesLegacyUpload = !isset($data['upload_url']) && isset($data['s3PutObjectUrl']);

            if ($uploadUrl) {
                $uploaded = $this->uploadSourceImage($imageUrl, $uploadUrl, $usesLegacyUpload ? $contentType : 'application/octet-stream', !$usesLegacyUpload);
                if (!($uploaded['success'] ?? false)) {
                    return $uploaded;
                }
            }

            Log::info('Autoenhance: Image job submitted', [
                'image_id' => $imageId,
                'order_id' => $data['order_id'] ?? null,
            ]);

            return [
                'job_id' => $imageId,
                'image_id' => $imageId,
                'order_id' => $data['order_id'] ?? null,
                'status' => $data['status'] ?? 'processing',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Autoenhance: Exception submitting job', [
                'error' => $e->getMessage(),
                'editing_type' => $editingType,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Submit an editing job using an already-loaded binary buffer instead of a remote URL.
     *
     * This is used for ad-hoc/quick uploads (e.g. images attached directly in chat) where the
     * source image is local-only and not reachable by Autoenhance over the public internet.
     *
     * @param  string  $contents Raw binary contents of the image.
     * @param  string  $imageName Filename (used for content type guess + provider naming).
     * @param  string|null  $contentType Optional explicit MIME type.
     * @param  string  $editingType Editing pipeline (enhance, sky_replace, etc).
     * @param  array<string, mixed>  $params Extra provider parameters.
     */
    public function submitEditingJobFromBuffer(
        string $contents,
        string $imageName,
        ?string $contentType,
        string $editingType,
        array $params = []
    ): ?array {
        try {
            if (!$this->apiKey) {
                Log::error('Autoenhance API key not configured');
                return null;
            }

            $resolvedContentType = $contentType ?: $this->guessContentType($imageName);
            $createPayload = $this->buildCreateImagePayload($imageName, $editingType, $params);

            Log::info('Autoenhance: Creating image job (buffer)', [
                'editing_type' => $editingType,
                'image_name' => $imageName,
                'bytes' => strlen($contents),
            ]);

            $createResponse = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->post($this->baseUrl . '/v3/images/', $createPayload);

            if (!$createResponse->successful()) {
                $failure = $this->failureFromResponse('create_image', $createResponse, [
                    'editing_type' => $editingType,
                    'request_payload' => $createPayload,
                ]);
                Log::error('Autoenhance: Image creation failed (buffer)', [
                    'status' => $createResponse->status(),
                    'body' => $createResponse->body(),
                    'editing_type' => $editingType,
                    'request_payload' => $createPayload,
                    'error' => $failure['error'],
                ]);
                return $failure;
            }

            $data = $createResponse->json() ?? [];
            $imageId = $data['image_id'] ?? $data['id'] ?? null;
            $uploadUrl = $data['upload_url'] ?? $data['s3PutObjectUrl'] ?? null;
            $usesLegacyUpload = !isset($data['upload_url']) && isset($data['s3PutObjectUrl']);

            if ($uploadUrl) {
                $uploadHeaders = ['Content-Type' => $usesLegacyUpload ? $resolvedContentType : 'application/octet-stream'];
                if (!$usesLegacyUpload) {
                    $uploadHeaders['x-api-key'] = $this->apiKey;
                }

                $uploadResponse = Http::timeout($this->timeout)
                    ->withHeaders($uploadHeaders)
                    ->withBody($contents, $uploadHeaders['Content-Type'])
                    ->put($uploadUrl);

                if (!$uploadResponse->successful()) {
                    $failure = $this->failureFromResponse('upload_source_image', $uploadResponse);
                    Log::error('Autoenhance: Buffer upload to signed URL failed', [
                        'status' => $uploadResponse->status(),
                        'body' => $uploadResponse->body(),
                        'error' => $failure['error'],
                    ]);
                    return $failure;
                }
            }

            Log::info('Autoenhance: Image job submitted (buffer)', [
                'image_id' => $imageId,
                'order_id' => $data['order_id'] ?? null,
            ]);

            return [
                'job_id' => $imageId,
                'image_id' => $imageId,
                'order_id' => $data['order_id'] ?? null,
                'status' => $data['status'] ?? 'processing',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Autoenhance: Exception submitting buffer job', [
                'error' => $e->getMessage(),
                'editing_type' => $editingType,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    public function getJobStatus(string $autoenhanceImageId): ?array
    {
        try {
            if (!$this->apiKey) {
                Log::error('Autoenhance API key not configured');
                return null;
            }

            $response = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->get($this->baseUrl . '/v3/images/' . $autoenhanceImageId);

            if (!$response->successful()) {
                Log::warning('Autoenhance: Failed to get image status', [
                    'image_id' => $autoenhanceImageId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json() ?? [];
            $status = $data['status'] ?? $data['state'] ?? 'processing';
            return array_merge($data, [
                'status' => $this->normalizeStatus($status),
                'provider_status' => $status,
                'enhanced_image_url' => $data['enhanced_image_url'] ?? $data['result_url'] ?? null,
                'error' => $data['error'] ?? $data['status_reason'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Autoenhance: Exception getting job status', [
                'image_id' => $autoenhanceImageId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function downloadEditedImage(string $autoenhanceImageId): ?string
    {
        try {
            if (!$this->apiKey) {
                Log::error('Autoenhance API key not configured');
                return null;
            }

            // The /enhanced endpoint returns the image binary (or a redirect to a
            // signed S3 URL). We must NOT send Accept: application/json — that's
            // the default in headers() and the API silently refuses to return the
            // binary. Force Accept: */* so we get raw bytes.
            $headers = [
                'x-api-key' => $this->apiKey,
                'Accept' => '*/*',
            ];
            if ($this->apiVersion) {
                $headers['x-api-version'] = $this->apiVersion;
            }
            if ($this->devMode) {
                $headers['x-dev-mode'] = 'true';
            }

            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->get($this->baseUrl . '/v3/images/' . $autoenhanceImageId . '/enhanced', [
                    'format' => 'jpeg',
                    'quality' => 90,
                ]);

            if (!$response->successful()) {
                Log::warning('Autoenhance: Failed to download enhanced image', [
                    'image_id' => $autoenhanceImageId,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);
                return null;
            }

            $contentType = (string) $response->header('Content-Type', '');
            $body = $response->body();

            // JSON path — provider returned a URL pointing at the result.
            if (str_contains($contentType, 'application/json')) {
                $data = $response->json() ?? [];
                return $data['url']
                    ?? $data['download_url']
                    ?? $data['image_url']
                    ?? $data['enhanced_image_url']
                    ?? null;
            }

            // Binary path — return as data URI (caller will persist it).
            if ($body === '' || $body === null) {
                Log::warning('Autoenhance: enhanced image binary was empty', [
                    'image_id' => $autoenhanceImageId,
                    'content_type' => $contentType,
                ]);
                return null;
            }

            return 'data:' . ($contentType ?: 'image/jpeg') . ';base64,' . base64_encode($body);
        } catch (\Exception $e) {
            Log::error('Autoenhance: Exception downloading enhanced image', [
                'image_id' => $autoenhanceImageId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getEditingTypes(): array
    {
        return $this->getDefaultEditingTypes();
    }

    public function getDefaultEditingTypes(): array
    {
        return [
            [
                'id' => 'enhance',
                'name' => 'Enhance',
                'description' => 'Core Autoenhance property photo enhancement',
                'params' => [
                    'enhance_type' => ['neutral'],
                    'vertical_correction' => 'boolean',
                    'lens_correction' => 'boolean',
                    'window_pull_type' => ['NONE', 'ONLY_WINDOWS', 'WITH_SKIES'],
                ],
            ],
            [
                'id' => 'sky_replace',
                'name' => 'Sky Replacement',
                'description' => 'Replace grey skies and keep enhancement enabled',
                'params' => [
                    'cloud_type' => ['CLEAR', 'LOW_CLOUD', 'LOW_CLOUD_LOW_SAT', 'HIGH_CLOUD'],
                ],
            ],
            [
                'id' => 'hdr_merge',
                'name' => 'HDR Bracket Merge',
                'description' => 'Bracket merge workflow for exposure stacks',
                'params' => [
                    'hdr' => 'boolean',
                    'bracket_count' => 'integer',
                ],
            ],
            [
                'id' => 'vertical_correction',
                'name' => 'Vertical Correction',
                'description' => 'Correct wonky vertical lines',
                'params' => [],
            ],
            [
                'id' => 'window_pull',
                'name' => 'Window Pull',
                'description' => 'Apply Autoenhance window pull processing',
                'params' => [
                    'window_pull_type' => ['ONLY_WINDOWS', 'WITH_SKIES'],
                ],
            ],
        ];
    }

    public function cancelJob(string $autoenhanceImageId): bool
    {
        Log::info('Autoenhance: Cancel requested locally', [
            'image_id' => $autoenhanceImageId,
        ]);
        return true;
    }

    public function testConnection(): array
    {
        if (!$this->apiKey) {
            return [
                'success' => false,
                'status' => 401,
                'message' => 'API key not configured',
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'message' => 'Autoenhance configuration is present',
            'editing_types_count' => count($this->getDefaultEditingTypes()),
        ];
    }

    private function headers(bool $includeDevMode = false): array
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($this->apiVersion) {
            $headers['x-api-version'] = $this->apiVersion;
        }

        if ($includeDevMode) {
            $headers['x-dev-mode'] = 'true';
        }

        return $headers;
    }

    private function buildCreateImagePayload(string $imageName, string $editingType, array $params): array
    {
        $payload = [
            'image_name' => $this->sanitizeImageName($imageName),
            'enhance' => $params['enhance'] ?? true,
            'lens_correction' => $params['lens_correction'] ?? true,
            'vertical_correction' => $params['vertical_correction'] ?? true,
        ];

        if (array_key_exists('privacy', $params)) {
            $payload['privacy'] = (bool) $params['privacy'];
        }

        if (!empty($params['metadata']) && is_array($params['metadata'])) {
            // Force JSON object encoding even when associative array is empty.
            $payload['metadata'] = (object) $params['metadata'];
        }

        if (!empty($params['ai_version'])) {
            $payload['ai_version'] = $params['ai_version'];
        }

        $enhanceType = $params['enhance_type'] ?? $this->mapEnhanceType($editingType, $params);
        if ($enhanceType) {
            $payload['enhance_type'] = $enhanceType;
        }

        if (!empty($params['order_id'])) {
            $payload['order_id'] = $params['order_id'];
        }

        if ($editingType === 'sky_replace' || !empty($params['sky_replacement'])) {
            $payload['sky_replacement'] = true;
            $payload['cloud_type'] = $params['cloud_type'] ?? $params['sky_type'] ?? 'CLEAR';
        }

        if ($editingType === 'window_pull' || !empty($params['window_pull_type'])) {
            $payload['window_pull_type'] = $params['window_pull_type'] ?? 'ONLY_WINDOWS';
        }

        if ($editingType === 'hdr_merge' || !empty($params['hdr'])) {
            $payload['hdr'] = true;
        }

        foreach (['upscale', 'threesixty', 'tripod_hide', 'preset_id', 'restage', 'finetune_settings'] as $key) {
            if (array_key_exists($key, $params)) {
                $payload[$key] = $params[$key];
            }
        }

        return $payload;
    }

    private function uploadSourceImage(string $imageUrl, string $uploadUrl, string $contentType, bool $includeApiKey = true): array
    {
        $sourceResponse = Http::timeout($this->timeout)->get($imageUrl);
        if (!$sourceResponse->successful()) {
            $failure = $this->failureFromResponse('fetch_source_image', $sourceResponse, [
                'image_url' => $imageUrl,
            ]);
            Log::error('Autoenhance: Failed to fetch source image for upload', [
                'image_url' => $imageUrl,
                'status' => $sourceResponse->status(),
                'error' => $failure['error'],
            ]);
            return $failure;
        }

        $headers = ['Content-Type' => $contentType];
        if ($includeApiKey) {
            $headers['x-api-key'] = $this->apiKey;
        }

        $uploadResponse = Http::timeout($this->timeout)
            ->withHeaders($headers)
            ->withBody($sourceResponse->body(), $contentType)
            ->put($uploadUrl);

        if (!$uploadResponse->successful()) {
            $failure = $this->failureFromResponse('upload_source_image', $uploadResponse);
            Log::error('Autoenhance: Upload to signed URL failed', [
                'status' => $uploadResponse->status(),
                'body' => $uploadResponse->body(),
                'error' => $failure['error'],
            ]);
            return $failure;
        }

        return ['success' => true];
    }

    private function sanitizeImageName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'image-' . uniqid() . '.jpg';
        }
        // Replace whitespace and disallowed chars with '-' to avoid provider-side validation errors.
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $clean = trim($clean, '-_.');
        if ($clean === '') {
            $clean = 'image-' . uniqid() . '.jpg';
        }
        if (mb_strlen($clean) > 180) {
            $clean = mb_substr($clean, -180);
        }
        return $clean;
    }

    private function failureFromResponse(string $stage, $response, array $context = []): array
    {
        $body = $response->body();
        $data = null;
        try {
            $data = $response->json();
        } catch (\Throwable $e) {
            $data = null;
        }

        $message = null;
        if (is_array($data)) {
            $message = $data['message'] ?? $data['error'] ?? $data['detail'] ?? $data['status_reason'] ?? null;
            if (is_array($message) || is_object($message)) {
                $message = json_encode($message);
            }
        }

        if (!$message && $body) {
            $message = trim((string) $body);
        }

        return array_merge($context, [
            'success' => false,
            'stage' => $stage,
            'status' => $response->status(),
            'error' => $message ?: 'Autoenhance request failed',
            'body' => $body,
            'data' => $data,
        ]);
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower($status);
        return match (true) {
            in_array($status, ['completed', 'done', 'finished', 'success', 'ready', 'downloaded', 'processed'], true) => 'completed',
            in_array($status, ['failed', 'error', 'cancelled', 'rejected'], true) => 'failed',
            default => 'processing',
        };
    }

    private function mapEnhanceType(string $editingType, array $params): ?string
    {
        if (!empty($params['enhance_type'])) {
            return $params['enhance_type'];
        }

        return null;
    }

    private function guessContentType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'image/jpeg',
        };
    }
}
