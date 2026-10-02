<?php

namespace App\Services\Schedule;

use App\Models\Shoot;
use App\Models\ShootService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Services own the booking schedule. Shoot-level scheduled_at / date / time are
 * derived from the earliest scheduled service line.
 */
class ShootScheduleFromServices
{
    /**
     * Earliest non-null scheduled_at among the given service rows or arrays.
     *
     * @param  iterable<int, ShootService|array|object>  $items
     */
    public function earliestInstant(iterable $items): ?Carbon
    {
        $earliest = null;

        foreach ($items as $item) {
            $raw = $item instanceof ShootService
                ? $item->scheduled_at
                : (data_get($item, 'scheduled_at') ?? data_get($item, 'pivot.scheduled_at'));

            if ($raw === null || $raw === '') {
                continue;
            }

            $instant = $raw instanceof Carbon
                ? $raw->copy()->utc()
                : Carbon::parse((string) $raw, 'UTC');

            if ($earliest === null || $instant->lt($earliest)) {
                $earliest = $instant;
            }
        }

        return $earliest;
    }

    /**
     * Shoot fields derived from an absolute instant in the shoot timezone.
     *
     * @return array{scheduled_at: string, scheduled_date: string, time: string}
     */
    public function shootFieldsFromInstant(Carbon $instant, ?string $timezone): array
    {
        $timezone = trim((string) $timezone);
        $utc = $instant->copy()->utc();
        $local = $timezone !== ''
            ? $utc->copy()->setTimezone($timezone)
            : $utc->copy();

        return [
            'scheduled_at' => $utc->toIso8601String(),
            'scheduled_date' => $local->toDateString(),
            'time' => $local->format('H:i:s'),
        ];
    }

    /**
     * Apply earliest-service schedule onto the shoot model (caller saves).
     */
    public function applyEarliestToShoot(Shoot $shoot, ?iterable $items = null): ?Carbon
    {
        $items ??= $shoot->relationLoaded('serviceItems')
            ? $shoot->serviceItems
            : $shoot->serviceItems()->get();

        $earliest = $this->earliestInstant($items);
        if (! $earliest) {
            return null;
        }

        $fields = $this->shootFieldsFromInstant($earliest, $shoot->timezone);
        $shoot->scheduled_at = Carbon::parse($fields['scheduled_at'])->utc();
        $shoot->scheduled_date = $fields['scheduled_date'];
        $shoot->time = $fields['time'];

        return $earliest;
    }

    /**
     * Deliverable service lines that participate in the booking schedule.
     * Non-deliverable fee lines (and intentional null schedules) are excluded.
     *
     * @return Collection<int, ShootService>
     */
    public function bookingDefiningItems(Shoot $shoot): Collection
    {
        $items = $shoot->relationLoaded('serviceItems')
            ? $shoot->serviceItems
            : $shoot->serviceItems()->get();

        return $items->filter(function (ShootService $item) {
            if ($item->is_deliverable === false) {
                return false;
            }

            return $item->scheduled_at !== null;
        })->values();
    }

    /**
     * Plan a booking move without writing, so availability checks and persistence
     * use identical target times.
     * Shared-time (or shoot-matching / null) lines adopt the instant; split
     * appointments shift by the same delta from the previous shoot instant.
     *
     * @return array<int, Carbon>
     */
    public function plannedBookingSchedules(Shoot $shoot, Carbon $instant): array
    {
        $target = $instant->copy()->utc();
        $defining = $this->bookingDefiningItems($shoot);
        $nullDeliverable = ($shoot->relationLoaded('serviceItems')
            ? $shoot->serviceItems
            : $shoot->serviceItems()->get()
        )->filter(fn (ShootService $item) => $item->scheduled_at === null && $item->is_deliverable !== false);

        $stamps = $defining->map(fn (ShootService $item) => $item->scheduled_at?->format('Y-m-d H:i:s'))
            ->filter()
            ->unique()
            ->values();

        $shootStamp = $shoot->scheduled_at?->format('Y-m-d H:i:s');
        $sharedOrMatched = $stamps->count() <= 1
            || ($shootStamp && $defining->every(
                fn (ShootService $item) => $item->scheduled_at?->format('Y-m-d H:i:s') === $shootStamp
            ));

        if ($sharedOrMatched || $defining->isEmpty()) {
            return $defining->merge($nullDeliverable)
                ->mapWithKeys(fn (ShootService $item) => [$item->id => $target->copy()])->all();
        }

        // Split appointments: preserve relative offsets from the prior shoot anchor.
        $anchor = $shoot->scheduled_at?->copy()->utc()
            ?? $this->earliestInstant($defining);
        $deltaSeconds = $anchor ? ($target->getTimestamp() - $anchor->getTimestamp()) : 0;

        return $defining->mapWithKeys(fn (ShootService $item) => [
            $item->id => $item->scheduled_at->copy()->utc()->addSeconds($deltaSeconds),
        ])->union($nullDeliverable->mapWithKeys(fn (ShootService $item) => [$item->id => $target->copy()]))->all();
    }

    public function alignBookingDefiningServices(Shoot $shoot, Carbon $instant): int
    {
        $planned = $this->plannedBookingSchedules($shoot, $instant);
        if ($planned === []) {
            return 0;
        }
        if (collect($planned)->map(fn (Carbon $time) => $time->format('Y-m-d H:i:s'))->unique()->count() === 1) {
            return ShootService::query()->where('shoot_id', $shoot->id)->whereIn('id', array_keys($planned))
                ->update([
                    'scheduled_at' => reset($planned)->format('Y-m-d H:i:s'),
                    'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
                    'updated_at' => now(),
                ]);
        }
        foreach ($shoot->serviceItems as $item) {
            if (! isset($planned[$item->id])) {
                continue;
            }
            $item->forceFill([
                'scheduled_at' => $planned[$item->id]->format('Y-m-d H:i:s'),
                'workflow_status' => ShootService::WORKFLOW_SCHEDULED,
            ])->save();
        }

        return count($planned);
    }
}
