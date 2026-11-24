<?php

namespace App\Services\Recruiting;

use App\Models\Crm\CrmEmailSend;
use App\Models\Crm\CrmEvent;
use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitApplicantFile;
use App\Models\Recruiting\RecruitJobPosting;
use App\Models\Recruiting\RecruitNote;
use App\Models\Recruiting\RecruitRoster;
use App\Models\Recruiting\RecruitStageChange;
use App\Services\Crm\CrmEventPresenter;
use App\Services\Crm\CrmPresenter;

/** JSON shapes for recruiting screens (frames 10, 11, 12, 15, 16). */
class RecruitPresenter
{
    public function __construct(
        private readonly CrmPresenter $presenter,
        private readonly CrmEventPresenter $events,
    ) {}

    public function card(RecruitApplicant $a, array $postings = []): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'role' => $a->role,
            'stage' => $a->stage,
            'stage_label' => RecruitApplicant::STAGE_LABELS[$a->stage] ?? $a->stage,
            'city' => $a->city,
            'state' => $a->state,
            'states' => $a->states ?? [],
            'source' => $a->source,
            'specialty' => $a->specialty,
            'flies_drone' => (bool) $a->flies_drone,
            'score' => $a->score,
            'posting' => isset($postings[$a->posting_id]) ? ['id' => $a->posting_id, 'title' => $postings[$a->posting_id]] : null,
            'days_in_stage' => $a->stage_changed_at ? (int) $a->stage_changed_at->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
            'exit_reason' => $a->exit_reason,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    public function applicant(RecruitApplicant $a, bool $canSeeDocuments): array
    {
        $posting = $a->posting_id ? RecruitJobPosting::query()->find($a->posting_id) : null;
        $files = RecruitApplicantFile::query()->where('applicant_id', $a->id)->orderByDesc('id')->get();
        $roster = $a->hired_user_id ? RecruitRoster::query()->where('user_id', $a->hired_user_id)->first() : null;

        return $this->card($a, $posting ? [$posting->id => $posting->title] : []) + [
            'email' => $a->email,
            'phone' => $a->phone,
            'metros' => $a->metros,
            'travel_radius' => $a->travel_radius,
            'portfolio_url' => $a->portfolio_url,
            'gear' => $a->gear ?? [],
            'part107_number' => $a->part107_number,
            'part107_expiry' => $a->part107_expiry?->toDateString(),
            'insurance_expiry' => $a->insurance_expiry?->toDateString(),
            'sales_experience' => $a->sales_experience,
            'scores' => $a->scores ?? [],
            'stage_before_exit' => $a->stage_before_exit,
            'hired_user' => $this->presenter->user($a->hired_user_id),
            'roster' => $roster ? $this->roster($roster) : null,
            'files' => $canSeeDocuments
                ? $files->map(fn (RecruitApplicantFile $f) => $this->file($f))->values()->all()
                : null,
            'file_count' => $files->count(),
            'stages' => RecruitStageChange::query()->where('applicant_id', $a->id)->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($s) => [
                'from' => $s->from_stage, 'to' => $s->to_stage, 'reason' => $s->reason,
                'by' => $this->presenter->user($s->changed_by), 'at' => $s->occurred_at?->toIso8601String(),
            ])->all(),
            'notes' => RecruitNote::query()->where('applicant_id', $a->id)->orderByDesc('id')->get()->map(fn ($n) => $this->note($n))->all(),
            'events' => CrmEvent::query()->where('applicant_id', $a->id)->orderBy('starts_at')->get()->map(fn ($e) => $this->events->event($e, false))->all(),
            'emails' => CrmEmailSend::query()->where('applicant_id', $a->id)->orderByDesc('id')->limit(50)->get()->map(fn ($s) => [
                'id' => $s->id, 'subject' => $s->subject, 'status' => $s->status, 'from' => $s->from_email,
                'sent_at' => $s->sent_at?->toIso8601String(), 'created_at' => $s->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function note(RecruitNote $n): array
    {
        return [
            'id' => $n->id, 'kind' => $n->kind, 'body' => $n->body, 'scores' => $n->scores,
            'author' => $this->presenter->user($n->author_id), 'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    public function file(RecruitApplicantFile $f): array
    {
        return [
            'id' => $f->id, 'kind' => $f->kind, 'name' => $f->original_name, 'mime' => $f->mime, 'size' => $f->size,
            'url' => '/api/crm/recruiting/files/'.$f->id, 'uploaded_by' => $this->presenter->user($f->uploaded_by),
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }

    public function posting(RecruitJobPosting $p, ?int $applicants = null): array
    {
        return [
            'id' => $p->id, 'role' => $p->role, 'title' => $p->title, 'state' => $p->state, 'region' => $p->region,
            'slug' => $p->slug, 'description' => $p->description, 'status' => $p->status,
            'public_url' => rtrim((string) config('crm.email.public_base_url', 'https://reprophotos.com'), '/').'/careers/'.$p->slug,
            'applicants' => $applicants, 'opened_at' => $p->opened_at?->toIso8601String(), 'closed_at' => $p->closed_at?->toIso8601String(),
        ];
    }

    public function publicPosting(RecruitJobPosting $p): array
    {
        return [
            'slug' => $p->slug, 'role' => $p->role, 'title' => $p->title, 'state' => $p->state,
            'state_name' => RecruitCoverage::STATES[$p->state] ?? $p->state, 'region' => $p->region, 'description' => $p->description,
        ];
    }

    public function roster(RecruitRoster $r): array
    {
        $soon = now()->startOfDay()->addDays((int) config('crm.expiry_reminder_days', 30));

        return [
            'user' => $this->presenter->user($r->user_id),
            'role' => $r->role,
            'states' => $r->states ?? [],
            'part107_expiry' => $r->part107_expiry?->toDateString(),
            'insurance_expiry' => $r->insurance_expiry?->toDateString(),
            'part107_expiring' => $r->part107_expiry !== null && $r->part107_expiry->lte($soon),
            'insurance_expiring' => $r->insurance_expiry !== null && $r->insurance_expiry->lte($soon),
            'active' => $r->active,
        ];
    }
}
