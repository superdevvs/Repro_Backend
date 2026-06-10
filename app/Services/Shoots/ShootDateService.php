<?php

namespace App\Services\Shoots;

use App\Models\Shoot;

/**
 * ShootDateService centralizes timezone-correct handling of shoot dates so the
 * intended local calendar day is preserved end to end (Req 9).
 *
 * Storage model (see design "2. Timezone-correct shoot dates"):
 * - `scheduled_at` is stored as an absolute instant (UTC in the DB).
 * - `timezone` (IANA, e.g. America/New_York) is the shoot's local zone,
 *   defaulting to the configured app timezone when absent.
 * - `scheduled_date` is the local calendar day (Y-m-d) in `timezone` and is the
 *   source of truth for "which day this shoot belongs to".
 */
class ShootDateService
{
    /**
     * Return the local calendar day (Y-m-d) for the shoot in its own timezone.
     *
     * When an absolute `scheduled_at` instant is present it is converted to the
     * shoot's timezone before formatting, so the calendar day never shifts to
     * the UTC day. Otherwise the already-local `scheduled_date` is returned.
     */
    public function localCalendarDate(Shoot $shoot): string
    {
        $tz = $shoot->timezone ?: config('app.timezone');

        if ($shoot->scheduled_at) {
            return $shoot->scheduled_at->copy()->setTimezone($tz)->format('Y-m-d');
        }

        // scheduled_date is already a local Y-m-d. The model casts it to a date,
        // so normalize to a plain Y-m-d string regardless of cast type.
        $scheduledDate = $shoot->scheduled_date;
        if ($scheduledDate instanceof \DateTimeInterface) {
            return $scheduledDate->format('Y-m-d');
        }

        return (string) $scheduledDate;
    }

    /**
     * Serialized shape returned to the Dashboard. `scheduled_date` is the local
     * calendar day and never shifts across timezones.
     *
     * @return array{scheduled_date: string, scheduled_at: ?string, timezone: string}
     */
    public function toApi(Shoot $shoot): array
    {
        $tz = $shoot->timezone ?: config('app.timezone');

        return [
            'scheduled_date' => $this->localCalendarDate($shoot), // Y-m-d, never shifts
            'scheduled_at'   => optional($shoot->scheduled_at)->toIso8601String(),
            'timezone'       => $tz,
        ];
    }
}
