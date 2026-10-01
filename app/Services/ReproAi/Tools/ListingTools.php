<?php

namespace App\Services\ReproAi\Tools;

use App\Models\Shoot;
use Illuminate\Support\Facades\Log;

class ListingTools
{
    public function __construct(private readonly RobbieRecordAccess $access) {}

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
            $address = $params['address'] ?? null;
            
            if (!$listingId && !$address) {
                return [
                    'success' => false,
                    'error' => 'Listing ID or address is required',
                ];
            }

            // For now, use Shoot model as listing representation
            // In a real system, you'd have a separate Listing model
            $shoot = $this->resolveListing($params, $context, ['client', 'services']);
            
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
                    'description' => $this->access->description($shoot, $this->access->actor($context)) ?? 'No description available.',
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
            $address = $params['address'] ?? null;
            
            if (!$listingId && !$address) {
                return [
                    'success' => false,
                    'error' => 'Listing ID or address is required',
                ];
            }

            $shoot = $this->resolveListing($params, $context);
            
            if (!$shoot) {
                return [
                    'success' => false,
                    'error' => 'Listing not found',
                ];
            }

            $actor = $this->access->actor($context);
            if (! $this->access->canChangeNote($shoot, $actor, 'shoot_notes')) {
                return ['success' => false, 'error' => 'You do not have permission to update this listing.'];
            }
            $updates = [];
            
            if (isset($params['description'])) {
                if ($this->access->canChangeNote($shoot, $actor, 'notes')) {
                    $updates['notes'] = $params['description'];
                }
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
            $actor = $this->access->actor($context);
            if (! $actor) return ['success' => false, 'error' => 'An authorized account is required.'];
            $userId = $actor->id;
            
            // Find shoots with missing media or old media
            $shoots = $this->access->query($actor)->where('client_id', $userId)
                ->where(function ($query) {
                    $query->where('missing_raw', true)
                        ->orWhere('missing_final', true)
                        ->orWhereNull('hero_image');
                })
                ->with('services')
                ->get()
                ->filter(fn (Shoot $shoot) => $this->access->canRead($shoot, $actor))
                ->values();

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

    private function resolveListing(array $params, array $context, array $relations = []): ?Shoot
    {
        $listingId = $params['listing_id'] ?? null;
        $address = $params['address'] ?? null;

        $actor = $this->access->actor($context);

        if ($listingId) {
            return $this->access->find($listingId, $actor, $relations);
        }

        if (!$address) {
            return null;
        }

        $parts = $this->parseAddressParts($address);
        $query = $this->access->query($actor);
        if (!empty($relations)) {
            $query->with($relations);
        }

        if (!empty($parts['street'])) {
            $query->where('address', 'like', '%' . $parts['street'] . '%');
        }
        if (!empty($parts['city'])) {
            $query->where('city', 'like', '%' . $parts['city'] . '%');
        }
        if (!empty($parts['state'])) {
            $query->where('state', 'like', '%' . $parts['state'] . '%');
        }
        if (!empty($parts['zip'])) {
            $query->where('zip', 'like', '%' . $parts['zip'] . '%');
        }

        $shoot = $query->orderBy('created_at', 'desc')->first();
        if ($shoot && $this->access->canRead($shoot, $actor)) {
            return $shoot;
        }

        $fallbackQuery = $this->access->query($actor);
        if (!empty($relations)) {
            $fallbackQuery->with($relations);
        }
        $raw = $parts['raw'] ?? $address;

        $fallback = $fallbackQuery
            ->where(function ($builder) use ($raw) {
                $builder->where('address', 'like', '%' . $raw . '%')
                    ->orWhere('city', 'like', '%' . $raw . '%')
                    ->orWhere('state', 'like', '%' . $raw . '%');
            })
            ->orderBy('created_at', 'desc')
            ->first();

        return $fallback && $this->access->canRead($fallback, $actor) ? $fallback : null;
    }

    private function parseAddressParts(string $address): array
    {
        $cleaned = trim(preg_replace('/\*+/', '', $address));
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
        $parts = array_values(array_filter(array_map('trim', explode(',', $cleaned))));

        $street = $parts[0] ?? null;
        $city = $parts[1] ?? null;
        $state = null;
        $zip = null;

        if (!empty($parts[2])) {
            $stateZip = preg_replace('/[^a-z0-9\s]/i', '', $parts[2]);
            $tokens = preg_split('/\s+/', trim($stateZip));
            $state = !empty($tokens[0]) ? strtoupper($tokens[0]) : null;
            $zip = $tokens[1] ?? null;
        }

        return [
            'raw' => $cleaned,
            'street' => $street,
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
        ];
    }
}






