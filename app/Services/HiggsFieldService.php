<?php

namespace App\Services;

use App\Models\AiVideoPreset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class HiggsFieldService
{
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;
    private int $timeout;
    private int $retryAttempts;
    private string $videoModel;
    private string $imageModel;

    public function __construct()
    {
        try {
            $settings = $this->loadSettings('integrations.higgsfield');

            $this->apiKey = $settings['apiKey'] ?? config('services.higgsfield.api_key') ?? env('HIGGSFIELD_API_KEY') ?? '';
            $this->apiSecret = $settings['apiSecret'] ?? config('services.higgsfield.api_secret') ?? env('HIGGSFIELD_API_SECRET') ?? '';
            $this->baseUrl = rtrim($settings['baseUrl'] ?? config('services.higgsfield.base_url', 'https://platform.higgsfield.ai'), '/');
            $this->timeout = $settings['timeout'] ?? config('services.higgsfield.timeout', 120) ?? 120;
            $this->retryAttempts = $settings['retryAttempts'] ?? config('services.higgsfield.retry_attempts', 3) ?? 3;
            $this->videoModel = $settings['videoModel'] ?? config('services.higgsfield.video_model', 'kling-video/v2.1/pro/image-to-video');
            $this->imageModel = $settings['imageModel'] ?? config('services.higgsfield.image_model', 'higgsfield-ai/soul/reference');
        } catch (\Exception $e) {
            Log::warning('HiggsFieldService constructor error, using defaults', [
                'error' => $e->getMessage(),
            ]);
            $this->apiKey = config('services.higgsfield.api_key') ?? env('HIGGSFIELD_API_KEY') ?? '';
            $this->apiSecret = config('services.higgsfield.api_secret') ?? env('HIGGSFIELD_API_SECRET') ?? '';
            $this->baseUrl = 'https://platform.higgsfield.ai';
            $this->timeout = 120;
            $this->retryAttempts = 3;
            $this->videoModel = 'kling-video/v2.1/pro/image-to-video';
            $this->imageModel = 'higgsfield-ai/soul/reference';
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
            Log::warning('Could not load HiggsField settings from database', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }

    /**
     * Get the authorization header value
     */
    private function getAuthHeader(): string
    {
        return 'Key ' . $this->apiKey . ':' . $this->apiSecret;
    }

    /**
     * Check if the service is configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->apiSecret);
    }

    /**
     * Parse a base64 data URI and return the raw base64 string.
     * Returns null if the input is not a data URI.
     */
    private function extractBase64(string $input): ?string
    {
        if (preg_match('/^data:[^;]+;base64,(.+)$/s', $input, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Build the image fields for the API payload.
     * Higgsfield API requires 'image_url' — accepts both HTTP URLs and data URIs.
     */
    private function buildImagePayload(string $imageInput, string $fieldPrefix = 'image'): array
    {
        // Always send as image_url — Higgsfield requires this field name
        // It accepts both regular URLs and base64 data URIs
        return [$fieldPrefix . '_url' => $imageInput];
    }

    public function generateVerticalImages(string $imageUrl, int $variantCount = 3): array
    {
        $requestIds = [];

        if (!$this->isConfigured()) {
            Log::error('HiggsField: API credentials not configured');
            return [];
        }

        for ($i = 0; $i < $variantCount; $i++) {
            try {
                // Use higgsfield-ai/popcorn/auto model for vertical extension
                $url = $this->baseUrl . '/higgsfield-ai/popcorn/auto';

                $payload = [
                    'image_urls' => [$imageUrl],
                    'prompt' => 'Extend image vertically',
                    'num_images' => 1,
                    'aspect_ratio' => '9:16',
                    'resolution' => '1600p',
                ];

                Log::info('HiggsField: Submitting vertical conversion', [
                    'image_url' => substr($imageUrl, 0, 100) . '...',
                    'variant_index' => $i,
                    'payload_keys' => array_keys($payload),
                ]);

                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => $this->getAuthHeader(),
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ])
                    ->post($url, $payload);

                if (!$response->successful()) {
                    Log::error('HiggsField: Vertical conversion submission failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'variant_index' => $i,
                    ]);
                    continue;
                }

                $data = $response->json();
                $requestId = $data['request_id'] ?? null;

                if ($requestId) {
                    $requestIds[] = $requestId;
                    Log::info('HiggsField: Vertical conversion submitted', [
                        'request_id' => $requestId,
                        'variant_index' => $i,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('HiggsField: Exception submitting vertical conversion', [
                    'error' => $e->getMessage(),
                    'variant_index' => $i,
                ]);
            }
        }

        return $requestIds;
    }

    /**
     * Submit a video generation request
     *
     * @param string $startFrameUrl Start frame image URL
     * @param string|null $endFrameUrl End frame image URL (optional)
     * @param string $prompt The generation prompt
     * @param int $duration Video duration in seconds
     * @return array|null Response with request_id or null on failure
     */
    public function generateVideo(string $startFrameUrl, ?string $endFrameUrl, string $prompt, int $duration = 5, string $aspectRatio = '16:9'): ?array
    {
        try {
            if (!$this->isConfigured()) {
                Log::error('HiggsField: API credentials not configured');
                return null;
            }

            $url = $this->baseUrl . '/' . $this->videoModel;

            $payload = array_merge(
                $this->buildImagePayload($startFrameUrl),
                [
                    'prompt' => $prompt,
                    'duration' => $duration,
                    'aspect_ratio' => $aspectRatio,
                ]
            );

            // Add end frame if provided (for models that support it)
            if ($endFrameUrl) {
                $endFields = $this->buildImagePayload($endFrameUrl, 'end_image');
                $payload = array_merge($payload, $endFields);
            }

            $isBase64Start = $this->extractBase64($startFrameUrl) !== null;
            Log::info('HiggsField: Submitting video generation', [
                'start_frame_type' => $isBase64Start ? 'base64' : 'url',
                'has_end_frame' => !empty($endFrameUrl),
                'prompt_length' => strlen($prompt),
                'payload_keys' => array_keys($payload),
            ]);

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => $this->getAuthHeader(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('HiggsField: Video generation submission failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $requestId = $data['request_id'] ?? null;

            Log::info('HiggsField: Video generation submitted', [
                'request_id' => $requestId,
            ]);

            return [
                'request_id' => $requestId,
                'status' => $data['status'] ?? 'queued',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('HiggsField: Exception submitting video generation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Check the status of a Higgsfield request
     *
     * @param string $requestId Higgsfield request ID
     * @return array|null Status data or null on failure
     */
    public function getRequestStatus(string $requestId): ?array
    {
        try {
            if (!$this->isConfigured()) {
                Log::error('HiggsField: API credentials not configured');
                return null;
            }

            $url = $this->baseUrl . '/requests/' . $requestId . '/status';

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => $this->getAuthHeader(),
                    'Accept' => 'application/json',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::warning('HiggsField: Failed to get request status', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            return [
                'status' => $data['status'] ?? 'unknown',
                'request_id' => $data['request_id'] ?? $requestId,
                'video_url' => $data['video']['url'] ?? null,
                'image_urls' => array_map(fn($img) => $img['url'] ?? null, $data['images'] ?? []),
                'error' => $data['error'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('HiggsField: Exception getting request status', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Cancel a Higgsfield request (only works while queued)
     *
     * @param string $requestId Higgsfield request ID
     * @return bool Success status
     */
    public function cancelRequest(string $requestId): bool
    {
        try {
            if (!$this->isConfigured()) {
                Log::error('HiggsField: API credentials not configured');
                return false;
            }

            $url = $this->baseUrl . '/requests/' . $requestId . '/cancel';

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => $this->getAuthHeader(),
                    'Accept' => 'application/json',
                ])
                ->post($url);

            if ($response->status() === 202) {
                Log::info('HiggsField: Request cancelled successfully', [
                    'request_id' => $requestId,
                ]);
                return true;
            }

            Log::warning('HiggsField: Failed to cancel request', [
                'request_id' => $requestId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('HiggsField: Exception cancelling request', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get video presets from database with config fallback
     *
     * @param bool $includeInactive Include inactive presets (for admin)
     * @return array List of presets
     */
    public function getPresets(bool $includeInactive = false): array
    {
        try {
            $query = AiVideoPreset::ordered();

            if (!$includeInactive) {
                $query->active();
            }

            $presets = $query->get();

            if ($presets->isEmpty()) {
                return $this->getDefaultPresets();
            }

            return $presets->toArray();
        } catch (\Exception $e) {
            Log::warning('HiggsField: Could not load presets from database, using defaults', [
                'error' => $e->getMessage(),
            ]);
            return $this->getDefaultPresets();
        }
    }

    /**
     * Get default presets (fallback if database is empty)
     */
    public function getDefaultPresets(): array
    {
        return config('video_presets', [
            [
                'slug' => 'weather',
                'name' => 'Weather Effects',
                'description' => 'Add cinematic weather effects to property exteriors',
                'icon' => 'Cloud',
                'category' => 'Lighting',
                'prompt_template' => 'Add cinematic weather effects — dramatic clouds, rain, or snow — to this real estate exterior, maintaining property visibility',
                'max_frames' => 1,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'slug' => 'twilight',
                'name' => 'Twilight',
                'description' => 'Transform property exteriors into golden hour twilight scenes',
                'icon' => 'Sunset',
                'category' => 'Lighting',
                'prompt_template' => 'Transform this property exterior into a stunning golden hour twilight scene with warm ambient lighting, glowing windows, and a dramatic sunset sky',
                'max_frames' => 1,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'day_to_night',
                'name' => 'Day to Night',
                'description' => 'Smooth cinematic transition from daytime to nighttime',
                'icon' => 'Moon',
                'category' => 'Lighting',
                'prompt_template' => 'Create a smooth cinematic transition from bright daytime to elegant nighttime with interior lights turning on and sky darkening',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'virtual_staging',
                'name' => 'Virtual Staging',
                'description' => 'Add realistic furniture and decor to empty rooms',
                'icon' => 'Sofa',
                'category' => 'Staging',
                'prompt_template' => 'Add realistic, elegant modern furniture and decor to this empty room, creating an inviting lived-in atmosphere',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'slug' => 'drone_flyover',
                'name' => 'Drone Flyover',
                'description' => 'Smooth aerial drone flyover of the property',
                'icon' => 'Plane',
                'category' => 'Movement',
                'prompt_template' => 'Create a smooth aerial drone flyover video starting from a wide establishing shot and slowly descending toward the property',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'slug' => 'entrance_walkthrough',
                'name' => 'Entrance Walk',
                'description' => 'Cinematic walkthrough approaching the entrance',
                'icon' => 'DoorOpen',
                'category' => 'Movement',
                'prompt_template' => 'Create a cinematic first-person walkthrough approaching and entering through the front entrance of this property',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'slug' => 'lifestyle',
                'name' => 'Lifestyle',
                'description' => 'Add lifestyle elements to make properties feel alive',
                'icon' => 'Users',
                'category' => 'Staging',
                'prompt_template' => 'Add lifestyle elements — people relaxing, subtle movement, warm lighting — to make this property feel alive and inviting',
                'max_frames' => 1,
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'slug' => 'construction_progress',
                'name' => 'Construction',
                'description' => 'Show construction progress transformation',
                'icon' => 'HardHat',
                'category' => 'Specialty',
                'prompt_template' => 'Show a time-lapse style construction progress transformation between these two stages of the build',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 7,
            ],
        ]);
    }

    /**
     * Test connection to Higgsfield API
     */
    public function testConnection(): array
    {
        try {
            if (!$this->isConfigured()) {
                return [
                    'success' => false,
                    'message' => 'API credentials not configured',
                ];
            }

            // Make a lightweight request to check auth
            $url = $this->baseUrl . '/requests/test/status';
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => $this->getAuthHeader(),
                    'Accept' => 'application/json',
                ])
                ->get($url);

            // A 404 means auth worked but request doesn't exist — that's fine
            if ($response->status() === 404 || $response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Connection successful',
                ];
            }

            return [
                'success' => false,
                'message' => 'Connection failed with status ' . $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }
}
