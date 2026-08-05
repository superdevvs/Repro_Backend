<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\WeatherLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WeatherController extends Controller
{
    /**
     * Minutes a successful weather payload is retained in the response cache.
     */
    private const CACHE_TTL_MINUTES = 10;

    public function __construct(
        private readonly WeatherLookupService $weatherLookupService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'location' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'dateTime' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $hasLocation = filled($request->input('location'));
        $hasCoords = $request->filled('latitude') && $request->filled('longitude');

        if (!$hasLocation && !$hasCoords) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => [
                    'location' => ['Provide either a location string or latitude/longitude coordinates.'],
                ],
            ], 422);
        }

        try {
            $cacheKey = 'weather:' . sha1(json_encode([
                'location' => $request->input('location'),
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'dateTime' => $request->input('dateTime'),
            ]));

            $weather = $this->safeCacheGet($cacheKey);
            $cacheMiss = !$weather;

            if ($cacheMiss) {
                $weather = $this->weatherLookupService->lookup([
                    'location' => $request->input('location'),
                    'latitude' => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),
                    'dateTime' => $request->input('dateTime'),
                ]);
            }

            if (!$weather) {
                // Do not persist a transient upstream miss: drop the empty entry so the
                // next request retries upstream rather than serving a cached null.
                $this->safeCacheForget($cacheKey);

                return response()->json([
                    'success' => false,
                    'message' => 'Weather data was not available for the requested location.',
                ], 404);
            }

            // Do not rewrite a hit: doing so creates a sliding expiration and a
            // frequently requested location may never refresh from upstream.
            if ($cacheMiss) {
                $this->safeCachePut($cacheKey, $weather);
            }

            return response()->json([
                'success' => true,
                'data' => $weather,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Weather lookup failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function safeCacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable $e) {
            Log::warning('Weather response cache read failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function safeCachePut(string $key, mixed $value): void
    {
        try {
            Cache::put($key, $value, now()->addMinutes(self::CACHE_TTL_MINUTES));
        } catch (\Throwable $e) {
            Log::warning('Weather response cache write failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeCacheForget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (\Throwable $e) {
            Log::warning('Weather response cache forget failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
