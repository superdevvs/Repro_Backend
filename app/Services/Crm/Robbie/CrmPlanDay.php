<?php

namespace App\Services\Crm\Robbie;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmEventType;
use App\Models\Crm\CrmQuote;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\CrmClientHealth;

/**
 * "Plan my day" (frame R2): today's work in a sensible order, each with the
 * reason it is on the list. Rule-based; Robbie only explains it.
 */
class CrmPlanDay
{
    public function __construct(private readonly CrmAccess $access) {}

    /** @return array<int, array<string, mixed>> */
    public function for(User $user, int $limit = 12): array
    {
        $types = CrmEventType::query()->get()->keyBy('id');
        $items = collect();
        $mineOnly = ! $this->access->canViewAll($user);
        $contactFields = fn (?CrmContact $c) => $c ? ['id' => $c->id, 'name' => $c->name, 'company' => $c->company, 'stage' => $c->stage, 'phone' => $c->phone, 'email' => $c->email] : null;

        $events = CrmEvent::query()->visible($user)->where('assigned_user_id', $user->id)->where('status', 'planned')
            ->where('starts_at', '<=', now()->endOfDay())->orderBy('starts_at')->limit(40)->get();
        $contacts = CrmContact::query()->whereIn('id', $events->pluck('contact_id')->filter())->get()->keyBy('id');
        foreach ($events as $event) {
            $overdue = $event->starts_at->lt(now()->startOfDay());
            $type = $types[$event->type_id] ?? null;
            $items->push([
                'kind' => $overdue ? 'overdue' : 'follow_up',
                'priority' => $overdue ? 0 : 2,
                'at' => $event->starts_at->toIso8601String(),
                'title' => ($type?->label ?? 'Follow-up').($event->contact_id && isset($contacts[$event->contact_id]) ? ' · '.$contacts[$event->contact_id]->name : ''),
                'reason' => $overdue ? 'Overdue since '.$event->starts_at->format('D j M') : 'Due '.$event->starts_at->format('g:i a'),
                'note' => $event->note,
                'event_id' => $event->id,
                'contact' => $contactFields($contacts[$event->contact_id] ?? null),
                'action' => ['kind' => in_array($type?->key, ['email', 'checkin_email'], true) ? 'email' : 'call', 'label' => $type?->label ?? 'Do it'],
            ]);
        }

        $planned = CrmEvent::query()->where('status', 'planned')->whereNotNull('contact_id')->pluck('contact_id')->flip();

        CrmContact::query()->whereIn('stage', [CrmContact::STAGE_OUTREACH, CrmContact::STAGE_INQUIRY])->whereNull('last_contacted_at')
            ->whereNotNull('first_contact_due_at')->where('first_contact_due_at', '<', now()->addHours(4))
            ->when($mineOnly, fn ($q) => $q->where('assigned_rep_id', $user->id))
            ->orderBy('first_contact_due_at')->limit(10)->get()
            ->each(fn (CrmContact $c) => $items->push([
                'kind' => 'first_contact', 'priority' => 1, 'at' => $c->first_contact_due_at?->toIso8601String(),
                'title' => 'First call · '.$c->name,
                'reason' => $c->first_contact_due_at->isPast() ? 'New lead, past the first-contact target' : 'New lead, first contact due '.$c->first_contact_due_at->format('g:i a'),
                'note' => null, 'event_id' => null, 'contact' => $contactFields($c), 'action' => ['kind' => 'call', 'label' => 'Call'],
            ]));

        $days = (int) config('crm.quote_follow_up_days', 5);
        $quotes = CrmQuote::query()->where('status', 'sent')->where('sent_at', '<', now()->subDays($days))->orderBy('sent_at')->limit(10)->get();
        $quoteContacts = CrmContact::query()->whereIn('id', $quotes->pluck('contact_id'))->get()->keyBy('id');
        foreach ($quotes as $quote) {
            $c = $quoteContacts[$quote->contact_id] ?? null;
            if (! $c || isset($planned[$c->id]) || ($mineOnly && (int) $c->assigned_rep_id !== (int) $user->id)) {
                continue;
            }
            $items->push([
                'kind' => 'quote', 'priority' => 3, 'at' => null, 'title' => 'Quote follow-up · '.$c->name,
                'reason' => 'Quote '.$quote->number.' sent '.$quote->sent_at->diffForHumans().', no answer yet', 'note' => null,
                'event_id' => null, 'contact' => $contactFields($c), 'action' => ['kind' => 'call', 'label' => 'Call'],
            ]);
        }

        CrmContact::query()->where('health', CrmClientHealth::AT_RISK)->where('stage', '!=', CrmContact::STAGE_LOST)
            ->when($mineOnly, fn ($q) => $q->where('assigned_rep_id', $user->id))
            ->orderBy('last_shoot_on')->limit(10)->get()
            ->reject(fn (CrmContact $c) => isset($planned[$c->id]))
            ->each(fn (CrmContact $c) => $items->push([
                'kind' => 'at_risk', 'priority' => 4, 'at' => null, 'title' => 'Check in · '.$c->name,
                'reason' => 'Usually books every '.$c->usual_gap_days.' days; last shoot '.($c->last_shoot_on?->format('j M') ?? 'a while ago'),
                'note' => null, 'event_id' => null, 'contact' => $contactFields($c), 'action' => ['kind' => 'email', 'label' => 'Check-in email'],
            ]));

        return $items->sortBy([['priority', 'asc'], ['at', 'asc']])->take($limit)->values()->map(function ($item) {
            unset($item['priority']);

            return $item;
        })->all();
    }
}
