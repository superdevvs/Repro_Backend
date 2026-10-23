<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AryeoRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['match_verification'];

    protected $casts = ['discovery' => 'array', 'inventory' => 'array', 'inventory_checked_at' => 'datetime', 'match_verification' => 'array'];

    public function hasVerifiedProviderDelivery(): bool
    {
        $status = $this->inventory['provider_status'] ?? null;
        if (! is_array($status) || ($status['origin'] ?? null) !== 'aryeo_provider'
            || ($status['delivery_status'] ?? null) !== 'delivered'
            || ! $this->request_id || ($status['request_id'] ?? null) !== $this->request_id
            || ! $this->listing_id || ($status['listing_id'] ?? null) !== $this->listing_id
            || empty($this->discovery['requester_email'])
            || strtolower(trim($status['requester_email'] ?? '')) !== strtolower(trim($this->discovery['requester_email']))) {
            return false;
        }
        try {
            return ! empty($status['checked_at']) && \Illuminate\Support\Carbon::parse($status['checked_at'])->lte(now()->addMinute());
        } catch (\Throwable) {
            return false;
        }
    }
}
