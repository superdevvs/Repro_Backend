<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class FalService
{
    private string $key;
    private string $model;

    public function __construct()
    {
        $this->key = (string) config('services.fal.key');
        $this->model = (string) config('services.fal.model', 'fal-ai/wan-pro/image-to-video');

        $missing = $this->key === '' || $this->key === 'PASTE_YOUR_FAL_KEY_HERE';
        if ($missing && ! config('services.fal.test_mode')) {
            throw new RuntimeException(
                'FAL_KEY is not set. Add it to your .env file or enable FAL_TEST_MODE=true.'
            );
        }
    }

    public function uploadImage(string $binary, string $mime): string
    {
        $init = Http::withHeaders([
            'Authorization' => 'Key ' . $this->key,
            'Content-Type' => 'application/json',
        ])->post('https://rest.alpha.fal.ai/storage/upload/initiate', [
            'content_type' => $mime,
            'file_name' => 'listing-photo-' . uniqid('', true) . $this->extensionForMime($mime),
        ]);

        if (! $init->successful()) {
            throw new RuntimeException('fal.ai storage initiate failed: ' . $init->body());
        }

        $uploadUrl = $init->json('upload_url');
        $fileUrl = $init->json('file_url');
        if (! $uploadUrl || ! $fileUrl) {
            throw new RuntimeException('fal.ai storage did not return upload/file URLs.');
        }

        $put = Http::withBody($binary, $mime)->put($uploadUrl);
        if (! $put->successful()) {
            throw new RuntimeException('fal.ai storage upload failed: ' . $put->status());
        }

        return $fileUrl;
    }

    public function submit(string $imageUrl, string $prompt): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Key ' . $this->key,
            'Content-Type' => 'application/json',
        ])->post('https://queue.fal.run/' . $this->model, [
            'image_url' => $imageUrl,
            'prompt' => $prompt,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('fal.ai submit failed: ' . $response->body());
        }

        $requestId = $response->json('request_id');
        if (! $requestId) {
            throw new RuntimeException('fal.ai did not return a request_id.');
        }

        return (string) $requestId;
    }

    public function status(string $requestId): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Key ' . $this->key,
        ])->get('https://queue.fal.run/' . $this->model . '/requests/' . $requestId . '/status');

        if (! $response->successful()) {
            return 'IN_PROGRESS';
        }

        return (string) ($response->json('status') ?? 'IN_PROGRESS');
    }

    public function result(string $requestId): string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Key ' . $this->key,
        ])->get('https://queue.fal.run/' . $this->model . '/requests/' . $requestId);

        if (! $response->successful()) {
            throw new RuntimeException('fal.ai result fetch failed: ' . $response->body());
        }

        $url = $response->json('video.url')
            ?? $response->json('video_url')
            ?? $response->json('output.video.url');

        if (! $url) {
            throw new RuntimeException('fal.ai result had no video URL: ' . $response->body());
        }

        return (string) $url;
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
}
