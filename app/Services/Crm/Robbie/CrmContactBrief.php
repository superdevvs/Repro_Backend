<?php

namespace App\Services\Crm\Robbie;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmNote;
use App\Models\Crm\CrmQuote;
use App\Services\Crm\CrmClientShootStats;
use App\Services\Crm\Email\CrmDripPresenter;

/**
 * A short brief on one contact: the facts, plus a plain-language summary.
 * The summary is rule-based unless the caller asks for Robbie's wording.
 */
class CrmContactBrief
{
    public function facts(CrmContact $contact): array
    {
        $lastNote = CrmNote::query()->where('contact_id', $contact->id)->latest('id')->first();
        $next = CrmEvent::query()->where('contact_id', $contact->id)->where('status', 'planned')->orderBy('starts_at')->first();
        $emails = CrmEmailSend::query()->where('contact_id', $contact->id)->whereIn('status', ['sent', 'logged']);
        $shoots = app(CrmClientShootStats::class)->forContacts(collect([$contact]))[$contact->id] ?? null;
        $quote = CrmQuote::query()->where('contact_id', $contact->id)->whereIn('status', ['draft', 'pending_approval', 'approved', 'sent'])->latest('id')->first();

        return [
            'name' => $contact->name,
            'company' => $contact->company,
            'stage' => $contact->stage,
            'stage_label' => $contact->stageLabel(),
            'days_in_stage' => $contact->stage_changed_at ? (int) $contact->stage_changed_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
            'last_contacted' => $contact->last_contacted_at?->toIso8601String(),
            'lead_source' => $contact->lead_source,
            'segment' => $contact->segment,
            'market' => trim(($contact->market_city ? $contact->market_city.', ' : '').($contact->market_state ?? ''), ', ') ?: null,
            'next_event' => $next ? ['starts_at' => $next->starts_at->toIso8601String(), 'note' => $next->note] : null,
            'last_note' => $lastNote ? ['body' => mb_substr($lastNote->body, 0, 400), 'at' => $lastNote->created_at?->toIso8601String()] : null,
            'emails_sent' => (clone $emails)->count(),
            'emails_opened' => (clone $emails)->whereNotNull('first_opened_at')->count(),
            'last_reply' => CrmEmailSend::query()->where('contact_id', $contact->id)->max('replied_at'),
            'drip' => app(CrmDripPresenter::class)->activeFor($contact),
            'shoots' => $shoots,
            'health' => $contact->health,
            'usual_gap_days' => $contact->usual_gap_days,
            'open_quote' => $quote ? ['number' => $quote->number, 'status' => $quote->status, 'total' => $quote->total, 'sent_at' => $quote->sent_at?->toIso8601String()] : null,
            'email_opt_out' => (bool) $contact->email_opt_out_at,
        ];
    }

    public function summary(array $f): string
    {
        $parts = [];
        $parts[] = $f['name'].($f['company'] ? ' ('.$f['company'].')' : '').' is in '.$f['stage_label'].($f['days_in_stage'] !== null ? ' for '.$f['days_in_stage'].' day'.($f['days_in_stage'] === 1 ? '' : 's') : '').'.';
        $parts[] = $f['last_contacted'] ? 'Last contacted '.\Illuminate\Support\Carbon::parse($f['last_contacted'])->diffForHumans().'.' : 'Not contacted yet.';
        if ($f['shoots']) {
            $parts[] = $f['shoots']['total'].' shoot'.($f['shoots']['total'] === 1 ? '' : 's').' so far'.($f['shoots']['last_shoot'] ? ', last on '.\Illuminate\Support\Carbon::parse($f['shoots']['last_shoot'])->format('j M Y') : '').'.';
        }
        if ($f['health'] === 'at_risk') {
            $parts[] = 'Overdue for a booking compared with their usual gap of '.$f['usual_gap_days'].' days.';
        }
        if ($f['open_quote']) {
            $parts[] = 'Quote '.$f['open_quote']['number'].' is '.str_replace('_', ' ', $f['open_quote']['status']).' ($'.number_format((float) $f['open_quote']['total'], 2).').';
        }
        if ($f['emails_sent'] > 0) {
            $parts[] = $f['emails_sent'].' email'.($f['emails_sent'] === 1 ? '' : 's').' sent, '.$f['emails_opened'].' opened'.($f['last_reply'] ? ', replied '.\Illuminate\Support\Carbon::parse($f['last_reply'])->diffForHumans() : '').'.';
        }
        if ($f['drip']) {
            $parts[] = 'On the '.($f['drip']['campaign']['name'] ?? 'drip').' drip.';
        }
        $parts[] = $f['next_event'] ? 'Next follow-up '.\Illuminate\Support\Carbon::parse($f['next_event']['starts_at'])->format('D j M, g:i a').'.' : 'No follow-up scheduled.';

        return implode(' ', $parts);
    }
}
