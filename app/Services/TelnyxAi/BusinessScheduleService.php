<?php

namespace App\Services\TelnyxAi;

use App\Models\VoiceScheduleOverride;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Hybrid scheduling service combining business hours, holidays, ad-hoc
 * overrides, and SMS-style quiet hours. Used by the voice routing layer to
 * decide what Robbie says when a caller asks for a human, and by the dashboard
 * to render the live schedule badge.
 */
class BusinessScheduleService
{
    public const STATE_TEAM_OPEN = 'team_open';
    public const STATE_AI_ONLY = 'ai_only';
    public const STATE_HOLIDAY_CLOSED = 'holiday_closed';
    public const STATE_QUIET_HOURS = 'quiet_hours';
    public const STATE_OVERRIDE_OPEN = 'override_open';
    public const STATE_OVERRIDE_CLOSED = 'override_closed';

    private const WEEKDAY_KEYS = [
        0 => 'sunday',
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
    ];

    public function __construct(private readonly VoiceSettingsService $settings)
    {
    }

    /**
     * Return the active schedule state, optionally evaluated in the caller's
     * timezone for natural phrasing ("Our office opens in 2 hours").
     *
     * @return array{state:string,label:?string,since:?string,until:?string,next_open_at:?string,office_timezone:string,caller_timezone:?string}
     */
    public function currentState(?Carbon $now = null, ?string $callerTimezone = null): array
    {
        $config = $this->settings->all();
        $officeTz = (string) ($config['business_hours']['timezone'] ?? config('app.timezone', 'UTC'));
        $now = $now ? $now->copy() : Carbon::now($officeTz);
        $now->setTimezone($officeTz);

        $override = $this->matchOverride($this->allOverrides($config), $now, $officeTz);
        if ($override) {
            $state = $override['mode'] === 'open' ? self::STATE_OVERRIDE_OPEN : self::STATE_OVERRIDE_CLOSED;
            return $this->payload($state, $override['label'] ?? null, $override['starts_at_dt'], $override['ends_at_dt'], $now, $callerTimezone, $officeTz, $config);
        }

        $holiday = $this->matchHoliday($config['holidays'] ?? [], $now);
        if ($holiday) {
            $start = $now->copy()->startOfDay();
            $end = $now->copy()->endOfDay();
            return $this->payload(self::STATE_HOLIDAY_CLOSED, $holiday['label'] ?? $holiday['date'], $start, $end, $now, $callerTimezone, $officeTz, $config);
        }

        if ($this->isInQuietHours($config['quiet_hours'] ?? [], $now)) {
            return $this->payload(self::STATE_QUIET_HOURS, null, null, null, $now, $callerTimezone, $officeTz, $config);
        }

        $window = $this->todayOpenWindow($config['business_hours']['weekly'] ?? [], $now, $officeTz);
        if ($window) {
            return $this->payload(self::STATE_TEAM_OPEN, null, $window[0], $window[1], $now, $callerTimezone, $officeTz, $config);
        }

        return $this->payload(self::STATE_AI_ONLY, null, null, null, $now, $callerTimezone, $officeTz, $config);
    }

    /**
     * Returns true if the operator should treat the team as available for a
     * live transfer.
     */
    public function isTeamAvailable(?Carbon $now = null): bool
    {
        $state = $this->currentState($now)['state'] ?? null;
        return in_array($state, [self::STATE_TEAM_OPEN, self::STATE_OVERRIDE_OPEN], true);
    }

    /**
     * Return the next moment the team opens, used by callback scheduling.
     */
    public function nextBusinessSlot(?Carbon $now = null): Carbon
    {
        $config = $this->settings->all();
        $officeTz = (string) ($config['business_hours']['timezone'] ?? config('app.timezone', 'UTC'));
        $cursor = $now ? $now->copy()->setTimezone($officeTz) : Carbon::now($officeTz);

        for ($i = 0; $i < 14; $i++) {
            $candidate = $cursor->copy()->addDays($i);
            if ($i > 0) {
                $candidate->setTime(0, 0, 0);
            }
            $window = $this->todayOpenWindow($config['business_hours']['weekly'] ?? [], $candidate, $officeTz);
            if (!$window) {
                continue;
            }
            $start = $window[0];
            if ($start->lessThanOrEqualTo($cursor)) {
                continue;
            }
            if ($this->matchHoliday($config['holidays'] ?? [], $start)) {
                continue;
            }
            return $start;
        }

        return $cursor->copy()->addDay()->setTime(9, 0, 0);
    }

    public function inQuietHours(?Carbon $now = null): bool
    {
        $config = $this->settings->all();
        $tz = (string) ($config['quiet_hours']['timezone'] ?? config('app.timezone', 'UTC'));
        $now = $now ? $now->copy()->setTimezone($tz) : Carbon::now($tz);
        return $this->isInQuietHours($config['quiet_hours'] ?? [], $now);
    }

    private function isInQuietHours(array $quiet, Carbon $now): bool
    {
        if (empty($quiet['enabled'])) {
            return false;
        }
        $tz = (string) ($quiet['timezone'] ?? $now->getTimezone()?->getName() ?? 'UTC');
        $local = $now->copy()->setTimezone($tz);
        $start = (string) ($quiet['start'] ?? '20:00');
        $end = (string) ($quiet['end'] ?? '08:00');
        return $this->timeWithin($local->format('H:i'), $start, $end);
    }

    private function timeWithin(string $hhmm, string $start, string $end): bool
    {
        if ($start === $end) {
            return false;
        }
        if ($start < $end) {
            return $hhmm >= $start && $hhmm < $end;
        }
        // Wrap-around (e.g. 20:00 -> 08:00 next day)
        return $hhmm >= $start || $hhmm < $end;
    }

    /**
     * @return array{0:Carbon,1:Carbon}|null
     */
    private function todayOpenWindow(array $weekly, Carbon $local, string $officeTz): ?array
    {
        $key = self::WEEKDAY_KEYS[$local->dayOfWeek] ?? null;
        if (!$key) {
            return null;
        }
        $windows = $weekly[$key] ?? [];
        $hhmm = $local->format('H:i');
        foreach ($windows as $window) {
            if (!is_array($window) || count($window) < 2) {
                continue;
            }
            [$open, $close] = [(string) $window[0], (string) $window[1]];
            if ($hhmm >= $open && $hhmm < $close) {
                $start = Carbon::createFromFormat('Y-m-d H:i', $local->format('Y-m-d') . ' ' . $open, $officeTz) ?: $local->copy();
                $end = Carbon::createFromFormat('Y-m-d H:i', $local->format('Y-m-d') . ' ' . $close, $officeTz) ?: $local->copy();
                return [$start, $end];
            }
        }
        // Find the next future window today (used by nextBusinessSlot when iterating future days from start of day)
        foreach ($windows as $window) {
            if (!is_array($window) || count($window) < 2) {
                continue;
            }
            [$open, $close] = [(string) $window[0], (string) $window[1]];
            if ($hhmm < $open) {
                $start = Carbon::createFromFormat('Y-m-d H:i', $local->format('Y-m-d') . ' ' . $open, $officeTz) ?: $local->copy();
                $end = Carbon::createFromFormat('Y-m-d H:i', $local->format('Y-m-d') . ' ' . $close, $officeTz) ?: $local->copy();
                return [$start, $end];
            }
        }
        return null;
    }

    private function matchHoliday(array $holidays, Carbon $local): ?array
    {
        $today = $local->format('Y-m-d');
        foreach ($holidays as $holiday) {
            if (!is_array($holiday)) {
                continue;
            }
            if (($holiday['date'] ?? null) === $today) {
                return $holiday;
            }
        }
        return null;
    }

    private function matchOverride(array $overrides, Carbon $now, string $officeTz): ?array
    {
        foreach ($overrides as $override) {
            if (!is_array($override)) {
                continue;
            }
            try {
                $start = CarbonImmutable::parse((string) ($override['starts_at'] ?? ''))->setTimezone($officeTz);
                $end = CarbonImmutable::parse((string) ($override['ends_at'] ?? ''))->setTimezone($officeTz);
            } catch (\Throwable $e) {
                continue;
            }
            if ($now->between(Carbon::instance($start), Carbon::instance($end))) {
                return [
                    'mode' => (string) ($override['mode'] ?? 'closed'),
                    'label' => $override['label'] ?? null,
                    'starts_at_dt' => Carbon::instance($start),
                    'ends_at_dt' => Carbon::instance($end),
                ];
            }
        }
        return null;
    }

    /**
     * Merge settings-defined overrides (JSON) with DB-backed overrides from the
     * voice_schedule_overrides table. DB rows take precedence by being appended
     * last so an explicit "Add override now" wins recency ties.
     *
     * @return array<int,array<string,mixed>>
     */
    private function allOverrides(array $config): array
    {
        $fromSettings = is_array($config['schedule_overrides'] ?? null) ? $config['schedule_overrides'] : [];

        $fromDb = [];
        try {
            $fromDb = VoiceScheduleOverride::query()
                ->orderBy('starts_at')
                ->get()
                ->map(fn (VoiceScheduleOverride $o) => [
                    'starts_at' => optional($o->starts_at)->toIso8601String(),
                    'ends_at' => optional($o->ends_at)->toIso8601String(),
                    'mode' => $o->mode,
                    'label' => $o->label,
                ])
                ->all();
        } catch (\Throwable $e) {
            $fromDb = [];
        }

        return array_merge($fromSettings, $fromDb);
    }

    /**
     * Provide the message + transfer behaviour Robbie should adopt for the
     * current schedule state. Used by the voice routing layer.
     *
     * @return array{state:string,allow_live_transfer:bool,message:string,label:?string,next_open_at:?string}
     */
    public function robbieScheduleGuidance(?Carbon $now = null, ?string $callerTimezone = null): array
    {
        $config = $this->settings->all();
        $snapshot = $this->currentState($now, $callerTimezone);
        $state = $snapshot['state'];
        $label = $snapshot['label'];

        $outOfHours = (string) ($config['out_of_hours_message']
            ?? 'Our team is offline right now, but I can schedule a callback for the next business morning.');
        $holidayTemplate = (string) ($config['holiday_message']
            ?? 'Our office is closed today for {holiday_label}. I can help you now or schedule a callback.');

        $message = match ($state) {
            self::STATE_TEAM_OPEN, self::STATE_OVERRIDE_OPEN => 'I can connect you to the team right now.',
            self::STATE_HOLIDAY_CLOSED => str_replace('{holiday_label}', (string) ($label ?: 'a holiday'), $holidayTemplate),
            self::STATE_OVERRIDE_CLOSED => $label
                ? "We're closed right now ({$label}), but I can help, or schedule a callback."
                : $outOfHours,
            self::STATE_QUIET_HOURS, self::STATE_AI_ONLY => $outOfHours,
            default => $outOfHours,
        };

        return [
            'state' => $state,
            'allow_live_transfer' => in_array($state, [self::STATE_TEAM_OPEN, self::STATE_OVERRIDE_OPEN], true),
            'message' => $message,
            'label' => $label,
            'next_open_at' => $snapshot['next_open_at'] ?? null,
        ];
    }

    /**
     * @return array{state:string,label:?string,since:?string,until:?string,next_open_at:?string,office_timezone:string,caller_timezone:?string,office_now:string,caller_now:?string}
     */
    private function payload(string $state, ?string $label, ?Carbon $since, ?Carbon $until, Carbon $now, ?string $callerTz, string $officeTz, array $config): array
    {
        $nextOpen = in_array($state, [self::STATE_TEAM_OPEN, self::STATE_OVERRIDE_OPEN], true)
            ? null
            : $this->nextBusinessSlot($now);

        $callerNow = null;
        if ($callerTz) {
            try {
                $callerNow = $now->copy()->setTimezone($callerTz)->toIso8601String();
            } catch (\Throwable $e) {
                $callerTz = null;
            }
        }

        return [
            'state' => $state,
            'label' => $label,
            'since' => $since?->toIso8601String(),
            'until' => $until?->toIso8601String(),
            'next_open_at' => $nextOpen?->toIso8601String(),
            'office_timezone' => $officeTz,
            'caller_timezone' => $callerTz,
            'office_now' => $now->toIso8601String(),
            'caller_now' => $callerNow,
        ];
    }
}
