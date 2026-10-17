<?php

namespace App\Services\Messaging;

use Carbon\Carbon;

/** Client email times are local calendar times; staff and SMS retain their offset. */
class ShootAppointmentReminderSchedule
{
    public const CLIENT_DEFAULTS = [
        'previous_day_time' => '09:00',
        'day_of_time' => '09:00',
        'morning_start' => '09:00',
        'morning_end' => '11:00',
        'morning_previous_evening_time' => '19:00',
        'morning_lead_minutes' => 120,
    ];

    /** @return array<string, array{at: Carbon, audience: string|null}> */
    public function occurrences(Carbon $appointment, array $schedule, bool $clientReminder): array
    {
        $client = $clientReminder && is_array($schedule['client_email_schedule'] ?? null)
            ? array_replace(self::CLIENT_DEFAULTS, $schedule['client_email_schedule']) : null;
        if ($client) {
            // Saved rules cannot widen the early-morning exception or precede 9am.
            foreach (['previous_day_time', 'day_of_time'] as $key) {
                $client[$key] = max('09:00', $client[$key]);
            }
            $client['morning_start'] = '09:00';
            $client['morning_end'] = '11:00';
            $client['morning_lead_minutes'] = 120;
        }
        $occurrences = [];
        $offset = $schedule['offset'] ?? ($clientReminder ? '-24h' : '-2h');
        if (preg_match('/^-(\d+)([mhd])$/', (string) $offset, $matches)) {
            $minutes = (int) $matches[1] * match ($matches[2]) { 'd' => 1440, 'h' => 60, default => 1 };
            $occurrences['offset'] = ['at' => $appointment->copy()->subMinutes($minutes), 'audience' => $client ? 'legacy' : null];
        }
        if (! $client) {
            return $occurrences;
        }

        $day = $appointment->copy()->startOfDay();
        $occurrences['client_previous_day'] = [
            'at' => $day->copy()->subDay()->setTimeFromTimeString($client['previous_day_time']), 'audience' => 'client_email',
        ];
        $clock = $appointment->format('H:i');
        // Only appointments from 9am through 11am get the two-hour early reminder.
        if ($clock >= $client['morning_start'] && $clock <= $client['morning_end']) {
            $occurrences['client_previous_evening'] = [
                'at' => $day->copy()->subDay()->setTimeFromTimeString($client['morning_previous_evening_time']), 'audience' => 'client_email',
            ];
            $occurrences['client_before_shoot'] = [
                'at' => $appointment->copy()->subMinutes((int) $client['morning_lead_minutes']), 'audience' => 'client_email',
            ];
        } else {
            $occurrences['client_day_of'] = [
                'at' => $day->copy()->setTimeFromTimeString($client['day_of_time']), 'audience' => 'client_email',
            ];
        }

        return $occurrences;
    }
}
