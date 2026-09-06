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

            // CubiCasa Integrate API v3 requires `api-key:` header (NOT Authorization: Bearer).
            // See https://integrate.docs.cubi.casa/get-started-1362307m0
            $response = Http::timeout(30)->withHeaders([
                'api-key' => (string) $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->{strtolower($method)}($url, $data);

            if ($response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            Log::error('CubiCasa API error', [
                'status' => $response->status(),
                'request_id' => \App\Services\RequestCorrelation::id(request()),
            ]);

            return response()->json([
                'error' => 'CubiCasa API request failed',
                'message' => 'CubiCasa could not complete this request. Check the integration settings and try again.',
                'status' => $response->status(),
                'code' => 'cubicasa_request_failed',
            ], $response->status());

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'error' => 'Failed to connect to CubiCasa API',
                'message' => 'Unable to reach CubiCasa servers. Please check your internet connection and try again.',
                'details' => \App\Services\ApiErrorResponder::publicMessage($e)
            ], 500);
        } catch (\Exception $e) {
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'error' => 'Failed to connect to CubiCasa API',
                'message' => \App\Services\ApiErrorResponder::publicMessage($e)
            ], 500);
        }
    }

    /**
     * Create a new scan order — DEPRECATED in this codebase.
     *
     * The old implementation POSTed to `/orders` (which doesn't exist in v3) and
     * used the wrong auth header. CubiCasa v3 expects `POST /orders/draft`
     * with `api-key:` auth. Order creation is intentionally out of scope for
     * the current passive-ingestion phase. Returns 410 Gone with guidance.
     */
    public function createOrder(Request $request)
    {
        return response()->json([
            'error' => 'Order creation is disabled',
            'message' => 'CubiCasa orders must be created in the CubiCasa portal/app for now. Paste the resulting order UUID into the shoot Tour tab. Programmatic order creation will be added in a later phase.',
            'docs' => 'https://integrate.docs.cubi.casa/create-a-draft-order-20093452e0',
        ], 410);
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
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'error' => 'Failed to list orders',
                'message' => \App\Services\ApiErrorResponder::publicMessage($e)
            ], 500);
        }
    }

    /**
     * Upload photos for an order — DEPRECATED.
     *
     * CubiCasa v3 does not expose a `/orders/{id}/photos` endpoint; scans are
     * captured via the CubiCasa mobile app or GoToScan invite. Returns 410.
     */
    public function uploadPhotos(Request $request, $orderId)
    {
        return response()->json([
            'error' => 'Photo upload via API is not supported',
            'message' => 'CubiCasa scans are captured by the CubiCasa mobile app or GoToScan invite. Photos cannot be uploaded via the API.',
        ], 410);
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
            \App\Services\ApiErrorResponder::log($e, 'error');

            return response()->json([
                'error' => 'Failed to link order to shoot',
                'message' => \App\Services\ApiErrorResponder::publicMessage($e)
            ], 500);
        }
    }
}
