<?php

namespace App\Services\Shoots;

/** Reviewed financial products; a fee-like name or absent intake never grants an exemption. */
final class ReviewedTravelFeeCatalog
{
    public const PRODUCTS = [
        61 => 'Travel fee - 60 miles',
        62 => 'Travel fee - 90 Miles',
        63 => 'Travel fee - 100 miles',
        64 => 'Travel Fee - 120 miles',
    ];

    public static function assertIdentity(object $service): void
    {
        $expected = self::PRODUCTS[(int) $service->id] ?? null;
        if ($expected === null || $service->name !== $expected || $service->upload_intake_type !== 'none'
            || (bool) $service->photographer_required || (bool) $service->is_migration_only) {
            throw new \RuntimeException('The reviewed travel fee catalog identity changed; review it before classification.');
        }
    }
}
