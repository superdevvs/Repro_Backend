<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\WeatherLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WeatherController extends Controller
{
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
            $weather = $this->weatherLookupService->lookup([
                'location' => $request->input('location'),
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'dateTime' => $request->input('dateTime'),
            ]);

            if (!$weather) {
                return response()->json([
                    'success' => false,
                    'message' => 'Weather data was not available for the requested location.',
                ], 404);
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
}
