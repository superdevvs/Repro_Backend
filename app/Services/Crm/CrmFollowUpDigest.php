<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\User;
use App\Services\Crm\Email\CrmEmailSender;
use App\Services\Crm\Email\CrmSendBlocked;

/**
 * Weekday morning email: each person's follow-ups due today and overdue.
 * Sent from the system address, once per person per day. A person can turn
 * it off with users.metadata.crmDigest = false.
 */
class CrmFollowUpDigest
{
    public function __construct(
        private readonly CrmEmailSender $sender,
        private readonly CrmAccess $access,
    ) {}

    /** @return array{sent: int, skipped: int} */
    public function run(): array
    {
        if (config('crm.outbound_hold')) {
            return ['sent' => 0, 'skipped' => 0];
        }
        $endOfToday = CrmBusinessTime::endOfDay();
        $byUser = CrmEvent::query()->where('status', 'planned')->where('starts_at', '<=', $endOfToday)
            ->whereNotNull('assigned_user_id')->orderBy('starts_at')->get()->groupBy('assigned_user_id');
        $sent = 0;
        $skipped = 0;

        foreach ($byUser as $userId => $events) {
            $user = User::query()->find($userId);
            if (! $user || ! $user->email || ($user->account_status && $user->account_status !== 'active') || (($user->metadata['crmDigest'] ?? true) === false)) {
                $skipped++;

                continue;
            }
            $events = $this->visibleTo($user, $events);
            if ($events->isEmpty()) {
                continue;
            }
            try {
                $this->sender->send([
                    'to_email' => $user->email,
                    'sender' => null,
                    'source_type' => 'digest',
                    'content' => $this->content($user, $events),
                    'idempotency_key' => 'crm-digest-'.$user->id.'-'.now(CrmBusinessTime::TIMEZONE)->toDateString(),
                    'marketing' => false,
                ])->requireProviderAcceptance();
                $sent++;
            } catch (CrmSendBlocked) {
                $skipped++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /** Only follow-ups on contacts this person can still see (a contact may have moved to another rep). */
    private function visibleTo(User $user, $events)
    {
        if ($this->access->canViewAll($user)) {
            return $events->filter(fn ($e) => $e->contact_id !== null || $e->applicant_id === null || $this->access->can($user, 'crm-recruiting', 'view'))->values();
        }
        $owned = CrmContact::unscopedForSystem()->whereIn('id', $events->pluck('contact_id')->filter()->unique())
            ->where('assigned_rep_id', $user->id)->pluck('id')->flip();

        return $events->filter(fn ($e) => $e->contact_id === null ? $e->applicant_id === null : isset($owned[$e->contact_id]))->values();
    }

    private function content(User $user, $events): array
    {
        $types = \App\Models\Crm\CrmEventType::query()->pluck('label', 'id');
        $contacts = CrmContact::unscopedForSystem()->whereIn('id', $events->pluck('contact_id')->filter()->unique())->get(['id', 'name', 'company'])->keyBy('id');
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $overdue = $events->filter(fn ($e) => $e->starts_at->lt(CrmBusinessTime::startOfDay()));
        $today = $events->diff($overdue);

        $row = function ($e) use ($types, $contacts, $base) {
            $c = $e->contact_id ? $contacts[$e->contact_id] ?? null : null;
            $who = $c ? e($c->name).($c->company ? ' · '.e($c->company) : '') : 'No contact';
            $link = $c ? ' <a href="'.e($base.'/crm/contacts/'.$c->id).'">Open</a>' : '';

            return '<li><strong>'.e($types[$e->type_id] ?? 'Follow-up').'</strong> · '.$who.' · '.e($e->starts_at->copy()->timezone(CrmBusinessTime::TIMEZONE)->format('g:i a T')).$link.($e->note ? '<br>'.e($e->note) : '').'</li>';
        };

        $html = '<p>Good morning '.e(explode(' ', trim($user->name))[0] ?? '').',</p>';
        if ($overdue->isNotEmpty()) {
            $html .= '<p><strong>Overdue ('.$overdue->count().')</strong></p><ul>'.$overdue->map($row)->implode('').'</ul>';
        }
        if ($today->isNotEmpty()) {
            $html .= '<p><strong>Today ('.$today->count().')</strong></p><ul>'.$today->map($row)->implode('').'</ul>';
        }
        $html .= '<p><a href="'.e($base.'/crm/follow-ups').'">Open follow-ups</a></p>';

        $subject = $events->count().' follow-up'.($events->count() === 1 ? '' : 's').' today'.($overdue->isNotEmpty() ? ' ('.$overdue->count().' overdue)' : '');

        return ['subject' => $subject, 'body_html' => $html, 'preheader' => null, 'image_url' => null, 'cta_label' => null, 'cta_url' => null];
    }
}
