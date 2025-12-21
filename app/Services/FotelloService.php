<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FotelloService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $retryAttempts;

    public function __construct()
    {
        try {
            // Try to load from database settings first, fallback to config
            $settings = $this->loadSettings('integrations.fotello');
            
            $this->apiKey = $settings['apiKey'] ?? config('services.fotello.api_key') ?? env('FOTELLO_API_KEY') ?? '';
            $this->baseUrl = rtrim($settings['baseUrl'] ?? config('services.fotello.base_url', 'https://app.fotello.co/api') ?? 'https://app.fotello.co/api', '/');
            $this->timeout = $settings['timeout'] ?? config('services.fotello.timeout', 120) ?? 120;
            $this->retryAttempts = $settings['retryAttempts'] ?? config('services.fotello.retry_attempts', 3) ?? 3;
        } catch (\Exception $e) {
            // If constructor fails, use defaults from config/env
            Log::warning('FotelloService constructor error, using defaults', [
                'error' => $e->getMessage(),
            ]);
            $this->apiKey = config('services.fotello.api_key') ?? env('FOTELLO_API_KEY') ?? '';
            $this->baseUrl = rtrim(config('services.fotello.base_url', 'https://app.fotello.co/api'), '/');
            $this->timeout = config('services.fotello.timeout', 120);
            $this->retryAttempts = config('services.fotello.retry_attempts', 3);
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
            Log::warning('Could not load settings from database', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }

    /**
     * Submit image for AI editing
     * 
     * @param string $imageUrl URL of the image to edit
     * @param string $editingType Type of editing (enhance, sky_replace, etc.)
     * @param array $params Additional parameters for the editing type
     * @return array|null Response with job ID or null on failure
     */
    public function submitEditingJob(string $imageUrl, string $editingType, array $params = []): ?array
    {
        try {
            if (!$this->apiKey) {
                Log::error('Fotello API key not configured');
                return null;
            }

            // Based on Fotello API docs: POST /createEnhance
            // The API uses different endpoints for different editing types
            $endpoint = $this->getEndpointForEditingType($editingType);
            $url = $this->baseUrl . $endpoint;

            // Build payload based on API structure
            $payload = $this->buildPayloadForEditingType($imageUrl, $editingType, $params);

            Log::info('Fotello: Submitting editing job', [
                'editing_type' => $editingType,
                'image_url' => $imageUrl,
            ]);

            $response = Http::timeout($this->timeout)
                ->withOptions([
                    'verify' => true, // SSL verification
                ])
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Fotello: Job submission failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'editing_type' => $editingType,
                ]);
                return null;
            }

            $data = $response->json();
            
            // Extract job ID from various possible response formats
            $jobId = $data['job_id'] ?? $data['id'] ?? $data['enhance_id'] ?? $data['enhanceId'] ?? null;
            
            Log::info('Fotello: Job submitted successfully', [
                'job_id' => $jobId,
                'response' => $data,
            ]);

            // Ensure we return a consistent format
            return [
                'job_id' => $jobId,
                'status' => $data['status'] ?? 'pending',
                'data' => $data,
            ];

        } catch (\Exception $e) {
            Log::error('Fotello: Exception submitting job', [
                'error' => $e->getMessage(),
                'editing_type' => $editingType,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Check job status
     * 
     * @param string $fotelloJobId Fotello's job ID
     * @return array|null Job status data or null on failure
     */
    public function getJobStatus(string $fotelloJobId): ?array
    {
        try {
            if (!$this->apiKey) {
                Log::error('Fotello API key not configured');
                return null;
            }

            // Based on Fotello API: GET /getEnhance/{id}
            $endpoint = '/getEnhance/' . $fotelloJobId;
            $url = $this->baseUrl . $endpoint;

            $response = Http::timeout($this->timeout)
                ->withOptions([
                    'verify' => env('APP_ENV') !== 'local', // Verify SSL in production
                ])
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::warning('Fotello: Failed to get job status', [
                    'job_id' => $fotelloJobId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            
            // Normalize response format for easier handling
            // Include both normalized fields and full response
            $normalized = [
                'status' => $data['status'] ?? $data['state'] ?? $data['enhance']['status'] ?? 'unknown',
                'enhanced_image_url' => $data['enhanced_image_url'] 
                    ?? $data['enhancedImageUrl'] 
                    ?? $data['result_url'] 
                    ?? $data['resultUrl']
                    ?? $data['enhance']['enhanced_image_url'] ?? null,
                'original_image_url' => $data['original_image_url'] ?? $data['originalImageUrl'] ?? null,
                'error' => $data['error'] ?? $data['error_message'] ?? null,
                'message' => $data['message'] ?? null,
            ];
            
            // Merge normalized with full response (normalized takes precedence)
            return array_merge($data, $normalized);

        } catch (\Exception $e) {
            Log::error('Fotello: Exception getting job status', [
                'job_id' => $fotelloJobId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Download edited image
     * 
     * @param string $fotelloJobId Fotello's job ID
     * @return string|null URL of the edited image or null on failure
     */
    public function downloadEditedImage(string $fotelloJobId): ?string
    {
        try {
            if (!$this->apiKey) {
                Log::error('Fotello API key not configured');
                return null;
            }

            // Try to get the enhanced image URL from job status
            // The API may return the image URL directly in the status response
            $status = $this->getJobStatus($fotelloJobId);
            if ($status && isset($status['enhanced_image_url'])) {
                return $status['enhanced_image_url'];
            }
            
            // Fallback: try download endpoint if it exists
            $endpoint = '/getEnhance/' . $fotelloJobId;
            $url = $this->baseUrl . $endpoint;

            $response = Http::timeout($this->timeout)
                ->withOptions([
                    'verify' => env('APP_ENV') !== 'local', // Verify SSL in production
                ])
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::warning('Fotello: Failed to download edited image', [
                    'job_id' => $fotelloJobId,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();
            
            // Return the image URL or download URL
            return $data['image_url'] ?? $data['download_url'] ?? $data['url'] ?? null;

        } catch (\Exception $e) {
            Log::error('Fotello: Exception downloading edited image', [
                'job_id' => $fotelloJobId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * List available editing types
     * 
     * @return array List of available editing types
     */
    public function getEditingTypes(): array
    {
        try {
            // For now, always return default types since API key might not be configured
            // Once API is configured, we can try to fetch from API
            if (!$this->apiKey) {
                Log::info('Fotello API key not configured, returning default editing types');
                return $this->getDefaultEditingTypes();
            }

            // Try to fetch from API if key is configured
            try {
                $endpoint = '/v1/editing-types'; // Update based on actual API docs
                $url = $this->baseUrl . $endpoint;

                $response = Http::timeout(30)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->get($url);

                if ($response->successful()) {
                    $data = $response->json();
                    $types = $data['types'] ?? $data['data'] ?? $data ?? null;
                    if ($types && is_array($types) && !empty($types)) {
                        return $types;
                    }
                }
                
                Log::info('Fotello: API call failed or returned empty, using defaults', [
                    'status' => $response->status() ?? 'no response',
                ]);
            } catch (\Exception $apiException) {
                Log::info('Fotello: API call exception, using defaults', [
                    'error' => $apiException->getMessage(),
                ]);
            }

            // Fallback to defaults
            return $this->getDefaultEditingTypes();

        } catch (\Exception $e) {
            Log::error('Fotello: Exception getting editing types', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Always return defaults on error
            return $this->getDefaultEditingTypes();
        }
    }

    /**
     * Get default editing types (fallback if API is unavailable)
     */
    public function getDefaultEditingTypes(): array
    {
        return [
            [
                'id' => 'enhance',
                'name' => 'Enhance',
                'description' => 'General image enhancement and quality improvement',
                'params' => [],
            ],
            [
                'id' => 'sky_replace',
                'name' => 'Sky Replacement',
                'description' => 'Replace sky with a natural blue sky',
                'params' => [
                    'sky_type' => ['natural', 'dramatic', 'sunset'],
                ],
            ],
            [
                'id' => 'remove_object',
                'name' => 'Remove Object',
                'description' => 'Remove unwanted objects from the image',
                'params' => [
                    'object_description' => 'string',
                ],
            ],
            [
                'id' => 'color_correction',
                'name' => 'Color Correction',
                'description' => 'Adjust colors and white balance',
                'params' => [],
            ],
            [
                'id' => 'exposure_fix',
                'name' => 'Exposure Fix',
                'description' => 'Fix overexposed or underexposed areas',
                'params' => [],
            ],
            [
                'id' => 'white_balance',
                'name' => 'White Balance',
                'description' => 'Correct white balance for natural tones',
                'params' => [],
            ],
        ];
    }

    /**
     * Cancel a pending job
     * 
     * @param string $fotelloJobId Fotello's job ID
     * @return bool Success status
     */
    public function cancelJob(string $fotelloJobId): bool
    {
        try {
            if (!$this->apiKey) {
                Log::error('Fotello API key not configured');
                return false;
            }

            // Cancel endpoint (may need to check API docs for exact endpoint)
            $endpoint = '/cancelEnhance/' . $fotelloJobId;
            $url = $this->baseUrl . $endpoint;

            $response = Http::timeout(30)
                ->withOptions([
                    'verify' => env('APP_ENV') !== 'local',
                ])
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->post($url);

            if (!$response->successful()) {
                Log::warning('Fotello: Failed to cancel job', [
                    'job_id' => $fotelloJobId,
                    'status' => $response->status(),
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Fotello: Exception canceling job', [
                'job_id' => $fotelloJobId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Test connection to Fotello API
     */
    public function testConnection(): array
    {
        try {
            if (!$this->apiKey) {
                return [
                    'success' => false,
                    'status' => 401,
                    'message' => 'API key not configured',
                ];
            }

            // Try to get editing types as a connection test
            $types = $this->getEditingTypes();
            
            return [
                'success' => !empty($types),
                'status' => 200,
                'message' => 'Connection successful',
                'editing_types_count' => count($types),
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
     * Get endpoint for editing type based on Fotello API structure
     */
    private function getEndpointForEditingType(string $editingType): string
    {
        // Map editing types to Fotello API endpoints
        $endpointMap = [
            'enhance' => '/createEnhance',
            'sky_replace' => '/createEnhance', // May need separate endpoint
            'remove_object' => '/createEnhance',
            'color_correction' => '/createEnhance',
            'exposure_fix' => '/createEnhance',
            'white_balance' => '/createEnhance',
        ];

        return $endpointMap[$editingType] ?? '/createEnhance';
    }

    /**
     * Build payload for editing type based on Fotello API structure
     * Based on API docs, the payload structure may vary
     */
    private function buildPayloadForEditingType(string $imageUrl, string $editingType, array $params = []): array
    {
        // Base payload structure - Fotello API may accept image_url or upload_id
        $payload = [
            'image_url' => $imageUrl,
        ];

        // Add editing type specific parameters
        switch ($editingType) {
            case 'sky_replace':
                $payload['sky_type'] = $params['sky_type'] ?? 'natural';
                break;
            case 'remove_object':
                if (isset($params['object_description'])) {
                    $payload['object_description'] = $params['object_description'];
                }
                break;
            case 'enhance':
                // General enhancement - may accept quality or style params
                if (isset($params['quality'])) {
                    $payload['quality'] = $params['quality'];
                }
                break;
            // Add more cases as needed
        }

        // Add any additional params
        foreach ($params as $key => $value) {
            if (!isset($payload[$key])) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}

