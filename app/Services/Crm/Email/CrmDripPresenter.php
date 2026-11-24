<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDripCampaign;
use App\Models\Crm\CrmDripCampaignVersion;
use App\Models\Crm\CrmDripEnrollment;
use App\Models\Crm\CrmEmailSend;
use App\Services\Crm\CrmPresenter;
use Illuminate\Support\Facades\DB;

/** Drip status for a contact, board cards and the drip builder. */
class CrmDripPresenter
{
    public function __construct(private readonly CrmPresenter $presenter, private readonly CrmEmailPresenter $email) {}

    public function activeFor(CrmContact $contact): ?array
    {
        $enrollment = CrmDripEnrollment::query()->where('active_contact_id', $contact->id)->first()
            ?? CrmDripEnrollment::query()->where('contact_id', $contact->id)->orderByDesc('id')->first();

        return $enrollment ? $this->enrollment($enrollment) : null;
    }

    public function enrollment(CrmDripEnrollment $e): array
    {
        $campaign = CrmDripCampaign::query()->find($e->campaign_id);
        $steps = $campaign?->publishedVersion?->steps()->get() ?? collect();
        $total = $steps->count();
        $reached = $steps->where('send_day', '<=', $e->last_sent_day)->count();
        $sends = CrmEmailSend::query()->where('source_type', 'drip')->where('source_id', $e->id)->get();

        return [
            'id' => $e->id,
            'campaign' => $campaign ? ['id' => $campaign->id, 'name' => $campaign->name, 'length' => CrmDripCampaign::LENGTH_LABELS[$campaign->length_key] ?? $campaign->length_key] : null,
            'status' => $e->status,
            'stop_reason' => $e->stop_reason,
            'step' => min($reached + ($e->status === 'active' ? 1 : 0), max($total, 1)),
            'steps_total' => $total,
            'week' => max(1, (int) floor(now()->diffInDays($e->started_at, true) / 7) + 1),
            'started_at' => $e->started_at?->toIso8601String(),
            'next_send_at' => $e->status === 'active' ? $e->next_send_at?->toIso8601String() : null,
            'emails_sent' => $sends->count(),
            'opened' => $sends->where('open_count', '>', 0)->count(),
            'clicked' => $sends->where('click_count', '>', 0)->count(),
            'sends' => $sends->map(fn ($s) => $this->email->send($s))->values()->all(),
        ];
    }

    /** @return array<int, string> contact id => "12-week drip · week 3" */
    public function shortLabels(array $contactIds): array
    {
        $rows = CrmDripEnrollment::query()->whereIn('active_contact_id', $contactIds)->get();
        $names = CrmDripCampaign::query()->whereIn('id', $rows->pluck('campaign_id'))->get()->keyBy('id');
        $out = [];
        foreach ($rows as $row) {
            $campaign = $names[$row->campaign_id] ?? null;
            $short = $campaign ? (CrmDripCampaign::LENGTH_LABELS[$campaign->length_key] ?? '').' drip' : 'Drip';
            $out[$row->contact_id] = str_replace(['12 weeks', '6 months', '12 months'], ['12-week', '6-month', '12-month'], $short).' · week '.(max(1, (int) floor(now()->diffInDays($row->started_at, true) / 7) + 1));
        }

        return $out;
    }

    public function campaign(CrmDripCampaign $c, bool $withSteps = false): array
    {
        $enrolled = CrmDripEnrollment::query()->where('campaign_id', $c->id)->where('status', 'active')->count();
        $out = [
            'id' => $c->id,
            'name' => $c->name,
            'length_key' => $c->length_key,
            'length' => CrmDripCampaign::LENGTH_LABELS[$c->length_key] ?? $c->length_key,
            'status' => $c->status,
            'enrolled' => $enrolled,
            'has_draft' => $c->draft_version_id !== null,
            'published' => $c->publishedVersion ? ['version_no' => $c->publishedVersion->version_no, 'published_at' => $c->publishedVersion->published_at?->toIso8601String(), 'published_by' => $this->presenter->user($c->publishedVersion->published_by)] : null,
        ];
        if ($withSteps) {
            $out['published_steps'] = $this->steps($c->publishedVersion);
            $out['draft_steps'] = $c->draftVersion ? $this->steps($c->draftVersion) : null;
        }

        return $out;
    }

    private function steps(?CrmDripCampaignVersion $version): array
    {
        if (! $version) {
            return [];
        }
        $steps = $version->steps()->with('template.approvedVersion')->get();
        $stats = DB::table('crm_email_sends')->where('source_type', 'drip')
            ->whereIn('template_version_id', $steps->map(fn ($s) => $s->template?->approved_version_id)->filter())
            ->selectRaw('template_version_id, COUNT(*) as n, SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END) as opened, SUM(CASE WHEN click_count > 0 THEN 1 ELSE 0 END) as clicked')
            ->groupBy('template_version_id')->get()->keyBy('template_version_id');

        return $steps->map(function ($s) use ($stats) {
            $template = $s->template;
            $stat = $template ? ($stats[$template->approved_version_id] ?? null) : null;
            $status = ! $template ? 'placeholder' : ($template->latest_version_id !== $template->approved_version_id ? 'awaiting_approval' : 'approved');

            return [
                'id' => $s->id,
                'send_day' => $s->send_day,
                'label' => $template?->name ?? $s->label,
                'template_id' => $s->template_id,
                'status' => $status,
                'subject' => $template?->approvedVersion?->subject,
                'open_rate' => $stat && $stat->n > 0 ? round($stat->opened / $stat->n * 100) : null,
                'click_rate' => $stat && $stat->n > 0 ? round($stat->clicked / $stat->n * 100) : null,
            ];
        })->all();
    }
}
