<?php

namespace App\Support;

use App\Models\Service;

final class ServiceDiscountEligibility
{
    /** Fees remain payable in full, including older catalogue rows without financial_only. */
    public static function allows(Service $service): bool
    {
        return ! $service->financial_only
            && ! preg_match('/\b(?:travel|mileage|cancell?ation)\b|\bhold\s+fee\b/i', (string) $service->name);
    }
}
