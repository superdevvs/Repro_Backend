<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PropertyTools
{
    /**
     * Get property details
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Property data
     */
    public function getProperty(array $params, array $context = []): array
    {
        try {
            $propertyId = $params['property_id'] ?? null;
            $address = $params['address'] ?? null;
            
            if (!$propertyId && !$address) {
                return [
                    'success' => false,
                    'error' => 'Property ID or address is required',
                ];
            }

            $query = Shoot::with(['client', 'services', 'photographer']);
            
            if ($propertyId) {
                $query->where('id', $propertyId);
            } elseif ($address) {
                $query->where('address', 'like', "%{$address}%");
            }
            
            $shoot = $query->first();
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Property not found',
                ];
            }

            return [
                'success' => true,
                'property' => [
                    'id' => $shoot->id,
                    'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state} {$shoot->zip}",
                    'full_address' => [
                        'street' => $shoot->address,
                        'city' => $shoot->city,
                        'state' => $shoot->state,
                        'zip' => $shoot->zip,
                    ],
                    'client' => [
                        'id' => $shoot->client_id,
                        'name' => $shoot->client->name ?? 'Unknown',
                    ],
                    'status' => $shoot->status,
                    'workflow_status' => $shoot->workflow_status,
                    'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                    'time' => $shoot->time,
                    'services' => $shoot->services->pluck('name')->toArray(),
                    'notes' => $shoot->notes ?? $shoot->shoot_notes ?? null,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('PropertyTools::getProperty error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get portfolio overview for a user
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Portfolio statistics
     */
    public function getPortfolioOverview(array $params, array $context = []): array
    {
        try {
            $userId = $params['user_id'] ?? $context['user_id'] ?? auth()->id();
            
            if (!$userId) {
                return [
                    'success' => false,
                    'error' => 'User ID is required',
                ];
            }

            $shoots = Shoot::where('client_id', $userId)->get();
            
            $totalShoots = $shoots->count();
            $scheduledShoots = $shoots->where('status', 'scheduled')->count();
            $completedShoots = $shoots->where('status', 'completed')->count();
            $needsMedia = $shoots->filter(function ($shoot) {
                return $shoot->missing_raw || $shoot->missing_final || !$shoot->hero_image;
            })->count();

            $recentShoots = $shoots->sortByDesc('created_at')->take(5)->map(function ($shoot) {
                return [
                    'id' => $shoot->id,
                    'address' => "{$shoot->address}, {$shoot->city}",
                    'status' => $shoot->status,
                    'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                ];
            })->values();

            return [
                'success' => true,
                'portfolio' => [
                    'total_shoots' => $totalShoots,
                    'scheduled_shoots' => $scheduledShoots,
                    'completed_shoots' => $completedShoots,
                    'needs_media' => $needsMedia,
                    'recent_shoots' => $recentShoots,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('PropertyTools::getPortfolioOverview error', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get property insights (selling points, ideal buyer, etc.)
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Property insights
     */
    public function getPropertyInsights(array $params, array $context = []): array
    {
        try {
            $propertyId = $params['property_id'] ?? null;
            
            if (!$propertyId) {
                return [
                    'success' => false,
                    'error' => 'Property ID is required',
                ];
            }

            $shoot = Shoot::with(['services', 'client'])->find($propertyId);
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Property not found',
                ];
            }

            // Basic insights based on available data
            $insights = [
                'location' => [
                    'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state}",
                    'city' => $shoot->city,
                    'state' => $shoot->state,
                ],
                'services_requested' => $shoot->services->pluck('name')->toArray(),
                'status' => $shoot->status,
                'scheduled_date' => $shoot->scheduled_date?->toDateString(),
            ];

            return [
                'success' => true,
                'insights' => $insights,
            ];
        } catch (\Exception $e) {
            Log::error('PropertyTools::getPropertyInsights error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}






