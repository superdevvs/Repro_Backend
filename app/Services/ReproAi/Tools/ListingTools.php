<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use Illuminate\Support\Facades\Log;

class ListingTools
{
    /**
     * Get listing details
     * Note: Using Shoot model as listing representation for now
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Listing data
     */
    public function getListing(array $params, array $context = []): array
    {
        try {
            $listingId = $params['listing_id'] ?? null;
            
            if (!$listingId) {
                return [
                    'success' => false,
                    'error' => 'Listing ID is required',
                ];
            }

            // For now, use Shoot model as listing representation
            // In a real system, you'd have a separate Listing model
            $shoot = Shoot::with(['client', 'services'])->find($listingId);
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Listing not found',
                ];
            }

            return [
                'success' => true,
                'listing' => [
                    'id' => $shoot->id,
                    'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state} {$shoot->zip}",
                    'title' => "Property at {$shoot->address}",
                    'description' => $shoot->notes ?? $shoot->shoot_notes ?? 'No description available.',
                    'status' => $shoot->status,
                    'scheduled_date' => $shoot->scheduled_date?->toDateString(),
                    'services' => $shoot->services->pluck('name')->toArray(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ListingTools::getListing error', [
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
     * Update listing copy (title, description, highlights)
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Update result
     */
    public function updateListingCopy(array $params, array $context = []): array
    {
        try {
            $listingId = $params['listing_id'] ?? null;
            
            if (!$listingId) {
                return [
                    'success' => false,
                    'error' => 'Listing ID is required',
                ];
            }

            $shoot = Shoot::find($listingId);
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Listing not found',
                ];
            }

            $updates = [];
            
            if (isset($params['description'])) {
                $updates['notes'] = $params['description'];
                $updates['shoot_notes'] = $params['description'];
            }

            if (!empty($updates)) {
                $shoot->update($updates);
            }

            return [
                'success' => true,
                'message' => 'Listing copy updated successfully',
                'listing_id' => $shoot->id,
                'updated_fields' => array_keys($updates),
            ];
        } catch (\Exception $e) {
            Log::error('ListingTools::updateListingCopy error', [
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
     * Get listings needing media attention
     * 
     * @param array $params Parameters from AI tool call
     * @param array $context Additional context
     * @return array Listings needing attention
     */
    public function getListingsNeedingMedia(array $params, array $context = []): array
    {
        try {
            $userId = $context['user_id'] ?? auth()->id();
            
            // Find shoots with missing media or old media
            $shoots = Shoot::where('client_id', $userId)
                ->where(function ($query) {
                    $query->where('missing_raw', true)
                        ->orWhere('missing_final', true)
                        ->orWhereNull('hero_image');
                })
                ->with('services')
                ->get();

            return [
                'success' => true,
                'listings' => $shoots->map(function ($shoot) {
                    return [
                        'id' => $shoot->id,
                        'address' => "{$shoot->address}, {$shoot->city}, {$shoot->state}",
                        'issues' => [
                            $shoot->missing_raw ? 'Missing raw photos' : null,
                            $shoot->missing_final ? 'Missing edited photos' : null,
                            !$shoot->hero_image ? 'No hero image' : null,
                        ],
                    ];
                })->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error('ListingTools::getListingsNeedingMedia error', [
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}






