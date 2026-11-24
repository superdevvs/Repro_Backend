<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reports (frame F2). Every number runs through the owner scope: a rep's
 * report covers only their contacts; admins can narrow to one rep.
 */
class CrmReports
{
    public function build(Carbon $from, Carbon $to, ?int $repId = null): array
    {
        $contacts = CrmContact::query()->when($repId, fn ($q) => $q->where('assigned_rep_id', $repId));
        $ids = (clone $contacts)->select('id');
        $period = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $entered = DB::table('crm_stage_changes')->whereIn('contact_id', $ids)->whereBetween('occurred_at', $period)
            ->selectRaw('to_stage, COUNT(DISTINCT contact_id) as c')->groupBy('to_stage')->pluck('c', 'to_stage');
        $funnel = collect([...CrmContact::PIPELINE_STAGES, CrmContact::STAGE_CLIENT_POOL])->map(fn ($s) => [
            'stage' => $s, 'label' => CrmContact::STAGE_LABELS[$s] ?? $s, 'entered' => (int) ($entered[$s] ?? 0),
        ])->values();
        $conversion = [];
        for ($i = 0; $i < $funnel->count() - 1; $i++) {
            $a = $funnel[$i]['entered'];
            $b = $funnel[$i + 1]['entered'];
            $conversion[] = ['from' => $funnel[$i]['stage'], 'to' => $funnel[$i + 1]['stage'], 'rate' => $a > 0 ? round(min(1, $b / $a), 3) : null];
        }

        $lost = (clone $contacts)->where('stage', CrmContact::STAGE_LOST)->whereBetween('lost_at', $period)
            ->selectRaw('lost_reason, COUNT(*) as c')->groupBy('lost_reason')->pluck('c', 'lost_reason');
        $sources = (clone $contacts)->whereBetween('created_at', $period)
            ->selectRaw("lead_source, COUNT(*) as total, SUM(CASE WHEN stage = 'client_pool' THEN 1 ELSE 0 END) as clients")
            ->groupBy('lead_source')->get();

        $firstShootDays = DB::table('crm_contacts as c')->whereIn('c.id', $ids)->whereNotNull('c.first_shoot_date')
            ->whereBetween('c.first_shoot_date', [$period[0]->toDateString(), $period[1]->toDateString()])
            ->get(['c.created_at', 'c.first_shoot_date'])
            ->map(fn ($r) => max(0, Carbon::parse($r->created_at)->startOfDay()->diffInDays(Carbon::parse($r->first_shoot_date))))
            ->sort()->values();

        $events = CrmEvent::query()->whereIn('contact_id', $ids)->whereBetween('completed_at', $period)->where('status', 'done')->count();
        $emails = DB::table('crm_email_sends')->whereIn('contact_id', $ids)->whereBetween('created_at', $period)->whereIn('status', ['sent', 'logged'])
            ->selectRaw('COUNT(*) as sent, SUM(CASE WHEN first_opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened, SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied')->first();
        $quotes = CrmQuote::query()->whereIn('contact_id', $ids)->whereBetween('sent_at', $period)
            ->selectRaw("COUNT(*) as sent, SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted, SUM(CASE WHEN status = 'accepted' THEN total ELSE 0 END) as accepted_value")->first();

        return [
            'period' => ['from' => $period[0]->toDateString(), 'to' => $period[1]->toDateString()],
            'funnel' => $funnel->all(),
            'conversion' => $conversion,
            'lost_reasons' => collect(CrmContact::LOST_REASONS)->map(fn ($r) => ['reason' => $r, 'label' => CrmContact::LOST_REASON_LABELS[$r] ?? $r, 'count' => (int) ($lost[$r] ?? 0)])->values()->all(),
            'lead_sources' => $sources->map(fn ($r) => ['source' => $r->lead_source, 'label' => CrmContact::LEAD_SOURCE_LABELS[$r->lead_source] ?? $r->lead_source, 'contacts' => (int) $r->total, 'clients' => (int) $r->clients])->values()->all(),
            'time_to_first_shoot_days' => $firstShootDays->isEmpty() ? null : (float) $firstShootDays->median(),
            'activity' => [
                'follow_ups_done' => $events,
                'emails_sent' => (int) ($emails->sent ?? 0),
                'emails_opened' => (int) ($emails->opened ?? 0),
                'emails_replied' => (int) ($emails->replied ?? 0),
                'notes' => DB::table('crm_notes')->whereIn('contact_id', $ids)->whereBetween('created_at', $period)->count(),
            ],
            'quotes' => ['sent' => (int) ($quotes->sent ?? 0), 'accepted' => (int) ($quotes->accepted ?? 0), 'accepted_value' => round((float) ($quotes->accepted_value ?? 0), 2)],
            'by_rep' => $repId ? null : $this->byRep($ids, $period),
        ];
    }

    private function byRep($ids, array $period): array
    {
        if (app(CrmAccess::class)->currentRestriction() !== null) {
            return [];
        }
        $rows = DB::table('crm_contacts')->whereIn('id', $ids)->whereNotNull('assigned_rep_id')
            ->selectRaw("assigned_rep_id, COUNT(*) as contacts, SUM(CASE WHEN stage = 'client_pool' THEN 1 ELSE 0 END) as clients, SUM(CASE WHEN stage IN ('outreach','inquiry','estimate','booked') THEN 1 ELSE 0 END) as pipeline")
            ->groupBy('assigned_rep_id')->get();
        $booked = DB::table('crm_stage_changes as s')->join('crm_contacts as c', 'c.id', '=', 's.contact_id')
            ->whereIn('s.contact_id', $ids)->where('s.to_stage', CrmContact::STAGE_BOOKED)->whereBetween('s.occurred_at', $period)
            ->selectRaw('c.assigned_rep_id, COUNT(DISTINCT s.contact_id) as n')->groupBy('c.assigned_rep_id')->pluck('n', 'assigned_rep_id');
        $presenter = app(CrmPresenter::class);

        return $rows->map(fn ($r) => [
            'rep' => $presenter->user((int) $r->assigned_rep_id),
            'contacts' => (int) $r->contacts, 'pipeline' => (int) $r->pipeline, 'clients' => (int) $r->clients,
            'booked_in_period' => (int) ($booked[$r->assigned_rep_id] ?? 0),
        ])->sortByDesc('booked_in_period')->values()->all();
    }
}
