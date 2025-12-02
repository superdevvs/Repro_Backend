<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AyrshareService
{
    protected $apiKey;
    protected $baseUrl = 'https://app.ayrshare.com/api';

    public function __construct()
    {
        $this->apiKey = config('services.ayrshare.api_key', env('AYRSHARE_API_KEY'));
    }

    /**
     * Create a slideshow from images using Ayrshare's post endpoint
     * Ayrshare creates a carousel/slideshow when multiple media URLs are provided
     * 
     * @param array $imageUrls Array of image URLs
     * @param string $title Slideshow title
     * @param string $orientation 'portrait' or 'landscape'
     * @param array $options Additional options (transition, speed, etc.)
     * @return array|null
     */
    public function createSlideshow(array $imageUrls, string $title, string $orientation = 'landscape', array $options = [])
    {
        if (empty($this->apiKey)) {
            Log::error('Ayrshare API key not configured');
            return null;
        }

        try {
            // Ayrshare uses the /post endpoint with mediaURLs to create carousel/slideshow posts
            $payload = [
                'post' => $title, // Post caption/title
                'mediaURLs' => $imageUrls, // Array of image URLs - creates carousel/slideshow
                'platforms' => ['instagram', 'facebook', 'twitter'], // Can be adjusted based on needs
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/post", $payload);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Ayrshare slideshow created', ['id' => $data['id'] ?? null]);
                
                // Return formatted response
                return [
                    'id' => $data['id'] ?? null,
                    'url' => $data['url'] ?? $data['postUrl'] ?? null,
                    'slideshowUrl' => $data['url'] ?? $data['postUrl'] ?? null,
                    'downloadUrl' => $data['downloadUrl'] ?? null,
                    'status' => $data['status'] ?? 'success',
                ];
            } else {
                Log::error('Ayrshare API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Ayrshare service exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Get post/slideshow status
     * 
     * @param string $postId
     * @return array|null
     */
    public function getSlideshowStatus(string $postId)
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->get("{$this->baseUrl}/post/{$postId}");

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Ayrshare get status error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get slideshow download URL
     * 
     * @param string $postId
     * @return string|null Download URL
     */
    public function getSlideshowDownloadUrl(string $postId)
    {
        $status = $this->getSlideshowStatus($postId);
        
        if ($status && isset($status['downloadUrl'])) {
            return $status['downloadUrl'];
        }

        // If no direct download URL, return the post URL
        if ($status && isset($status['url'])) {
            return $status['url'];
        }

        return null;
    }
}


