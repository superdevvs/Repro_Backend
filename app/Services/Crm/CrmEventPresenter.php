<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmEventType;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Event shapes for the follow-ups list, contact cards and the stat strip. */
class CrmEventPresenter
{
    /** @var array<int, CrmEventType>|null */
    private ?array $types = null;

    public function __construct(private readonly CrmPresenter $presenter) {}

    public function types(): array
    {
        return $this->types ??= CrmEventType::query()->orderBy('sort')->get()->keyBy('id')->all();
    }

    public function event(CrmEvent $event, bool $withContact = true): array
    {
        $type = $this->types()[$event->type_id] ?? null;
        $now = now();
        // Overdue means due on an earlier day; earlier today still shows under Today.
        $overdue = $event->status === 'planned' && $event->starts_at->lt($now->copy()->startOfDay());
        $contact = $withContact && $event->contact_id ? CrmContact::query()->find($event->contact_id) : null;

        return [
            'id' => $event->id,
            'type' => $type ? ['id' => $type->id, 'key' => $type->key, 'label' => $type->label, 'icon' => $type->icon] : null,
            'starts_at' => $event->starts_at->toIso8601String(),
            'duration_min' => $event->duration_min,
            'status' => $event->status,
            'note' => $event->note,
            'outcome_note' => $event->outcome_note,
            'completed_at' => $event->completed_at?->toIso8601String(),
            'assigned_user' => $this->presenter->user($event->assigned_user_id),
            'via_robbie' => (bool) $event->via_robbie,
            'overdue' => $overdue,
            'late' => $overdue ? self::lateLabel($event->starts_at) : null,
            'contact' => $contact ? [
                'id' => $contact->id, 'name' => $contact->name, 'company' => $contact->company,
                'stage' => $contact->stage, 'stage_label' => $contact->stageLabel(),
            ] : null,
            'applicant_id' => $event->applicant_id,
            'applicant' => $withContact && $event->applicant_id
                ? \App\Models\Recruiting\RecruitApplicant::query()->whereKey($event->applicant_id)->first(['id', 'name', 'role', 'stage'])?->only(['id', 'name', 'role', 'stage'])
                : null,
            'on_calendar' => (bool) \Illuminate\Support\Facades\DB::table('crm_event_calendar_links')->where('event_id', $event->id)->whereNotNull('google_event_id')->exists(),
        ];
    }

    public static function lateLabel(Carbon $startsAt): string
    {
        $days = (int) floor($startsAt->copy()->startOfDay()->diffInDays(now()->startOfDay()));

        return $days >= 1 ? $days.' day'.($days === 1 ? '' : 's') : 'today';
    }

    public function nextFor(CrmContact $contact): ?array
    {
        $event = CrmEvent::query()->where('contact_id', $contact->id)->where('status', 'planned')->orderBy('starts_at')->first();

        return $event ? $this->event($event, false) : null;
    }

    /** The next planned event per contact, in card form. @return array<int, array> */
    public function nextForMany(array $contactIds): array
    {
        $events = CrmEvent::query()->whereIn('contact_id', $contactIds)->where('status', 'planned')->orderBy('starts_at')->get()->groupBy('contact_id');
        $out = [];
        foreach ($events as $contactId => $list) {
            $event = $list->first();
            $type = $this->types()[$event->type_id] ?? null;
            $overdue = $event->starts_at->lt(now()->startOfDay());
            $out[$contactId] = [
                'label' => $type?->label ?? 'Event',
                'type_key' => $type?->key,
                'icon' => $type?->icon ?? 'calendar',
                'overdue' => $overdue,
                'late' => $overdue ? self::lateLabel($event->starts_at) : null,
                'when' => $event->starts_at->isToday() ? 'today '.$event->starts_at->format('g:i a') : $event->starts_at->format('D j M'.($event->starts_at->format('H:i') !== '00:00' ? ', g:i a' : '')),
            ];
        }

        return $out;
    }

    public function overdueCount(?User $user = null): int
    {
        return CrmEvent::query()->visible(app(CrmAccess::class)->user())
            ->when($user, fn ($q) => $q->where('assigned_user_id', $user->id))
            ->where('status', 'planned')->where('starts_at', '<', now()->startOfDay())->count();
    }

    public function dueCountFor(User $user): int
    {
        return CrmEvent::query()->where('assigned_user_id', $user->id)->where('status', 'planned')->where('starts_at', '<', now()->endOfDay())->count();
    }
}
