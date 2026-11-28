<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;

final class StoredTimestamp
{
    public static function seconds(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->format('Y-m-d H:i:s');
    }
}
