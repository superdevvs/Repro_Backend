<?php

namespace App\Services\Scheduling;

use App\Jobs\ProcessUpdatedShootSideEffectsJob;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarSyncDispatcher;
use App\Services\MailService;
use App\Services\Schedule\ScheduleDateScopeService;
use App\Services\ShootActivityLogger;
use App\Services\Shoots\ShootAuthorizationSupport;
use App\Services\Shoots\ShootManagementAccess;
use App\Services\Shoots\ShootDurationResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Neighbor changes share the target booking's feasibility, locks and transaction. */
class DaySchedulePlan
{
    public static function rules(): array
    {
        return [
            'schedule_adjustments' => 'sometimes|array|max:10',
            'schedule_adjustments.*' => 'array:shoot_id,photographer_id,from_start,scheduled_at,expected_edit_version,service_durations',
            'schedule_adjustments.*.shoot_id' => 'required|integer|exists:shoots,id',
            'schedule_adjustments.*.photographer_id' => 'required|integer|exists:users,id',
            'schedule_adjustments.*.from_start' => 'required|date',
            'schedule_adjustments.*.scheduled_at' => 'required|date',
            'schedule_adjustments.*.expected_edit_version' => 'required|string|size:64',
            'schedule_adjustments.*.service_durations' => 'sometimes|array|min:1|max:100',
            'schedule_adjustments.*.service_durations.*' => 'array:shoot_service_id,duration_minutes',
            'schedule_adjustments.*.service_durations.*.shoot_service_id' => 'required|integer',
            'schedule_adjustments.*.service_durations.*.duration_minutes' => 'required|integer|min:1|max:300',
        ];
    }

    private function adjustable(Shoot $shoot, ?User $actor, array $visits): bool
    {
        return app(ShootManagementAccess::class)->canEditAppointment($shoot, $actor)
            && count($visits) > 0;
    }

    public function day(int $photographerId, string $date, string $timezone, ?User $actor, ?int $exclude): array
    {
        $all = app(ScheduleVisitRepository::class)->visits([$photographerId], $exclude ? [$exclude] : []);
        $start = Carbon::parse($date, $timezone)->startOfDay()->utc();
        $end = $start->copy()->setTimezone($timezone)->addDay()->utc();
        $access = app(ShootAuthorizationSupport::class);
        $management = app(ShootManagementAccess::class);
        $rows = [];
        foreach ($all as $visit) {
            if (Carbon::parse($visit['start'])->gte($end) || Carbon::parse($visit['end'])->lte($start)) continue;
            $shoot = $visit['_shoot'];
            $visible = $management->canEditAppointment($shoot, $actor) || $access->canViewShootDetails($shoot, $actor);
            $shootVisits = app(\App\Services\Shoots\ShootDurationResolver::class)->windowsForShoot($shoot);
            $window = collect($shootVisits)->first(fn ($row) => (int) $row['photographer_id'] === $photographerId
                && $row['start']?->eq(Carbon::parse($visit['start'])));
            $canAdjust = $visible && $this->adjustable($shoot, $actor, $shootVisits);
            $rows[] = [
                'id' => $visible ? $visit['id'] : hash('sha256', $visit['id']), 'start' => $visit['start'], 'end' => $visit['end'],
                'duration_minutes' => $visit['duration_minutes'], 'photographer_id' => $photographerId,
                'label' => $visible ? $shoot->address : 'Booked',
                'shoot_id' => $visible ? $shoot->id : null,
                'can_adjust' => $canAdjust,
                'duration_items' => $canAdjust ? app(DayScheduleDurations::class)->items($shoot, $window['row_indexes'] ?? []) : [],
                'expected_edit_version' => $visible && $management->canEditAppointment($shoot, $actor) ? $management->editVersion($shoot) : null,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['start'], $b['start']));

        return ['date' => $date, 'timezone' => $timezone, 'photographer' => User::findOrFail($photographerId)->only(['id', 'name']),
            'bookings' => $rows, 'schedule_version' => app(ScheduleVisitRepository::class)->fingerprint([$photographerId])];
    }

    public function expand(array $plans, ?User $actor): array
    {
        if (collect($plans)->contains(fn ($p) => isset($p['_day_adjustment']))) return $plans;
        $changes = $plans[0]['payload']['schedule_adjustments'] ?? [];
        if (! $changes) return $plans;
        validator(['schedule_adjustments' => $changes], self::rules())->validate();
        if (! app(ScheduleCommitGuard::class)->enabled()) $this->invalid('Schedule adjustment is unavailable. Refresh and try again.');
        $main = $plans[0];
        app(TravelScheduleAccess::class)->authorizePayload($main['payload'], $main['shoot'] ?? null, $actor);
        abort_unless($main['shoot'] ? app(ShootManagementAccess::class)->canEditAppointment($main['shoot'], $actor)
            : app(ShootManagementAccess::class)->can($actor), 403);
        $proposed = app(VisitPlanBuilder::class)->build($main['payload'], $main['shoot'] ?? null, $actor);
        foreach (collect($changes)->groupBy('shoot_id') as $shootId => $shootChanges) {
            $shoot = Shoot::with(['serviceItems.service', 'serviceItems.unit', 'services', 'photographer'])->findOrFail($shootId);
            abort_unless(app(ShootManagementAccess::class)->canEditAppointment($shoot, $actor), 403);
            if (collect($plans)->contains(fn ($p) => ($p['shoot']?->id ?? null) === $shoot->id)) $this->invalid('A booking may only be adjusted once.');
            $windows = array_map(fn ($window) => [...$window, 'timezone' => $window['start']?->timezoneName ?? $shoot->timezone],
                app(ShootDurationResolver::class)->windowsForShoot($shoot));
            $edits = []; $seen = [];
            foreach ($shootChanges as $change) {
                app(ShootManagementAccess::class)->assertVersion($shoot, $change['expected_edit_version']);
                $index = collect($windows)->search(fn ($row) => (int) $row['photographer_id'] === (int) $change['photographer_id']
                    && $row['start']?->eq(Carbon::parse($change['from_start'])));
                if ($index === false || isset($seen[$index]) || ! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $change['scheduled_at'])) {
                    $this->invalid('This booking changed or a visit was selected twice. Reload the day schedule.');
                }
                $seen[$index] = true;
                $window = $windows[$index]; $zone = $window['timezone'];
                $before = $window['start']; $after = Carbon::parse($change['scheduled_at']);
                $day = $before->copy()->setTimezone($zone)->toDateString();
                if ($after->copy()->setTimezone($zone)->toDateString() !== $day
                    || ! collect($proposed)->contains(fn ($p) => (int) $p['photographer_id'] === (int) $change['photographer_id']
                        && Carbon::parse($p['scheduled_at'] ?? $p['start'])->setTimezone($zone)->toDateString() === $day)) {
                    $this->invalid('Only bookings on the selected photographer and date can be adjusted here.');
                }
                $minutes = app(DayScheduleDurations::class)->resolve($shoot, $window, $change['service_durations'] ?? []);
                $windows[$index]['start'] = $after;
                $windows[$index]['minutes'] = $minutes;
                $edits[] = ['change' => $change, 'timezone' => $zone, 'line_ids' => $window['row_indexes'], 'from_minutes' => $window['minutes'], 'minutes' => $minutes];
            }
            $payload = array_merge($main['payload'], $shoot->only(['client_id', 'address', 'city', 'state', 'zip', 'property_details']), [
                'shoot_id' => $shoot->id, 'timezone' => $shoot->timezone, 'action_mode' => 'reschedule',
                'photographer_id' => $shoot->photographer_id,
                '_schedule_visits' => array_map(fn ($window) => [
                    'photographer_id' => $window['photographer_id'], 'scheduled_at' => $window['start']?->toIso8601String(),
                    'duration_minutes' => $window['minutes'], 'timezone' => $window['timezone'], '_resolved_window' => true,
                ], $windows),
            ]);
            unset($payload['schedule_adjustments'], $payload['services'], $payload['service_items'], $payload['service_lines'], $payload['units'], $payload['travel_location_confirmed']);
            $plans[] = ['payload' => $payload, 'shoot' => $shoot, '_day_adjustment' => $shootChanges->first(), '_day_edits' => $edits];
        }

        return $plans;
    }

    /** Called inside the guard's outer transaction, before the target write. */
    public function apply(array $plans, ?User $actor): array
    {
        $saved = [];
        foreach ($plans as $plan) {
            if (! isset($plan['_day_adjustment'])) continue;
            $shoot = $plan['shoot'];
            $currentActor = $actor ? User::find($actor->id) : null;
            abort_unless(app(ShootManagementAccess::class)->canEditAppointment($shoot->fresh(), $currentActor), 403);
            foreach ($plan['_day_edits'] as $edit) app(ShootManagementAccess::class)->assertVersion($shoot, $edit['change']['expected_edit_version']);
            $mail = app(MailService::class);
            $before = $mail->captureShootSnapshot($shoot);
            $dates = [];
            $anchor = app(\App\Services\Schedule\ScheduleInstantResolver::class)->forShoot($shoot);
            foreach ($plan['_day_edits'] as $edit) {
                $change = $edit['change'];
                $local = Carbon::parse($change['scheduled_at'])->setTimezone($edit['timezone']);
                $stored = $shoot->timezone ? $local->copy()->utc() : $local->copy()->shiftTimezone(config('app.timezone', 'UTC'));
                if ($edit['line_ids']) $shoot->serviceItems()->whereIn('id', $edit['line_ids'])
                    ->update(['scheduled_at' => $stored->format('Y-m-d H:i:s')]);
                foreach ($change['service_durations'] ?? [] as $line) {
                    $shoot->serviceItems()->whereKey($line['shoot_service_id'])->update(['duration_minutes' => $line['duration_minutes']]);
                }
                if ((int) $shoot->photographer_id === (int) $change['photographer_id'] && $anchor?->eq(Carbon::parse($change['from_start']))) {
                    $shoot->forceFill(['scheduled_at' => $stored, 'scheduled_date' => $local->toDateString(), 'time' => $local->format('H:i')]);
                }
                $dates[] = $local->toDateString();
                app(ShootActivityLogger::class)->log($shoot, 'rescheduled', [
                    'from' => $change['from_start'], 'to' => $change['scheduled_at'], 'suppress_notifications' => true,
                    'from_duration_minutes' => $edit['from_minutes'], 'duration_minutes' => $edit['minutes'],
                    'service_durations' => $change['service_durations'] ?? [],
                ], $currentActor);
            }
            $shoot->save();
            $shoot->unsetRelation('serviceItems');
            $summary = $mail->buildShootChangeSummary($before, $shoot);
            foreach ($plan['_day_edits'] as $edit) {
                if ($edit['from_minutes'] === $edit['minutes']) continue;
                $at = Carbon::parse($edit['change']['scheduled_at'])->setTimezone($edit['timezone'])->format('g:i A T');
                $line = 'Shoot duration ('.$at.'): '.$edit['from_minutes'].' min to '.$edit['minutes'].' min';
                $summary['summary'] = trim($summary['summary']."\n".$line);
                $summary['html'] .= '<p>'.e($line).'</p>';
            }
            DB::afterCommit(function () use ($shoot, $summary, $plan) {
                ProcessUpdatedShootSideEffectsJob::dispatch($shoot->id, $summary['summary'], $summary['html'],
                    $plan['payload']['notify_client'] ?? null, $plan['payload']['notify_photographer'] ?? null,
                    $shoot->photographer_id, $shoot->status, $shoot->workflow_status, false, false)->afterCommit();
                app(GoogleCalendarSyncDispatcher::class)->dispatchShootSync($shoot->id);
            });
            DB::afterCommit(fn () => app(ScheduleDateScopeService::class)->invalidateDates(array_unique($dates)));
            $saved[] = $shoot;
        }

        return $saved;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['schedule_adjustments' => $message]);
    }
}
