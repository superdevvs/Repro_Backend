<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Square\SquareClient;
use Square\Exceptions\ApiException;

class TestSquareController extends Controller
{
    /**
     * Test Square API connection and credentials
     */
    public function testConnection()
    {
        $accessToken = config('services.square.access_token');
        $locationId = config('services.square.location_id');
        $environment = config('services.square.environment', 'sandbox');
        
        if (!$accessToken) {
            return response()->json([
                'success' => false,
                'message' => 'Square access token not configured. Please set SQUARE_ACCESS_TOKEN in your .env file.',
                'config' => [
                    'access_token' => 'NOT SET',
                    'location_id' => $locationId ?: 'NOT SET',
                    'environment' => $environment,
                ]
            ], 400);
        }

        if (!$locationId) {
            return response()->json([
                'success' => false,
                'message' => 'Square location ID not configured. Please set SQUARE_LOCATION_ID in your .env file.',
                'config' => [
                    'access_token' => 'SET (hidden)',
                    'location_id' => 'NOT SET',
                    'environment' => $environment,
                ]
            ], 400);
        }

        try {
            // Initialize Square client
            $client = new SquareClient($accessToken);
            
            // Test 1: Get merchant information
            $merchantsApi = $client->getMerchantsApi();
            $merchantResponse = $merchantsApi->listMerchants();
            
            if ($merchantResponse->isSuccess()) {
                $merchants = $merchantResponse->getResult()->getMerchant();
                $merchant = !empty($merchants) ? $merchants[0] : null;
            } else {
                $merchant = null;
            }

            // Test 2: Get location information
            $locationsApi = $client->getLocationsApi();
            $locationResponse = $locationsApi->retrieveLocation($locationId);
            
            $locationData = null;
            if ($locationResponse->isSuccess()) {
                $location = $locationResponse->getResult()->getLocation();
                $locationData = [
                    'id' => $location->getId(),
                    'name' => $location->getName(),
                    'address' => $location->getAddress() ? [
                        'address_line_1' => $location->getAddress()->getAddressLine1(),
                        'locality' => $location->getAddress()->getLocality(),
                        'administrative_district_level_1' => $location->getAddress()->getAdministrativeDistrictLevel1(),
                        'postal_code' => $location->getAddress()->getPostalCode(),
                        'country' => $location->getAddress()->getCountry(),
                    ] : null,
                    'status' => $location->getStatus(),
                    'timezone' => $location->getTimezone(),
                ];
            }

            // Test 3: List payment methods (to verify API access)
            $paymentsApi = $client->getPaymentsApi();
            $paymentsResponse = $paymentsApi->listPayments(
                null, // beginTime
                null, // endTime
                null, // sortOrder
                null, // cursor
                $locationId,
                null, // total
                null  // last4
            );

            $paymentsCount = 0;
            if ($paymentsResponse->isSuccess()) {
                $payments = $paymentsResponse->getResult()->getPayments();
                $paymentsCount = $payments ? count($payments) : 0;
            }

            return response()->json([
                'success' => true,
                'message' => 'Square API connection successful!',
                'config' => [
                    'access_token' => substr($accessToken, 0, 10) . '...' . substr($accessToken, -4),
                    'location_id' => $locationId,
                    'environment' => $environment,
                ],
                'merchant' => $merchant ? [
                    'id' => $merchant->getId(),
                    'business_name' => $merchant->getBusinessName(),
                    'country' => $merchant->getCountry(),
                    'language_code' => $merchant->getLanguageCode(),
                    'currency' => $merchant->getCurrency(),
                    'status' => $merchant->getStatus(),
                ] : 'Could not retrieve merchant info',
                'location' => $locationData ?: 'Could not retrieve location info',
                'payments' => [
                    'count' => $paymentsCount,
                    'status' => $paymentsResponse->isSuccess() ? 'success' : 'failed',
                ],
                'api_status' => 'All API calls successful',
            ]);

        } catch (ApiException $e) {
            Log::error('Square API Exception', [
                'message' => $e->getMessage(),
                'errors' => $e->getErrors(),
                'response_body' => $e->getResponseBody(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Square API error occurred',
                'error' => $e->getMessage(),
                'errors' => $e->getErrors(),
                'response_body' => $e->getResponseBody(),
                'config' => [
                    'access_token' => substr($accessToken, 0, 10) . '...' . substr($accessToken, -4),
                    'location_id' => $locationId,
                    'environment' => $environment,
                ],
            ], 500);

        } catch (\Exception $e) {
            Log::error('Square Connection Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to Square API',
                'error' => $e->getMessage(),
                'config' => [
                    'access_token' => substr($accessToken, 0, 10) . '...' . substr($accessToken, -4),
                    'location_id' => $locationId,
                    'environment' => $environment,
                ],
            ], 500);
        }
    }

    /**
     * Get Square locations for the configured account
     */
    public function listLocations()
    {
        $accessToken = config('services.square.access_token');
        
        if (!$accessToken) {
            return response()->json([
                'success' => false,
                'message' => 'Square access token not configured'
            ], 400);
        }

        try {
            $client = new SquareClient($accessToken);
            $locationsApi = $client->getLocationsApi();
            $response = $locationsApi->listLocations();

            if ($response->isSuccess()) {
                $locations = $response->getResult()->getLocations();
                $locationList = [];
                
                foreach ($locations as $location) {
                    $locationList[] = [
                        'id' => $location->getId(),
                        'name' => $location->getName(),
                        'address' => $location->getAddress() ? [
                            'address_line_1' => $location->getAddress()->getAddressLine1(),
                            'locality' => $location->getAddress()->getLocality(),
                            'administrative_district_level_1' => $location->getAddress()->getAdministrativeDistrictLevel1(),
                            'postal_code' => $location->getAddress()->getPostalCode(),
                            'country' => $location->getAddress()->getCountry(),
                        ] : null,
                        'status' => $location->getStatus(),
                        'timezone' => $location->getTimezone(),
                    ];
                }

                return response()->json([
                    'success' => true,
                    'locations' => $locationList,
                    'count' => count($locationList),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to retrieve locations',
                    'errors' => $response->getErrors(),
                ], 500);
            }

        } catch (ApiException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Square API error',
                'error' => $e->getMessage(),
                'errors' => $e->getErrors(),
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to Square API',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Square configuration for frontend (non-sensitive data only)
     */
    public function getConfig()
    {
        $applicationId = config('services.square.application_id');
        $locationId = config('services.square.location_id');
        $environment = config('services.square.environment', 'sandbox');
        
        if (empty($applicationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Square Application ID is not configured. Please set SQUARE_APPLICATION_ID in your .env file.',
                'config' => null,
            ], 400);
        }
        
        if (empty($locationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Square Location ID is not configured. Please set SQUARE_LOCATION_ID in your .env file. You can get it from /api/test/square-locations',
                'config' => [
                    'application_id' => $applicationId,
                    'location_id' => null,
                    'environment' => $environment,
                ],
            ], 400);
        }
        
        return response()->json([
            'success' => true,
            'config' => [
                'application_id' => $applicationId,
                'location_id' => $locationId,
                'environment' => $environment,
                'currency' => config('services.square.currency', 'USD'),
            ],
        ]);
    }
}


