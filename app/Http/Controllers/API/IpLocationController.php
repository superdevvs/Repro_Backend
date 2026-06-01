<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\IpLocationLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class IpLocationController extends Controller
{
    /**
     * Time-to-live (in minutes) for cached IP-location lookups. IP->location
     * mappings are stable for relatively long periods, so a longer TTL is safe.
     */
    public const CACHE_TTL_MINUTES = 60;

    public function __construct(
        private readonly IpLocationLookupService $ipLocationLookupService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'postalCode' => 'nullable|string|max:32',
            'countryCode' => 'nullable|string|max:8',
            'city' => 'nullable|string|max:120',
            'region' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $hasHint = $request->filled('postalCode')
            || ($request->filled('latitude') && $request->filled('longitude'));

        $cacheKey = $hasHint
            ? 'iploc:hint:' . sha1(json_encode($request->only([
                'latitude', 'longitude', 'postalCode', 'countryCode', 'city', 'region',
            ])))
            : 'iploc:ip:' . sha1((string) $request->ip());

        $location = Cache::remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => $hasHint
                ? $this->ipLocationLookupService->refine([
                    'latitude' => $request->input('latitude'),
                    'longitude' => $request->input('longitude'),
                    'postalCode' => $request->input('postalCode'),
                    'countryCode' => $request->input('countryCode'),
                    'city' => $request->input('city'),
                    'region' => $request->input('region'),
                ])
                : $this->ipLocationLookupService->lookup($request->ip())
        );

        if (!$location) {
            return response()->json([
                'success' => false,
                'message' => 'IP-based location was not available.',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $location,
        ]);
    }
}
