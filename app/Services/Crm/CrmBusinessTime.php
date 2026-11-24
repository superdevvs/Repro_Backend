<?php

namespace App\Services\Crm;

use Illuminate\Support\Carbon;

final class CrmBusinessTime
{
    public const TIMEZONE = 'America/New_York';

    public static function firstResponseDue(?Carbon $receivedAt = null): Carbon
    {
        return ($receivedAt ?? now())->copy()
            ->setTimezone(self::TIMEZONE)
            ->addWeekday()
            ->setTimezone(config('app.timezone'));
    }

    public static function startOfDay(): Carbon
    {
        return now(self::TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'));
    }

    public static function endOfDay(): Carbon
    {
        return now(self::TIMEZONE)->endOfDay()->setTimezone(config('app.timezone'));
    }
}
