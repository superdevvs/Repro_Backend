<?php

namespace App\Services\Studio\Providers;

use App\Exceptions\OpenAiImageException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * One synchronous request, with no automatic retries or fallback orchestration.
 * The caller must persist an attempt before calling and retain ambiguous attempts.
 * Sources: developers.openai.com/api/docs/guides/image-generation (2026-09-08).
 */
class OpenAiImageProvider
{
    public const MODELS = ['gpt-image-2', 'gpt-image-2-2026-04-21'];

    private const ENDPOINT = 'https://api.openai.com/v1/images/edits';

    private const MAX_INPUT_BYTES = 50_000_000;

    private const MAX_INPUT_PIXELS = 40_000_000;

    private ImageManager $images;

    public function __construct()
    {
        $this->images = ImageManager::gd();
    }

    public function configured(array $options = []): bool
    {
        return filled($options['api_key'] ?? config('services.openai.api_key'));
    }

    public function model(array $options = []): string
    {
        $model = (string) ($options['model'] ?? config('services.openai.image_model', 'gpt-image-2'));
        if (! in_array($model, self::MODELS, true)) {
            throw new OpenAiImageException(reason: 'unsupported_model');
        }

        return $model;
    }

    /** Return PNG bytes; retain the source aspect ratio, including very narrow revision crops. */
    public function edit(string $sourceBytes, string $prompt, array $options = []): string
    {
        $source = $this->read($sourceBytes)->scaleDown(width: 2048, height: 2048);
        $width = $source->width();
        $height = $source->height();
        // GPT Image 2 accepts at most 3:1. Pad a narrow selection, then remove only that padding.
        $ratio = max(1 / 3, min(3, $width / $height));
        [$canvasWidth, $canvasHeight] = $this->requestSize($ratio);
        $placed = clone $source;
        $placed->scale(width: $canvasWidth, height: $canvasHeight);
        $x = (int) floor(($canvasWidth - $placed->width()) / 2);
        $y = (int) floor(($canvasHeight - $placed->height()) / 2);
        $canvas = $this->images->create($canvasWidth, $canvasHeight)->fill('101828')->place($placed, 'top-left', $x, $y);
        $result = $this->request((string) $canvas->toPng(), $prompt, $canvasWidth, $canvasHeight, options: $options);
        $image = $this->readResult($result)->resize($canvasWidth, $canvasHeight);

        return (string) $image->crop($placed->width(), $placed->height(), $x, $y)->resize($width, $height)->toPng();
    }

    /**
     * Extend to a Studio ratio with a centered, uncropped source, returning PNG bytes.
     * The model's mask is advisory; re-compositing protects the original source rectangle.
     */
    public function outpaint(string $sourceBytes, string $ratio, string $prompt = '', array $options = []): string
    {
        if (! in_array($ratio, ['1:1', '9:16', '16:9', '4:3', '3:4', '4:5', '5:4', '3:2', '2:3'], true)) {
            throw new OpenAiImageException(reason: 'invalid_ratio');
        }
        [$rw, $rh] = array_map('intval', explode(':', $ratio));
        $source = $this->read($sourceBytes);
        $finalWidth = $rw >= $rh ? 1600 : (int) round(1600 * $rw / $rh);
        $finalHeight = $rw >= $rh ? (int) round(1600 * $rh / $rw) : 1600;
        // Exact supported ratios and 16px multiples; stay below experimental >2K pixel counts.
        $unit = max(1, (int) ceil(1600 / (16 * max($rw, $rh))));
        $canvasWidth = 16 * $rw * $unit;
        $canvasHeight = 16 * $rh * $unit;
        $placed = clone $source;
        $placed->scale(width: $canvasWidth, height: $canvasHeight);
        $x = (int) floor(($canvasWidth - $placed->width()) / 2);
        $y = (int) floor(($canvasHeight - $placed->height()) / 2);
        if ($canvasWidth === $placed->width() && $canvasHeight === $placed->height()) {
            return (string) $source->resize($finalWidth, $finalHeight)->toPng();
        }

        $canvas = $this->images->create($canvasWidth, $canvasHeight)->place($placed, 'top-left', $x, $y);
        $protected = $this->images->create($placed->width(), $placed->height())->fill('ffffff');
        $mask = $this->images->create($canvasWidth, $canvasHeight)->place($protected, 'top-left', $x, $y);
        $instructions = 'Fill only the transparent padding around this property photograph. Continue the scene naturally with matching perspective, lighting, materials and photorealistic detail. Preserve the complete existing photograph, architecture, window and door geometry, furniture and landscaping; do not crop, zoom, stretch, move or redraw them. Return the entire expanded canvas with no transparent or blank padding.';
        if (trim($prompt) !== '') {
            $instructions .= ' Additional extension guidance: '.trim($prompt);
        }
        $result = $this->request((string) $canvas->toPng(), $instructions, $canvasWidth, $canvasHeight, (string) $mask->toPng(), $options);
        $image = $this->readResult($result)->resize($canvasWidth, $canvasHeight)->resize($finalWidth, $finalHeight);
        $original = clone $source;
        $original->scale(width: $finalWidth, height: $finalHeight);
        $finalX = (int) floor(($finalWidth - $original->width()) / 2);
        $finalY = (int) floor(($finalHeight - $original->height()) / 2);

        return (string) $image->place($original, 'top-left', $finalX, $finalY)->toPng();
    }

    /** @return array{int, int} */
    private function requestSize(float $ratio): array
    {
        $width = $ratio >= 1 ? 1536 : (int) round(1536 * $ratio / 16) * 16;
        $height = $ratio >= 1 ? (int) round(1536 / $ratio / 16) * 16 : 1536;

        return [$width, $height];
    }

    private function request(string $image, string $prompt, int $width, int $height, ?string $mask = null, array $options = []): string
    {
        if (! $this->configured($options)) {
            throw new OpenAiImageException(reason: 'not_configured');
        }
        $model = $this->model($options);
        if (trim($prompt) === '' || mb_strlen($prompt) > 32000) {
            throw new OpenAiImageException(reason: 'invalid_prompt');
        }
        $references = $options['reference_images'] ?? [];
        if (! is_array($references) || count($references) > 4) {
            throw new OpenAiImageException(reason: 'invalid_image');
        }
        $referenceImages = [];
        foreach ($references as $bytes) {
            if (! is_string($bytes)) {
                throw new OpenAiImageException(reason: 'invalid_image');
            }
            $referenceImages[] = (string) $this->read($bytes)->scaleDown(width: 2048, height: 2048)->toPng();
        }
        try {
            $request = Http::withToken((string) ($options['api_key'] ?? config('services.openai.api_key')))->acceptJson()
                ->connectTimeout(15)->timeout(max(30, min(600, (int) config('services.openai.image_timeout', 180))))
                ->withOptions(['allow_redirects' => false])
                ->attach('image[]', $image, 'property.png', ['Content-Type' => 'image/png']);
            if ($mask !== null) {
                $request->attach('mask', $mask, 'extension-mask.png', ['Content-Type' => 'image/png']);
            }
            foreach ($referenceImages as $index => $bytes) {
                $request->attach('image[]', $bytes, 'reference-'.($index + 1).'.png', ['Content-Type' => 'image/png']);
            }
            // GPT Image 2 always uses high input fidelity: input_fidelity is not accepted.
            $response = $request->post(self::ENDPOINT, ['model' => $model, 'prompt' => $prompt,
                'n' => 1, 'quality' => 'high', 'size' => $width.'x'.$height, 'output_format' => 'png', 'background' => 'opaque']);
        } catch (ConnectionException) {
            throw new OpenAiImageException(ambiguous: true, reason: 'transport');
        } catch (RequestException $exception) {
            throw $this->failure($exception->response->status(), $exception->response->json('error.code'));
        }
        if (! $response->successful()) {
            throw $this->failure($response->status(), $response->json('error.code'));
        }
        $encoded = $response->json('data.0.b64_json');
        if (! is_string($encoded) || strlen($encoded) > 70_000_000) {
            throw new OpenAiImageException(ambiguous: true, reason: 'invalid_result');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            throw new OpenAiImageException(ambiguous: true, reason: 'invalid_result');
        }
        $this->readResult($bytes);

        return $bytes;
    }

    private function failure(int $status, mixed $code): OpenAiImageException
    {
        $blocked = $code === 'moderation_blocked';

        return new OpenAiImageException($status,
            ambiguous: ! $blocked && ($status >= 500 || in_array($status, [408, 409, 425], true)),
            reason: $blocked ? 'moderation_blocked' : 'provider_rejected');
    }

    private function read(string $bytes): ImageInterface
    {
        $info = strlen($bytes) <= self::MAX_INPUT_BYTES ? @getimagesizefromstring($bytes) : false;
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] * $info[1] > self::MAX_INPUT_PIXELS) {
            throw new OpenAiImageException(reason: 'invalid_image');
        }
        try {
            return $this->images->read($bytes)->orient();
        } catch (Throwable) {
            throw new OpenAiImageException(reason: 'invalid_image');
        }
    }

    private function readResult(string $bytes): ImageInterface
    {
        try {
            return $this->read($bytes);
        } catch (OpenAiImageException) {
            throw new OpenAiImageException(ambiguous: true, reason: 'invalid_result');
        }
    }
}
