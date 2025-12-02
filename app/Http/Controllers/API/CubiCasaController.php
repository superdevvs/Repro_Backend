<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Shoot;

class CubiCasaController extends Controller
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.cubicasa.api_key');
        $this->baseUrl = config('services.cubicasa.base_url');
    }

    /**
     * Make authenticated request to CubiCasa API
     */
    protected function makeRequest($method, $endpoint, $data = [])
    {
        if (!$this->apiKey) {
            Log::error('CubiCasa API key not configured', [
                'api_key_set' => !empty($this->apiKey),
                'base_url' => $this->baseUrl
            ]);
            return response()->json([
                'error' => 'CubiCasa API key not configured',
                'message' => 'Please configure CUBICASA_API_KEY in your .env file'
            ], 500);
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        try {
            Log::info('CubiCasa API request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'url' => $url
            ]);

            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->{strtolower($method)}($url, $data);

            if ($response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $responseBody = $response->body();
            $responseData = $response->json();

            Log::error('CubiCasa API error', [
                'endpoint' => $endpoint,
                'url' => $url,
                'status' => $response->status(),
                'response' => $responseBody,
                'headers' => $response->headers()
            ]);

            return response()->json([
                'error' => 'CubiCasa API request failed',
                'message' => $responseData['message'] ?? $responseData['error'] ?? 'API request failed',
                'status' => $response->status(),
                'details' => $responseData
            ], $response->status());

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('CubiCasa API connection exception', [
                'endpoint' => $endpoint,
                'url' => $url,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to connect to CubiCasa API',
                'message' => 'Unable to reach CubiCasa servers. Please check your internet connection and try again.',
                'details' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('CubiCasa API exception', [
                'endpoint' => $endpoint,
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to connect to CubiCasa API',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new scan order
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'address' => 'required|string',
            'property_type' => 'nullable|string',
            'shoot_id' => 'nullable|exists:shoots,id',
            'notes' => 'nullable|string',
            'customer_name' => 'nullable|string',
            'customer_email' => 'nullable|email',
        ]);

        $user = $request->user();

        $orderData = [
            'address' => $validated['address'],
            'property_type' => $validated['property_type'] ?? 'residential',
            'notes' => $validated['notes'] ?? '',
        ];

        if (isset($validated['customer_name'])) {
            $orderData['customer_name'] = $validated['customer_name'];
        }

        if (isset($validated['customer_email'])) {
            $orderData['customer_email'] = $validated['customer_email'];
        }

        $response = $this->makeRequest('POST', '/orders', $orderData);

        if ($response->getStatusCode() === 200 || $response->getStatusCode() === 201) {
            $orderResponse = json_decode($response->getContent(), true);
            $orderId = $orderResponse['id'] ?? $orderResponse['order_id'] ?? null;

            // Link to shoot if provided
            if (isset($validated['shoot_id']) && $orderId) {
                $this->linkToShootInternal($orderId, $validated['shoot_id'], $user->id);
            }

            return $response;
        }

        return $response;
    }

    /**
     * Get order details
     */
    public function getOrder($orderId)
    {
        return $this->makeRequest('GET', "/orders/{$orderId}");
    }

    /**
     * List orders
     */
    public function listOrders(Request $request)
    {
        try {
            $shootId = $request->query('shoot_id');
            $status = $request->query('status');
            $limit = $request->query('limit', 50);
            $offset = $request->query('offset', 0);

            $params = [
                'limit' => $limit,
                'offset' => $offset,
            ];

            if ($status) {
                $params['status'] = $status;
            }

            $endpoint = '/orders?' . http_build_query($params);
            $response = $this->makeRequest('GET', $endpoint);

            // If filtering by shoot, we'd need to check our local database
            // For now, return all orders and filter client-side if needed
            return $response;
        } catch (\Exception $e) {
            Log::error('CubiCasa listOrders exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to list orders',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload photos for an order
     */
    public function uploadPhotos(Request $request, $orderId)
    {
        $validated = $request->validate([
            'photos' => 'required|array',
            'photos.*' => 'required|image|max:10240', // 10MB max per photo
        ]);

        $photos = $request->file('photos');
        $uploadedFiles = [];

        foreach ($photos as $photo) {
            // Upload to CubiCasa API
            // Note: CubiCasa API may require multipart form data
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])->attach('photo', file_get_contents($photo->getRealPath()), $photo->getClientOriginalName())
                  ->post(rtrim($this->baseUrl, '/') . "/orders/{$orderId}/photos");

                if ($response->successful()) {
                    $uploadedFiles[] = [
                        'filename' => $photo->getClientOriginalName(),
                        'status' => 'uploaded',
                        'response' => $response->json()
                    ];
                } else {
                    $uploadedFiles[] = [
                        'filename' => $photo->getClientOriginalName(),
                        'status' => 'failed',
                        'error' => $response->json()
                    ];
                }
            } catch (\Exception $e) {
                Log::error('CubiCasa photo upload error', [
                    'order_id' => $orderId,
                    'filename' => $photo->getClientOriginalName(),
                    'error' => $e->getMessage()
                ]);

                $uploadedFiles[] = [
                    'filename' => $photo->getClientOriginalName(),
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'order_id' => $orderId,
            'uploads' => $uploadedFiles
        ]);
    }

    /**
     * Get order status
     */
    public function getOrderStatus($orderId)
    {
        return $this->makeRequest('GET', "/orders/{$orderId}/status");
    }

    /**
     * Link order to shoot
     */
    public function linkToShoot(Request $request, $orderId)
    {
        $validated = $request->validate([
            'shoot_id' => 'required|exists:shoots,id',
        ]);

        $user = $request->user();
        return $this->linkToShootInternal($orderId, $validated['shoot_id'], $user->id);
    }

    /**
     * Internal method to link order to shoot
     */
    protected function linkToShootInternal($orderId, $shootId, $userId)
    {
        try {
            $shoot = Shoot::findOrFail($shootId);

            // Get order details to get the floor plan URL
            $orderResponse = $this->makeRequest('GET', "/orders/{$orderId}");
            
            if ($orderResponse->getStatusCode() === 200) {
                $orderData = json_decode($orderResponse->getContent(), true);
                $floorPlanUrl = $orderData['floor_plan_url'] ?? $orderData['result_url'] ?? null;

                // Update shoot's tour links
                $tourLinks = $shoot->tour_links ?? [];
                $tourLinks['cubicasa'] = $floorPlanUrl ?? "https://app.cubi.casa/orders/{$orderId}";

                $shoot->tour_links = $tourLinks;
                $shoot->save();

                return response()->json([
                    'message' => 'Order linked to shoot successfully',
                    'shoot_id' => $shootId,
                    'order_id' => $orderId
                ]);
            }

            return response()->json([
                'error' => 'Failed to retrieve order details'
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to link CubiCasa order to shoot', [
                'order_id' => $orderId,
                'shoot_id' => $shootId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to link order to shoot',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
