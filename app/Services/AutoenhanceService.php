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
            $createPayload = $this->buildCreateImagePayload($imageName, $editingType, $params, $contentType);

            Log::info('Autoenhance: Creating image job', [
                'editing_type' => $editingType,
                'image_url' => $imageUrl,
            ]);

            $createResponse = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->post($this->baseUrl . '/v3/images/', $createPayload);

            if (!$createResponse->successful()) {
                Log::error('Autoenhance: Image creation failed', [
                    'status' => $createResponse->status(),
                    'body' => $createResponse->body(),
                    'editing_type' => $editingType,
                ]);
                return null;
            }

            $data = $createResponse->json() ?? [];
            $imageId = $data['image_id'] ?? $data['id'] ?? null;
            $uploadUrl = $data['upload_url'] ?? $data['s3PutObjectUrl'] ?? null;

            if ($uploadUrl) {
                $uploaded = $this->uploadSourceImage($imageUrl, $uploadUrl, $contentType);
                if (!$uploaded) {
                    return null;
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

            $response = Http::timeout($this->timeout)
                ->withHeaders($this->headers($this->devMode))
                ->get($this->baseUrl . '/v3/images/' . $autoenhanceImageId . '/enhanced', [
                    'format' => 'jpeg',
                    'quality' => 90,
                ]);

            if (!$response->successful()) {
                Log::warning('Autoenhance: Failed to download enhanced image', [
                    'image_id' => $autoenhanceImageId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $contentType = $response->header('Content-Type', '');
            if (str_contains($contentType, 'application/json')) {
                $data = $response->json() ?? [];
                return $data['url'] ?? $data['download_url'] ?? $data['image_url'] ?? $data['enhanced_image_url'] ?? null;
            }

            return 'data:' . ($contentType ?: 'image/jpeg') . ';base64,' . base64_encode($response->body());
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
                'description' => 'Autoenhance property photo enhancement',
                'params' => [
                    'enhance_type' => ['warm', 'neutral', 'modern'],
                    'vertical_correction' => 'boolean',
                    'lens_correction' => 'boolean',
                    'window_pull_type' => ['NONE', 'ONLY_WINDOWS', 'WINDOWS_WITH_SKIES'],
                ],
            ],
            [
                'id' => 'sky_replace',
                'name' => 'Sky Replacement',
                'description' => 'Enhance the image and replace sky using Autoenhance',
                'params' => [
                    'cloud_type' => ['CLEAR', 'LOW_CLOUD', 'HIGH_CLOUD'],
                ],
            ],
            [
                'id' => 'hdr_merge',
                'name' => 'HDR Bracket Merge',
                'description' => 'Group bracketed exposures and process HDR output',
                'params' => [
                    'hdr' => 'boolean',
                    'bracket_count' => 'integer',
                ],
            ],
            [
                'id' => 'vertical_correction',
                'name' => 'Vertical Correction',
                'description' => 'Correct vertical distortion and enhance property imagery',
                'params' => [],
            ],
            [
                'id' => 'window_pull',
                'name' => 'Window Pull',
                'description' => 'Apply Autoenhance window pull processing',
                'params' => [
                    'window_pull_type' => ['ONLY_WINDOWS', 'WINDOWS_WITH_SKIES'],
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

        if ($includeDevMode) {
            $headers['x-dev-mode'] = 'true';
        }

        return $headers;
    }

    private function buildCreateImagePayload(string $imageName, string $editingType, array $params, string $contentType): array
    {
        $payload = [
            'ai_version' => $params['ai_version'] ?? '5.x',
            'image_name' => $imageName,
            'content_type' => $contentType,
            'enhance' => $params['enhance'] ?? true,
            'enhance_type' => $params['enhance_type'] ?? $this->mapEnhanceType($editingType, $params),
            'lens_correction' => $params['lens_correction'] ?? true,
            'vertical_correction' => $params['vertical_correction'] ?? true,
            'privacy' => $params['privacy'] ?? true,
            'metadata' => $params['metadata'] ?? [],
        ];

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

    private function uploadSourceImage(string $imageUrl, string $uploadUrl, string $contentType): bool
    {
        $sourceResponse = Http::timeout($this->timeout)->get($imageUrl);
        if (!$sourceResponse->successful()) {
            Log::error('Autoenhance: Failed to fetch source image for upload', [
                'image_url' => $imageUrl,
                'status' => $sourceResponse->status(),
            ]);
            return false;
        }

        $uploadResponse = Http::timeout($this->timeout)
            ->withHeaders(['Content-Type' => $contentType])
            ->withBody($sourceResponse->body(), $contentType)
            ->put($uploadUrl);

        if (!$uploadResponse->successful()) {
            Log::error('Autoenhance: Upload to signed URL failed', [
                'status' => $uploadResponse->status(),
                'body' => $uploadResponse->body(),
            ]);
            return false;
        }

        return true;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower($status);
        return match (true) {
            in_array($status, ['completed', 'done', 'finished', 'success', 'ready', 'downloaded'], true) => 'completed',
            in_array($status, ['failed', 'error', 'cancelled', 'rejected'], true) => 'failed',
            default => 'processing',
        };
    }

    private function mapEnhanceType(string $editingType, array $params): string
    {
        if (!empty($params['enhance_type'])) {
            return $params['enhance_type'];
        }

        return match ($editingType) {
            'color_correction', 'white_balance' => 'neutral',
            'exposure_fix' => 'warm',
            default => 'property',
        };
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
