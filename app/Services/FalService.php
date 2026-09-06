<?php

namespace App\Services;

use App\Exceptions\FalTerminalException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FalService
{
    private string $key;

    private string $model;

    private string $imageModel;

    public function __construct()
    {
        $this->key = (string) config('services.fal.key');
        $this->model = (string) config('services.fal.model', 'fal-ai/wan-pro/image-to-video');
        $this->imageModel = (string) config('services.fal.image_model', 'fal-ai/flux-kontext/dev');
    }

    public function testConnection(): array
    {
        if ($this->key === '' || $this->key === 'PASTE_YOUR_FAL_KEY_HERE') {
            return [
                'success' => false,
                'status' => 401,
                'provider' => 'fal',
                'message' => 'FAL_KEY is not configured',
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'provider' => 'fal',
            'message' => 'fal.ai configuration is present',
            'image_model' => $this->imageModel,
            'video_model' => $this->model,
            'test_mode' => (bool) config('services.fal.test_mode'),
        ];
    }

    public function getImageEditingTypes(): array
    {
        return [
            [
                'id' => 'enhance',
                'name' => 'Enhance',
                'description' => 'fal.ai natural photo enhancement',
                'params' => [
                    'prompt' => 'string',
                    'output_format' => ['jpeg', 'png'],
                ],
            ],
            [
                'id' => 'sky_replace',
                'name' => 'Sky Replacement',
                'description' => 'fal.ai exterior sky cleanup while preserving property realism',
                'params' => [
                    'prompt' => 'string',
                    'output_format' => ['jpeg', 'png'],
                ],
            ],
            [
                'id' => 'vertical_correction',
                'name' => 'Vertical Correction',
                'description' => 'fal.ai perspective and vertical-line correction',
                'params' => [
                    'prompt' => 'string',
                    'output_format' => ['jpeg', 'png'],
                ],
            ],
            [
                'id' => 'window_pull',
                'name' => 'Window Pull',
                'description' => 'fal.ai window exposure balancing for real estate interiors',
                'params' => [
                    'prompt' => 'string',
                    'output_format' => ['jpeg', 'png'],
                ],
            ],
        ];
    }

    public function uploadImage(string $binary, string $mime): string
    {
        $this->ensureConfigured();

        $init = $this->providerResponse(fn () => $this->request()->withHeaders([
            'Authorization' => 'Key '.$this->key,
            'Content-Type' => 'application/json',
        ])->post('https://rest.alpha.fal.ai/storage/upload/initiate', [
            'content_type' => $mime,
            'file_name' => 'listing-photo-'.uniqid('', true).$this->extensionForMime($mime),
        ]));

        if (! $init->successful()) {
            $this->throwProviderFailure($init, 'storage initiation');
        }

        $uploadUrl = $init->json('upload_url');
        $fileUrl = $init->json('file_url');
        if (! $uploadUrl || ! $fileUrl) {
            throw new RuntimeException('fal.ai storage did not return upload/file URLs.');
        }

        $put = $this->providerResponse(fn () => $this->request((int) config('services.fal.upload_timeout', 120))
            ->withBody($binary, $mime)
            ->put($uploadUrl));
        if (! $put->successful()) {
            $this->throwProviderFailure($put, 'storage upload');
        }

        return $fileUrl;
    }

    public function submit(string $imageUrl, string $prompt): string
    {
        $this->ensureConfigured();

        $response = $this->postQueue($this->model, [
            'image_url' => $imageUrl,
            'prompt' => $prompt,
        ]);

        if (! $response->successful()) {
            $this->throwProviderFailure($response, 'submission');
        }

        $requestId = $response->json('request_id');
        if (! $requestId) {
            throw new RuntimeException('fal.ai did not return a request_id.');
        }

        return (string) $requestId;
    }

    public function submitImageEdit(string $imageUrl, string $editingType, array $params = []): array
    {
        $payload = $this->buildImageEditPayload($imageUrl, $editingType, $params);
        $response = $this->postQueue($this->imageModel, $payload);

        if (! $response->successful()) {
            $this->throwProviderFailure($response, 'image edit submission');
        }

        $data = $response->json() ?? [];
        $requestId = $data['request_id'] ?? null;
        if (! $requestId) {
            throw new RuntimeException('fal.ai image edit did not return a request_id.');
        }

        return [
            'job_id' => (string) $requestId,
            'request_id' => (string) $requestId,
            'status' => 'processing',
            'model' => $this->imageModel,
            'data' => $data,
            'payload' => $payload,
        ];
    }

    public function submitImageEditFromBuffer(
        string $contents,
        string $imageName,
        ?string $contentType,
        string $editingType,
        array $params = []
    ): array {
        $mime = $contentType ?: $this->mimeForName($imageName);
        $imageUrl = 'data:'.$mime.';base64,'.base64_encode($contents);

        return $this->submitImageEdit($imageUrl, $editingType, array_merge($params, [
            'image_name' => $imageName,
            'content_type' => $mime,
        ]));
    }

    public function status(string $requestId): string
    {
        $response = $this->getQueueStatus($this->model, $requestId);

        if (! $response->successful()) {
            $this->throwProviderFailure($response, 'status check');
        }

        return strtoupper((string) ($response->json('status') ?? 'IN_PROGRESS'));
    }

    public function result(string $requestId): string
    {
        $data = $this->fetchQueueResult($this->model, $requestId);

        $url = data_get($data, 'video.url')
            ?? data_get($data, 'video_url')
            ?? data_get($data, 'output.video.url');

        if (! $url) {
            throw new RuntimeException('fal.ai returned no generated video.');
        }

        return (string) $url;
    }

    public function imageEditStatus(string $requestId): ?array
    {
        $response = $this->getQueueStatus($this->imageModel, $requestId);

        if (! $response->successful()) {
            if ($this->isTerminalStatus($response->status())) {
                throw new FalTerminalException($response->status());
            }

            return null;
        }

        $data = $response->json() ?? [];
        $providerStatus = (string) ($data['status'] ?? 'IN_PROGRESS');

        return [
            'status' => $this->normalizeQueueStatus($providerStatus),
            'provider_status' => $providerStatus,
            'data' => $data,
        ];
    }

    public function imageEditResult(string $requestId): array
    {
        $data = $this->fetchQueueResult($this->imageModel, $requestId);
        $url = data_get($data, 'images.0.url')
            ?? data_get($data, 'image.url')
            ?? data_get($data, 'image_url')
            ?? data_get($data, 'output.images.0.url')
            ?? data_get($data, 'output.image.url')
            ?? data_get($data, 'output.image_url');

        if (! $url) {
            throw new RuntimeException('fal.ai returned no edited image.');
        }

        return [
            'status' => 'completed',
            'edited_image_url' => (string) $url,
            'image_url' => (string) $url,
            'data' => $data,
        ];
    }

    /** Explicit model queue support for Studio outpainting. Model names are server-owned. */
    public function submitModel(string $model, array $payload): string
    {
        $response = $this->postQueue($model, $payload);
        if (! $response->successful()) {
            $this->throwProviderFailure($response, 'submission');
        }
        if (! $response->json('request_id')) {
            throw new RuntimeException('fal.ai did not return a request ID for this operation.');
        }

        return (string) $response->json('request_id');
    }

    public function modelStatus(string $model, string $requestId): string
    {
        $response = $this->getQueueStatus($model, $requestId);
        if (! $response->successful()) {
            $this->throwProviderFailure($response, 'status check');
        }

        return strtoupper((string) $response->json('status', 'IN_PROGRESS'));
    }

    public function modelImageResult(string $model, string $requestId): string
    {
        $data = $this->fetchQueueResult($model, $requestId);
        $url = data_get($data, 'images.0.url') ?? data_get($data, 'image.url') ?? data_get($data, 'image_url');
        if (! $url) {
            throw new RuntimeException('fal.ai returned no prepared image.');
        }

        return (string) $url;
    }

    public function modelVideoResult(string $model, string $requestId): string
    {
        $data = $this->fetchQueueResult($model, $requestId);
        $url = data_get($data, 'video.url') ?? data_get($data, 'video_url');
        if (! $url) {
            throw new RuntimeException('fal.ai returned no generated video.');
        }

        return (string) $url;
    }

    public function submitWalkthroughClip(string $imageUrl, ?string $endImageUrl, string $prompt): string
    {
        $model = (string) config('services.fal.walkthrough_model');
        if ($model === '') {
            throw new RuntimeException('The walkthrough start/end-frame model is not configured.');
        }
        $payload = ['image_url' => $imageUrl, 'prompt' => $prompt, 'duration' => '5', 'negative_prompt' => 'cuts, jump cuts, distortion, morphing architecture, blur, text'];
        if ($endImageUrl !== null) {
            $payload['tail_image_url'] = $endImageUrl;
        }

        return $this->submitModel($model, $payload);
    }

    private function postQueue(string $model, array $payload)
    {
        $this->ensureConfigured();

        return $this->providerResponse(fn () => $this->request()->withHeaders([
            'Authorization' => 'Key '.$this->key,
            'Content-Type' => 'application/json',
        ])->post('https://queue.fal.run/'.ltrim($model, '/'), $payload));
    }

    private function getQueueStatus(string $model, string $requestId)
    {
        $this->ensureConfigured();

        return $this->providerResponse(fn () => $this->request(20)
            ->retry(3, 500, throw: false)
            ->withHeaders([
                'Authorization' => 'Key '.$this->key,
            ])
            ->get('https://queue.fal.run/'.$this->queueBasePath($model).'/requests/'.$requestId.'/status'));
    }

    private function fetchQueueResult(string $model, string $requestId): array
    {
        $this->ensureConfigured();

        $baseUrl = 'https://queue.fal.run/'.$this->queueBasePath($model).'/requests/'.$requestId;
        $headers = ['Authorization' => 'Key '.$this->key];

        $response = $this->providerResponse(fn () => $this->request()->withHeaders($headers)->get($baseUrl.'/response'));
        // Some queue models expose the result at the base request URL. Do not turn
        // an in-flight response or transient failure into a second endpoint error.
        if (in_array($response->status(), [404, 405], true)) {
            $response = $this->providerResponse(fn () => $this->request()->withHeaders($headers)->get($baseUrl));
        }

        if (! $response->successful()) {
            $this->throwProviderFailure($response, 'result fetch');
        }

        return $response->json() ?? [];
    }

    private function isTerminalStatus(int $status): bool
    {
        return $status >= 400 && $status < 500 && ! in_array($status, [408, 409, 425, 429], true);
    }

    private function throwProviderFailure(Response $response, string $operation): never
    {
        if ($this->isTerminalStatus($response->status())) {
            throw new FalTerminalException($response->status());
        }

        throw new RuntimeException('fal.ai '.$operation.' failed temporarily (HTTP '.$response->status().'). Retry to resume the existing request.');
    }

    /** Never attach a transport exception: its message/trace can contain request URLs or input. */
    private function providerResponse(callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException) {
            throw new RuntimeException('fal.ai is temporarily unreachable. Retry to resume the existing request.');
        } catch (RequestException $exception) {
            $this->throwProviderFailure($exception->response, 'request');
        }
    }

    private function request(?int $timeout = null): PendingRequest
    {
        return Http::connectTimeout((int) config('services.fal.connect_timeout', 10))
            ->timeout($timeout ?? (int) config('services.fal.http_timeout', 60));
    }

    private function queueBasePath(string $model): string
    {
        $segments = array_values(array_filter(explode('/', trim($model, '/')), static fn ($segment) => $segment !== ''));

        return implode('/', array_slice($segments, 0, 2));
    }

    private function buildImageEditPayload(string $imageUrl, string $editingType, array $params): array
    {
        $payload = [
            'image_url' => $imageUrl,
            'prompt' => (string) ($params['prompt'] ?? $this->promptForEditingType($editingType, $params)),
            'num_images' => max(1, min(4, (int) ($params['num_images'] ?? 1))),
            'output_format' => $params['output_format'] ?? 'jpeg',
        ];

        foreach (['seed', 'guidance_scale', 'num_inference_steps', 'safety_tolerance', 'sync_mode'] as $key) {
            if (array_key_exists($key, $params) && $params[$key] !== null && $params[$key] !== '') {
                $payload[$key] = $params[$key];
            }
        }

        return $payload;
    }

    private function ensureConfigured(): void
    {
        $missing = $this->key === '' || $this->key === 'PASTE_YOUR_FAL_KEY_HERE';
        if ($missing && ! config('services.fal.test_mode')) {
            throw new RuntimeException(
                'FAL_KEY is not set. Add it to your .env file or enable FAL_TEST_MODE=true.'
            );
        }
    }

    private function promptForEditingType(string $editingType, array $params): string
    {
        $base = 'Edit this real estate photo naturally. Preserve the property layout, architectural details, materials, colors, camera angle, and photorealistic style.';

        return match ($editingType) {
            'sky_replace' => $base.' Replace dull or overcast skies with a clean realistic blue sky and keep lighting believable.',
            'vertical_correction' => $base.' Correct perspective and straighten vertical architectural lines without cropping important room details.',
            'window_pull' => $base.' Balance bright windows so exterior detail is visible while keeping the interior naturally exposed.',
            default => $base.' Deliver a high-quality HDR real estate photograph with professional magazine-grade retouching:'
                .' bright, evenly exposed interiors with recovered shadow and highlight detail, balanced window exposure,'
                .' neutral accurate white balance that removes colour casts from mixed lighting, crisp natural sharpness and clarity,'
                .' rich but true-to-life colour, clean straight lines, and a bright inviting finish.'
                .' Keep it photorealistic — no HDR halos, no over-saturation, no plastic or over-processed look,'
                .' and do not add, remove, or move any objects, furniture, or fixtures.',
        };
    }

    private function normalizeQueueStatus(string $status): string
    {
        $status = strtoupper($status);

        return match ($status) {
            'COMPLETED' => 'completed',
            'FAILED', 'ERROR', 'CANCELLED' => 'failed',
            default => 'processing',
        };
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/heic' => '.heic',
            'image/heif' => '.heif',
            default => '.jpg',
        };
    }

    private function mimeForName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            default => 'image/jpeg',
        };
    }
}
