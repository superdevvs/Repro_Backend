<?php

namespace App\Services\ExternalBooking;

use App\Models\Service;
use Illuminate\Validation\ValidationException;

final class ExternalBookingPricing
{
    /** Browser-supplied prices are never used. */
    public function resolve(array $services, ?int $sqft): array
    {
        $catalog = Service::with('sqftRanges')->whereIn('id', array_column($services, 'id'))->get()->keyBy('id');

        foreach ($services as $index => &$line) {
            $service = $catalog->get($line['id']);
            if (! $service) {
                throw ValidationException::withMessages(["services.$index.id" => ['The selected service is no longer available.']]);
            }
            $line['price'] = (float) $service->price;

            // Legacy callers may omit sqft. Fixed-price services never use tiers.
            if ($service->pricing_type !== 'variable' || $sqft === null || $service->sqftRanges->isEmpty()) {
                continue;
            }
            $ranges = $service->sqftRanges->filter(fn ($range) => $range->sqft_from <= $sqft && $range->sqft_to >= $sqft);
            if ($ranges->count() !== 1) {
                throw ValidationException::withMessages([
                    'sqft' => ["No unambiguous price is available for {$service->name} at this square footage. Please contact us for a quote."],
                ]);
            }
            $line['price'] = (float) $ranges->first()->price;
        }
        unset($line);

        return $services;
    }
}
