<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmEventType;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scheduling and completing follow-ups. Completing an event asks for an
 * outcome note and, optionally, the next event. Changes are mirrored to the
 * assigned user's Google Calendar when that is switched on.
 */
class CrmEventService
{
    public function __construct(
        private readonly CrmActivityLogger $activity,
        private readonly CrmAccess $access,
    ) {}

    /** @param  array{type_id?: int, type?: string, starts_at: string, duration_min?: int, assigned_user_id?: int|null, note?: string|null}  $data */
    public function schedule(?CrmContact $contact, array $data, ?User $actor, bool $viaRobbie = false, ?int $applicantId = null): CrmEvent
    {
        $type = $this->resolveType($data);
        $assignee = $this->resolveAssignee($contact, $data['assigned_user_id'] ?? null, $actor);

        $event = LockedWrite::run(fn () => DB::transaction(function () use ($contact, $data, $actor, $viaRobbie, $applicantId, $type, $assignee) {
            $event = CrmEvent::create([
                'contact_id' => $contact?->id,
                'applicant_id' => $applicantId,
                'type_id' => $type->id,
                'starts_at' => Carbon::parse($data['starts_at']),
                'duration_min' => (int) ($data['duration_min'] ?? 15),
                'assigned_user_id' => $assignee,
                'note' => $data['note'] ?? null,
                'status' => 'planned',
                'created_by' => $actor?->id,
                'via_robbie' => $viaRobbie,
            ]);
            if ($contact) {
                $this->activity->log($contact, 'event_scheduled', $actor, $type->label.' scheduled for '.$event->starts_at->format('D j M, g:i a'), [
                    'event_id' => $event->id, 'type' => $type->key, 'note' => $event->note,
                ], $viaRobbie, 'event', $event->id);
            }

            return $event;
        }), 'crm.event.schedule');

        $this->syncCalendar($event);

        return $event;
    }

    public function update(CrmEvent $event, array $data, ?User $actor): CrmEvent
    {
        if ($event->status !== 'planned') {
            throw ValidationException::withMessages(['status' => 'Only planned events can be changed.']);
        }
        $changes = [];
        if (isset($data['type_id']) || isset($data['type'])) {
            $changes['type_id'] = $this->resolveType($data)->id;
        }
        if (isset($data['starts_at'])) {
            $changes['starts_at'] = Carbon::parse($data['starts_at']);
        }
        if (isset($data['duration_min'])) {
            $changes['duration_min'] = (int) $data['duration_min'];
        }
        if (array_key_exists('note', $data)) {
            $changes['note'] = $data['note'];
        }
        if (array_key_exists('assigned_user_id', $data)) {
            $changes['assigned_user_id'] = $this->resolveAssignee($event->contact, $data['assigned_user_id'], $actor);
        }
        $event->forceFill($changes)->save();

        if ($event->contact && isset($changes['starts_at'])) {
            $this->activity->log($event->contact, 'event_rescheduled', $actor, ($event->type?->label ?? 'Event').' moved to '.$event->starts_at->format('D j M, g:i a'), ['event_id' => $event->id], false, 'event', $event->id);
        }
        $this->syncCalendar($event);

        return $event;
    }

    /**
     * @param  array{status: string, outcome_note?: string|null, next?: array|null}  $data
     * @return array{event: CrmEvent, next: ?CrmEvent}
     */
    public function complete(CrmEvent $event, array $data, ?User $actor): array
    {
        if ($event->status !== 'planned') {
            throw ValidationException::withMessages(['status' => 'This event is already closed.']);
        }
        if (! in_array($data['status'], ['done', 'missed'], true)) {
            throw ValidationException::withMessages(['status' => 'Mark the event done or missed.']);
        }
        if ($data['status'] === 'done' && trim((string) ($data['outcome_note'] ?? '')) === '') {
            throw ValidationException::withMessages(['outcome_note' => 'Add a short note on how it went.']);
        }

        $next = null;
        LockedWrite::run(fn () => DB::transaction(function () use ($event, $data, $actor) {
            $event->forceFill([
                'status' => $data['status'],
                'outcome_note' => $data['outcome_note'] ?? null,
                'completed_at' => now(),
            ])->save();

            if ($contact = $event->contact) {
                $type = $event->type;
                $label = ($type?->label ?? 'Event').($data['status'] === 'done' ? ' completed' : ' missed');
                $this->activity->log($contact, 'event_'.$data['status'], $actor, $label, [
                    'event_id' => $event->id, 'type' => $type?->key, 'outcome' => $data['outcome_note'] ?? null, 'duration_min' => $event->duration_min,
                ], false, 'event', $event->id);
                if ($data['status'] === 'done' && ($type?->counts_as_contact ?? true)) {
                    $contact->forceFill(['last_contacted_at' => now(), 'first_contact_due_at' => null])->save();
                }
            }
        }), 'crm.event.complete');

        if (! empty($data['next'])) {
            $next = $this->schedule($event->contact, $data['next'], $actor);
            $event->forceFill(['next_event_id' => $next->id])->save();
        }

        $this->syncCalendar($event);

        return ['event' => $event, 'next' => $next];
    }

    /** Cancels every planned event on a contact, for example when it is marked lost. */
    public function cancelPlanned(CrmContact $contact, ?User $actor, string $reason): int
    {
        $events = CrmEvent::query()->where('contact_id', $contact->id)->where('status', 'planned')->get();
        foreach ($events as $event) {
            $event->forceFill(['status' => 'cancelled', 'completed_at' => now(), 'outcome_note' => $reason])->save();
            $this->syncCalendar($event);
        }
        if ($events->isNotEmpty()) {
            $this->activity->log($contact, 'events_cancelled', $actor, $events->count().' planned follow-up'.($events->count() === 1 ? '' : 's').' cancelled: '.$reason);
        }

        return $events->count();
    }

    public function resolveType(array $data): CrmEventType
    {
        $type = isset($data['type_id'])
            ? CrmEventType::query()->whereKey($data['type_id'])->first()
            : CrmEventType::query()->where('key', $data['type'] ?? 'call')->first();
        if (! $type || ! $type->is_active) {
            throw ValidationException::withMessages(['type_id' => 'Choose an event type.']);
        }

        return $type;
    }

    /** Reps schedule for themselves; admins may schedule for anyone on the team. */
    private function resolveAssignee(?CrmContact $contact, mixed $requested, ?User $actor): int
    {
        if ($requested !== null && $requested !== '' && $actor && (int) $requested !== (int) $actor->id) {
            if (! $this->access->canViewAll($actor)) {
                throw ValidationException::withMessages(['assigned_user_id' => 'You can only schedule events for yourself.']);
            }

            return (int) $requested;
        }
        if ($actor) {
            return (int) $actor->id;
        }
        if ($contact?->assigned_rep_id) {
            return (int) $contact->assigned_rep_id;
        }
        throw ValidationException::withMessages(['assigned_user_id' => 'Choose who the event is for.']);
    }

    private function syncCalendar(CrmEvent $event): void
    {
        if (! config('crm.google_sync.enabled') || ! class_exists(\App\Services\Crm\Calendar\CrmCalendarSync::class)) {
            return;
        }
        DB::afterCommit(fn () => app(\App\Services\Crm\Calendar\CrmCalendarSync::class)->queue($event));
    }
}
