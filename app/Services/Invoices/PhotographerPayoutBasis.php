<?php

namespace App\Services\Invoices;

use App\Models\Service;
use App\Models\Shoot;
use Illuminate\Database\Eloquent\Model;

/** The booked price/rate is evidence; catalog changes must not rewrite it. */
final class PhotographerPayoutBasis
{
    public function capture(Model $line, Shoot $shoot, Service $service, bool $legacy = false): array
    {
        $before = $line->payout_snapshot;
        if (is_string($before)) $before = json_decode($before, true);
        $price = (float) ($line->price ?? $service->price);
        $sqft = $line->shoot_unit_id ? $shoot->units()->find($line->shoot_unit_id)?->sqft : $shoot->propertySqft();
        $tier = $sqft && $service->pricing_type === 'variable'
            ? $service->sqftRanges()->where('sqft_from', '<=', $sqft)->where('sqft_to', '>=', $sqft)->first() : null;
        $source = $tier ?? $service;
        $override = $line->photographer_pay;
        $percent = $source->photographer_pay_type === Service::PAY_TYPE_PERCENT
            ? $source->photographer_pay_percent : null;
        // A custom booked price on a percentage service still uses the agreed
        // percentage, not today's catalogue price or a newly inferred tier.
        if ($percent === null && $service->photographer_pay_type === Service::PAY_TYPE_PERCENT
            && abs($price - (float) ($source->price ?? $service->price)) > 0.005) {
            $percent = $service->photographer_pay_percent;
        }
        $unitPay = $override !== null ? (float) $override
            : ($percent !== null ? round($price * (float) $percent / 100, 2) : $service->getPhotographerPayForSqft($sqft));
        $basis = [
            'version' => 1, 'service_id' => $service->id, 'service_name' => $service->name,
            'booked_price' => round($price, 2), 'quantity' => (int) ($line->quantity ?? 1),
            'unit_pay' => $unitPay, 'pay_type' => $override !== null ? 'override' : ($percent !== null ? 'percent' : 'fixed'),
            'override' => $override !== null ? (float) $override : null,
            'percent' => $percent !== null ? (float) $percent : null,
            'photo_count' => $line->contracted_photo_count ?? $tier?->photo_count ?? $service->photo_count,
            'sqft' => $sqft, 'source' => $legacy ? 'legacy_booking_freeze' : 'booking',
            'captured_at' => now()->toIso8601String(),
        ];
        if ($before && $line->exists) {
            $basis['previous_basis'] = collect($before)->except(['previous_basis'])->all();
            $basis['review_required'] = true;
        }
        if ($line->contracted_photo_count && $tier?->photo_count
            && (int) $line->contracted_photo_count !== (int) $tier->photo_count) {
            $basis['review_required'] = true;
            $basis['warning'] = 'Booked photo count differs from the configured pricing tier. Verify the agreed package.';
        }
        $line->payout_snapshot = $basis;
        return $basis;
    }

    public function forLine(Model $line, Shoot $shoot, Service $service): array
    {
        $basis = $line->payout_snapshot;
        if (is_string($basis)) $basis = json_decode($basis, true);
        // Imports/administrative SQL can bypass model hooks. A changed booking
        // must be flagged as changed evidence rather than silently using a stale
        // override. Catalogue-only changes never satisfy these comparisons.
        if ($basis && ((int) ($basis['service_id'] ?? 0) !== (int) $service->id
            || ($line->price !== null && abs((float)$basis['booked_price'] - (float)$line->price) > 0.005)
            || (int) ($basis['quantity'] ?? 1) !== (int) ($line->quantity ?? 1)
            || ($basis['override'] ?? null) !== ($line->photographer_pay !== null ? (float)$line->photographer_pay : null))) {
            return $this->capture($line, $shoot, $service);
        }
        return $basis ?: $this->capture($line, $shoot, $service, true);
    }

    public function amount(Model $line, Shoot $shoot, Service $service): float
    {
        $basis = $this->forLine($line, $shoot, $service);
        return round((float) ($basis['unit_pay'] ?? 0) * (int) ($line->quantity ?? 1), 2);
    }

    /** A capture is complete before editing/client delivery, including floorplans. */
    public function completion(Shoot $shoot, Model $line): ?string
    {
        if ($line->cancelled_at || in_array($line->workflow_status, ['cancelled', 'declined'], true)
            || in_array($shoot->workflow_status, ['cancelled', 'declined', 'on_hold'], true)) return null;
        foreach ([$line->delivered_at, $line->ready_at, $shoot->completed_at, $shoot->admin_verified_at] as $date) {
            if ($date) return (string) $date;
        }
        if (! in_array($shoot->workflow_status, ['uploaded', 'editing', 'review', 'ready', 'delivered'], true)
            || ! $shoot->photos_uploaded_at) return null;
        $recipients = $shoot->services->map(fn ($s) => $s->pivot->photographer_id ?? $shoot->photographer_id)->filter()->unique();
        // Whole-order submission establishes capture completion for all services
        // of one photographer (including a floorplan without image files).
        // A partial upload for another artist must never qualify this artist.
        if ($recipients->count() <= 1) return (string) $shoot->photos_uploaded_at;
        $hasOwnFiles = $shoot->relationLoaded('files')
            ? $shoot->files->contains(fn ($f) => (int) $f->shoot_service_id === (int) $line->id && $f->media_type === 'raw')
            : $shoot->files()->where('shoot_service_id', $line->id)->where('media_type', 'raw')->exists();
        return $hasOwnFiles ? (string) $shoot->photos_uploaded_at : null;
    }
}
