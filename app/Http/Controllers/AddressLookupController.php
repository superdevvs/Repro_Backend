<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\AddressLookupService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class AddressLookupController extends Controller
{
    private AddressLookupService $addressService;

    public function __construct(AddressLookupService $addressService)
    {
        $this->addressService = $addressService;
    }

    /**
     * Search for address suggestions
     */
    public function searchAddresses(Request $request): JsonResponse
    {
        // Accept both 'query' and 'q' as the search term
        if (!$request->has('query') && $request->has('q')) {
            $request->merge(['query' => $request->get('q')]);
        }

        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:3|max:255',
            'location' => 'nullable|string', // lat,lng format
            'radius' => 'nullable|integer|min:1000|max:50000' // meters
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $options = [];
            
            if ($request->has('location')) {
                $options['location'] = $request->location;
            }
            
            if ($request->has('radius')) {
                $options['radius'] = $request->radius;
            }

            $suggestions = $this->addressService->searchAddresses(
                (string) $request->input('query'),
                $options
            );

            return response()->json([
                'success' => true,
                'data' => $suggestions,
                'count' => count($suggestions)
            ]);

        } catch (\Exception $e) {
            Log::error('Address search controller error', [
                'query' => $request->input('query'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'Address search failed',
                'message' => $e->getMessage(),
                'hint' => str_contains($e->getMessage(), 'LocationIQ') 
                    ? 'LocationIQ API key may not be configured. Please set LOCATIONIQ_API_KEY in your .env file. See LOCATIONIQ_SETUP.md for instructions.'
                    : 'Please check your API configuration and try again.'
            ], 500);
        }
    }

    /**
     * Get detailed address information by place ID
     */
    public function getAddressDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'place_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $details = $this->addressService->getAddressDetails((string) $request->input('place_id'));

            if (!$details) {
                return response()->json([
                    'error' => 'Address not found',
                    'message' => 'Could not retrieve address details for the provided place ID'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $details
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Address details lookup failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate an address
     */
    public function validateAddress(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|size:2',
            'zip' => 'required|string|regex:/^\d{5}(-\d{4})?$/',
            'place_id' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $addressData = $request->only(['address', 'city', 'state', 'zip', 'place_id']);
            $validation = $this->addressService->validateAddress($addressData);

            return response()->json([
                'success' => true,
                'data' => $validation
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Address validation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate distance between two addresses
     */
    public function calculateDistance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'origin' => 'required|array',
            'origin.address' => 'required|string',
            'origin.city' => 'required|string',
            'origin.state' => 'required|string',
            'origin.zip' => 'required|string',
            'destination' => 'required|array',
            'destination.address' => 'required|string',
            'destination.city' => 'required|string',
            'destination.state' => 'required|string',
            'destination.zip' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $distance = $this->addressService->getDistance(
                $request->origin,
                $request->destination
            );

            if (!$distance) {
                return response()->json([
                    'error' => 'Distance calculation failed',
                    'message' => 'Could not calculate distance between the provided addresses'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => $distance
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Distance calculation failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get nearby photographers (placeholder for future implementation)
     */
    public function getNearbyPhotographers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'zip' => 'required|string',
            'radius' => 'nullable|integer|min:1|max:100' // miles
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // This is a placeholder - you can implement photographer location logic later
        return response()->json([
            'success' => true,
            'data' => [
                'photographers' => [],
                'message' => 'Photographer location matching will be implemented based on your business requirements'
            ]
        ]);
    }

    /**
     * Get service area information
     */
    public function checkServiceArea(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'zip' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // This is a placeholder - implement your service area logic
        $serviceAreas = [
            'DC', 'MD', 'VA', 'CA', 'NY', 'FL', 'TX' // Example service areas
        ];

        $isInServiceArea = in_array(strtoupper($request->state), $serviceAreas);

        return response()->json([
            'success' => true,
            'data' => [
                'in_service_area' => $isInServiceArea,
                'state' => strtoupper($request->state),
                'service_areas' => $serviceAreas,
                'message' => $isInServiceArea 
                    ? 'This address is in our service area' 
                    : 'This address is outside our current service area'
            ]
        ]);
    }
}
