<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShootResource extends JsonResource
{
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
                ];
            }),
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
            'bypassPaywall' => (bool) $this->bypass_paywall,
            'createdBy' => $this->created_by,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}

