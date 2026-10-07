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
            'schedule_adjustments.*' => 'array:shoot_id,photographer_id,from_start,scheduled_at,expected_edit_version',
            'schedule_adjustments.*.shoot_id' => 'required|integer|distinct|exists:shoots,id',
            'schedule_adjustments.*.photographer_id' => 'required|integer|exists:users,id',
            'schedule_adjustments.*.from_start' => 'required|date',
            'schedule_adjustments.*.scheduled_at' => 'required|date',
            'schedule_adjustments.*.expected_edit_version' => 'required|string|size:64',
        ];
    }

    private function adjustable(Shoot $shoot, ?User $actor, array $visits): bool
    {
        // A single capture window can move without changing other photographers or units.
        return app(ShootManagementAccess::class)->canEdit($shoot, $actor)
            && count($visits) === 1 && ! $shoot->units()->exists();
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
            $visible = $management->canEdit($shoot, $actor) || $access->canViewShootDetails($shoot, $actor);
            $shootVisits = app(\App\Services\Shoots\ShootDurationResolver::class)->windowsForShoot($shoot);
            $rows[] = [
                'id' => $visible ? $visit['id'] : hash('sha256', $visit['id']), 'start' => $visit['start'], 'end' => $visit['end'],
                'duration_minutes' => $visit['duration_minutes'], 'photographer_id' => $photographerId,
                'label' => $visible ? $shoot->address : 'Booked',
                'shoot_id' => $visible ? $shoot->id : null,
                'can_adjust' => $visible && $this->adjustable($shoot, $actor, $shootVisits),
                'expected_edit_version' => $visible && $management->canEdit($shoot, $actor) ? $management->editVersion($shoot) : null,
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
        abort_unless($main['shoot'] ? app(ShootManagementAccess::class)->canEdit($main['shoot'], $actor)
            : app(ShootManagementAccess::class)->can($actor), 403);
        $proposed = app(VisitPlanBuilder::class)->build($main['payload'], $main['shoot'] ?? null, $actor);
        foreach ($changes as $change) {
            $shoot = Shoot::with(['serviceItems.service', 'serviceItems.unit', 'services', 'photographer'])->findOrFail($change['shoot_id']);
            abort_unless(app(ShootManagementAccess::class)->canEdit($shoot, $actor), 403);
            app(ShootManagementAccess::class)->assertVersion($shoot, $change['expected_edit_version']);
            if (collect($plans)->contains(fn ($p) => ($p['shoot']?->id ?? null) === $shoot->id)) $this->invalid('A booking may only be adjusted once.');
            $ids = array_values(array_unique(array_filter(array_merge([(int) $shoot->photographer_id], $shoot->serviceItems->pluck('photographer_id')->all()))));
            $visits = array_values(array_filter(app(ScheduleVisitRepository::class)->visits($ids), fn ($v) => $v['_shoot']->id === $shoot->id));
            if (! $this->adjustable($shoot, $actor, $visits)) $this->invalid('Open this multi-visit booking separately to adjust its schedule.');
            $visit = $visits[0];
            $zone = $visit['timezone'];
            $before = Carbon::parse($visit['start']);
            $after = Carbon::parse($change['scheduled_at']);
            if (! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $change['scheduled_at'])
                || ! $before->eq(Carbon::parse($change['from_start']))
                || (int) $visit['photographer_id'] !== (int) $change['photographer_id']) $this->invalid('This booking changed. Reload the day schedule.');
            $day = $before->copy()->setTimezone($zone)->toDateString();
            if ($after->copy()->setTimezone($zone)->toDateString() !== $day
                || ! collect($proposed)->contains(fn ($p) => (int) $p['photographer_id'] === (int) $change['photographer_id']
                    && Carbon::parse($p['scheduled_at'] ?? $p['start'])->setTimezone($zone)->toDateString() === $day)) {
                $this->invalid('Only bookings on the selected photographer and date can be adjusted here.');
            }
            $payload = array_merge($main['payload'], $shoot->only(['client_id', 'address', 'city', 'state', 'zip', 'property_details']), [
                'shoot_id' => $shoot->id, 'timezone' => $zone, 'action_mode' => 'reschedule',
                'photographer_id' => $visit['photographer_id'], 'scheduled_at' => $after->toIso8601String(),
                '_schedule_visits' => [[
                    'photographer_id' => $visit['photographer_id'], 'scheduled_at' => $after->toIso8601String(),
                    'duration_minutes' => $visit['duration_minutes'], 'timezone' => $zone, '_resolved_window' => true,
                ]],
            ]);
            unset($payload['schedule_adjustments'], $payload['services'], $payload['service_items'], $payload['service_lines'], $payload['units'], $payload['travel_location_confirmed']);
            $windows = app(\App\Services\Shoots\ShootDurationResolver::class)->windowsForShoot($shoot);
            $plans[] = ['payload' => $payload, 'shoot' => $shoot, '_day_adjustment' => $change, '_day_timezone' => $zone,
                '_day_line_ids' => $windows[0]['row_indexes'] ?? []];
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
            $change = $plan['_day_adjustment'];
            app(ShootManagementAccess::class)->assertVersion($shoot, $change['expected_edit_version']);
            $mail = app(MailService::class);
            $before = $mail->captureShootSnapshot($shoot);
            $local = Carbon::parse($change['scheduled_at'])->setTimezone($plan['_day_timezone']);
            $stored = $shoot->timezone ? $local->copy()->utc() : $local->copy()->shiftTimezone(config('app.timezone', 'UTC'));
            if ($plan['_day_line_ids']) $shoot->serviceItems()->whereIn('id', $plan['_day_line_ids'])
                ->update(['scheduled_at' => $stored->format('Y-m-d H:i:s')]);
            $shoot->forceFill(['scheduled_at' => $stored, 'scheduled_date' => $local->toDateString(), 'time' => $local->format('H:i')])->save();
            $shoot->unsetRelation('serviceItems');
            $summary = $mail->buildShootChangeSummary($before, $shoot);
            app(ShootActivityLogger::class)->log($shoot, 'rescheduled', [
                'from' => $change['from_start'], 'to' => $change['scheduled_at'], 'suppress_notifications' => true,
            ], $actor);
            DB::afterCommit(function () use ($shoot, $summary, $plan) {
                ProcessUpdatedShootSideEffectsJob::dispatch($shoot->id, $summary['summary'], $summary['html'],
                    $plan['payload']['notify_client'] ?? null, $plan['payload']['notify_photographer'] ?? null,
                    $shoot->photographer_id, $shoot->status, $shoot->workflow_status, false, false)->afterCommit();
                app(GoogleCalendarSyncDispatcher::class)->dispatchShootSync($shoot->id);
            });
            DB::afterCommit(fn () => app(ScheduleDateScopeService::class)->invalidateDates([$local->toDateString()]));
            $saved[] = $shoot;
        }

        return $saved;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['schedule_adjustments' => $message]);
    }
}
