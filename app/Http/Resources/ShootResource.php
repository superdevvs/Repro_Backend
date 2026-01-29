<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShootResource extends JsonResource
{
    /**
     * Calculate total photographer pay from services
     */
    protected function calculatePhotographerPay(): float
    {
        // Ensure services are loaded
        if (!$this->relationLoaded('services')) {
            $this->load('services');
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
        return [
            'id' => (string) $this->id,
            'client' => [
                'id' => (string) $this->client_id,
                'name' => $this->client->name ?? 'Unknown',
                'email' => $this->client->email ?? '',
            ],
            'rep' => $this->when($this->rep_id, [
                'id' => (string) $this->rep_id,
                'name' => $this->rep->name ?? 'Unknown',
            ]),
            'photographer' => $this->when($this->photographer_id, [
                'id' => (string) $this->photographer_id,
                'name' => $this->photographer->name ?? 'Unassigned',
            ]),
            'location' => [
                'address' => $this->address,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip,
                'fullAddress' => "{$this->address}, {$this->city}, {$this->state} {$this->zip}",
            ],
            'services' => $this->services->map(function ($service) {
                return [
                    'id' => (string) $service->id,
                    'name' => $service->name,
                    'price' => (float) ($service->pivot->price ?? $service->price ?? 0),
                    'quantity' => (int) ($service->pivot->quantity ?? 1),
                    'photographer_pay' => $service->pivot->photographer_pay ? (float) $service->pivot->photographer_pay : null,
                ];
            }),
            // Explicitly include services_list for frontend compatibility
            'services_list' => $this->services->pluck('name')->filter()->values()->all(),
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'scheduledDate' => $this->scheduled_date?->toDateString(),
            'time' => $this->time,
            'completedAt' => $this->completed_at?->toIso8601String(),
            'status' => $this->status,
            'workflowStatus' => $this->workflow_status,
            'payment' => [
                'baseQuote' => (float) $this->base_quote,
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
            'property_details' => $this->property_details,
            'tour_links' => $this->tour_links ?? [],
            'iguide_tour_url' => $this->iguide_tour_url,
            'iguide_floorplans' => $this->iguide_floorplans ?? [],
            'iguide_last_synced_at' => $this->iguide_last_synced_at?->toIso8601String(),
            'iguide_property_id' => $this->iguide_property_id,
            'is_private_listing' => (bool) ($this->is_private_listing ?? false),
            'isPrivateListing' => (bool) ($this->is_private_listing ?? false),
        ];
    }
}

