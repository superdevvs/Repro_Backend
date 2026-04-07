<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShootResource extends JsonResource
{
    /**
     * Calculate total photographer pay from services
     */
    protected function calculatePhotographerPay(): float
    {
        // Ensure services (with category) are loaded
        if (!$this->relationLoaded('services')) {
            $this->load('services.category');
        } elseif ($this->services->isNotEmpty() && !$this->services->first()->relationLoaded('category')) {
            $this->services->load('category');
        }
        
        // Calculate total photographer pay from services
        return (float) $this->services->sum(function ($service) {
            $photographerPay = $service->pivot->photographer_pay ?? null;
            $quantity = $service->pivot->quantity ?? 1;
            
            if ($photographerPay === null) {
                return 0;
            }
            
            return (float) $photographerPay * $quantity;
        });
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Ensure services.category is loaded for per-category grouping
        if (!$this->relationLoaded('services')) {
            $this->load('services.category');
        } elseif ($this->services->isNotEmpty() && !$this->services->first()->relationLoaded('category')) {
            $this->services->load('category');
        }

        $tourLinks = is_array($this->tour_links) ? $this->tour_links : [];
        $realtorClient = $this->resolveRealtorClient($tourLinks);
        if ($realtorClient) {
            $tourLinks['realtor_client'] = $realtorClient;
        }

        return [
            'id' => (string) $this->id,
            'client' => [
                'id' => (string) $this->client_id,
                'name' => $this->client?->name ?? 'Unknown',
                'email' => $this->client?->email ?? '',
            ],
            'rep' => $this->when($this->rep_id, function () {
                return [
                    'id' => (string) $this->rep_id,
                    'name' => $this->rep?->name ?? 'Unknown',
                ];
            }),
            'photographer' => $this->when($this->photographer_id, function () {
                return [
                    'id' => (string) $this->photographer_id,
                    'name' => $this->photographer?->name ?? 'Unassigned',
                ];
            }),
            'location' => [
                'address' => $this->address,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip,
                'fullAddress' => "{$this->address}, {$this->city}, {$this->state} {$this->zip}",
            ],
            // Batch-load unique per-service photographer IDs to avoid N+1 queries
            'services' => (function () {
                $servicePhotographerIds = $this->services
                    ->pluck('pivot.photographer_id')
                    ->filter()
                    ->unique()
                    ->values();
                $servicePhotographers = $servicePhotographerIds->isNotEmpty()
                    ? \App\Models\User::whereIn('id', $servicePhotographerIds)->get()->keyBy('id')
                    : collect();

                return $this->services->map(function ($service) use ($servicePhotographers) {
                // FALLBACK RULE: service.photographer_id ?? shoot.photographer_id
                $resolvedPhotographerId = $service->pivot->photographer_id ?? $this->photographer_id;
                $sqftRanges = $service->relationLoaded('sqftRanges')
                    ? $service->getRelation('sqftRanges')
                    : $service->sqftRanges()->get();

                // Resolve photographer details
                $resolvedPhotographer = null;
                if ($resolvedPhotographerId) {
                    // Try service-level photographer first (from batch-loaded collection)
                    if ($service->pivot->photographer_id) {
                        $photographer = $servicePhotographers->get($service->pivot->photographer_id);
                    } else {
                        // Fallback to shoot-level photographer
                        $photographer = $this->photographer;
                    }

                    if ($photographer) {
                        $resolvedPhotographer = [
                            'id' => (string) $photographer->id,
                            'name' => $photographer->name,
                            'avatar' => $photographer->avatar ?? null,
                        ];
                    }
                }
                
                return [
                    'id' => (string) $service->id,
                    'name' => $service->name,
                    'price' => (float) ($service->pivot->price ?? $service->price ?? 0),
                    'quantity' => (int) ($service->pivot->quantity ?? 1),
                    'pricing_type' => $service->pricing_type,
                    'photo_count' => $service->photo_count !== null ? (int) $service->photo_count : null,
                    'sqft_ranges' => $sqftRanges->map(fn($range) => [
                        'id' => $range->id,
                        'sqft_from' => (int) $range->sqft_from,
                        'sqft_to' => (int) $range->sqft_to,
                        'duration' => $range->duration !== null ? (int) $range->duration : null,
                        'price' => (float) $range->price,
                        'photographer_pay' => $range->photographer_pay !== null ? (float) $range->photographer_pay : null,
                        'photo_count' => $range->photo_count !== null ? (int) $range->photo_count : null,
                    ])->values()->all(),
                    'photographer_pay' => $service->pivot->photographer_pay ? (float) $service->pivot->photographer_pay : null,
                    // Raw pivot value (may be null)
                    'photographer_id' => $service->pivot->photographer_id ? (string) $service->pivot->photographer_id : null,
                    // RESOLVED value with fallback (frontend uses this)
                    'resolved_photographer_id' => $resolvedPhotographerId ? (string) $resolvedPhotographerId : null,
                    // Resolved photographer details (never null if shoot has photographer)
                    'photographer' => $resolvedPhotographer,
                    // Category info for per-category grouping
                    'category' => $service->category ? [
                        'id' => (string) $service->category->id,
                        'name' => $service->category->name,
                    ] : null,
                    'category_name' => $service->category?->name,
                ];
                });
            })(),
            // Explicitly include services_list for frontend compatibility
            'services_list' => $this->services->pluck('name')->filter()->values()->all(),
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'scheduledDate' => $this->scheduled_date?->toDateString(),
            'time' => $this->time,
            'completedAt' => $this->completed_at?->toIso8601String(),
            'status' => $this->status,
            'workflowStatus' => $this->workflow_status,
            'payment' => [
                'serviceSubtotal' => (float) (($this->base_quote ?? 0) + ($this->discount_amount ?? 0)),
                'baseQuote' => (float) $this->base_quote,
                'discountType' => $this->discount_type,
                'discountValue' => $this->discount_value !== null ? (float) $this->discount_value : null,
                'discountAmount' => (float) ($this->discount_amount ?? 0),
                'discountedSubtotal' => (float) $this->base_quote,
                'taxRegion' => $this->tax_region ?? 'none',
                'taxPercent' => (float) ($this->tax_percent ?? 0),
                'taxAmount' => (float) $this->tax_amount,
                'totalQuote' => (float) $this->total_quote,
                'totalPaid' => (float) $this->total_paid,
                'remainingBalance' => (float) $this->remaining_balance,
                'paymentStatus' => $this->payment_status,
            ],
            'photographerPay' => $this->calculatePhotographerPay(),
            'totalPhotographerPay' => $this->calculatePhotographerPay(),
            'photographer_pay' => $this->calculatePhotographerPay(), // Alternative key for compatibility
            'bypassPaywall' => (bool) $this->bypass_paywall,
            'createdBy' => $this->created_by_name ?? $this->created_by ?? 'Unknown',
            'createdAt' => $this->created_at->toIso8601String(),
            'cancellationRequestedAt' => $this->cancellation_requested_at?->toIso8601String(),
            'cancellationReason' => $this->cancellation_reason,
            'holdRequestedAt' => $this->hold_requested_at?->toIso8601String(),
            'holdRequestedBy' => $this->hold_requested_by,
            'holdReason' => $this->hold_reason,
            'property_details' => $this->property_details,
            'tour_links' => $tourLinks,
            'realtor_client' => $realtorClient,
            'iguide_tour_url' => $this->iguide_tour_url,
            'iguide_floorplans' => $this->iguide_floorplans ?? [],
            'iguide_last_synced_at' => $this->iguide_last_synced_at?->toIso8601String(),
            'iguide_property_id' => $this->iguide_property_id,
            'is_private_listing' => (bool) ($this->is_private_listing ?? false),
            'isPrivateListing' => (bool) ($this->is_private_listing ?? false),
            'is_featured' => (bool) ($this->is_featured ?? false),
            'isFeatured' => (bool) ($this->is_featured ?? false),
            'listing_type' => $this->listing_type,
            'listingType' => $this->listing_type,
            'property_status' => $this->property_status ?? 'available',
            'propertyStatus' => $this->property_status ?? 'available',
            'photographerPaidAt' => $this->photographer_paid_at?->toIso8601String(),
            'photographerPaidInvoiceId' => $this->photographer_paid_invoice_id,
            'salesRepPaidAt' => $this->sales_rep_paid_at?->toIso8601String(),
            'salesRepPaidInvoiceId' => $this->sales_rep_paid_invoice_id,
        ];
    }

    protected function resolveRealtorClient(array $tourLinks): ?array
    {
        $realtorClientId = $tourLinks['realtor_client_id'] ?? $tourLinks['realtorClientId'] ?? null;
        if (!$realtorClientId) {
            return null;
        }

        $client = User::query()
            ->where('role', 'client')
            ->find($realtorClientId);

        if (!$client) {
            return null;
        }

        return [
            'id' => (string) $client->id,
            'name' => $client->name ?? 'Client',
            'email' => $client->email ?? null,
            'company' => $client->company_name ?? $client->company ?? null,
        ];
    }
}

