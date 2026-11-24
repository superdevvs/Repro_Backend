<?php

namespace App\Services\Recruiting;

use App\Models\Recruiting\RecruitApplicant;
use App\Models\Recruiting\RecruitJobPosting;
use App\Models\Recruiting\RecruitNote;
use App\Models\Recruiting\RecruitStageChange;
use App\Models\User;
use App\Services\Crm\CrmNoticeService;
use App\Services\Crm\CrmNormalizer;
use App\Support\LockedWrite;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applicants: add, edit, move through the stages, leave and reopen, notes and
 * interview feedback. Hiring lives in RecruitHireService. Nothing is deleted.
 */
class RecruitApplicantService
{
    public const FIELDS = [
        'role', 'name', 'email', 'phone', 'city', 'state', 'posting_id', 'source', 'states', 'metros', 'travel_radius',
        'portfolio_url', 'gear', 'flies_drone', 'part107_number', 'part107_expiry', 'insurance_expiry', 'sales_experience', 'specialty',
    ];

    public const SCORE_KEYS = ['quality', 'communication', 'reliability', 'fit'];

    public function __construct(private readonly CrmNoticeService $notices) {}

    public function create(array $data, ?User $actor, string $origin = 'manual'): RecruitApplicant
    {
        $attributes = $this->attributes($data);
        $attributes['stage'] = 'applied';
        $attributes['stage_changed_at'] = now();
        $attributes['created_by'] = $actor?->id;
        $attributes['external_reference'] = $data['external_reference'] ?? null;

        $applicant = LockedWrite::run(fn () => DB::transaction(function () use ($attributes, $actor, $origin) {
            $applicant = RecruitApplicant::create($attributes);
            $this->recordStage($applicant, null, 'applied', $actor, $origin === 'website' ? 'Applied on the website' : null);

            return $applicant;
        }), 'recruit.applicant.create');

        if ($origin === 'website') {
            $this->notices->notifyRecruiters('recruit_applied', 'New '.$this->roleLabel($applicant->role).' application: '.$applicant->name, $applicant->state ? 'From '.$applicant->state : null, ['applicant_id' => $applicant->id]);
        }

        return $applicant;
    }

    /**
     * A website application. The same email applying again updates the open
     * record (and reopens a past applicant) rather than creating a second one.
     */
    public function fromWebsite(array $data, ?RecruitJobPosting $posting): RecruitApplicant
    {
        $data['source'] = 'website';
        $data['posting_id'] = $posting?->id;
        $data['role'] = $posting?->role ?? ($data['role'] ?? 'photographer');

        if (! empty($data['external_reference'])) {
            $existing = RecruitApplicant::query()->where('external_reference', $data['external_reference'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $email = CrmNormalizer::email($data['email'] ?? null);
        $existing = $email ? RecruitApplicant::query()->where('email_normalized', $email)->where('role', $data['role'])->latest('id')->first() : null;
        if ($existing && $existing->stage !== 'hired') {
            $fill = collect($this->attributes($data))->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->except(['role'])->all();
            $existing->fill($fill)->save();
            $this->addNote($existing, 'Applied again on the website'.($posting ? ' ('.$posting->title.')' : '').'.', null, 'system', null);
            if ($existing->stage === RecruitApplicant::EXIT) {
                $this->reopen($existing, null);
            }
            $this->notices->notifyRecruiters('recruit_applied', $existing->name.' applied again', null, ['applicant_id' => $existing->id]);

            return $existing->fresh();
        }

        return $this->create($data, null, 'website');
    }

    public function update(RecruitApplicant $applicant, array $data, ?User $actor): RecruitApplicant
    {
        $applicant->fill($this->attributes($data, $applicant))->save();

        return $applicant;
    }

    public function move(RecruitApplicant $applicant, string $stage, ?User $actor, ?string $reason = null): RecruitApplicant
    {
        if (! in_array($stage, RecruitApplicant::STAGES, true) || $stage === 'hired') {
            throw ValidationException::withMessages(['stage' => $stage === 'hired' ? 'Use Hire to hire someone, so their account is set up.' : 'Unknown stage.']);
        }
        if ($applicant->stage === 'hired') {
            throw ValidationException::withMessages(['stage' => 'This person has already been hired.']);
        }
        if ($applicant->stage === RecruitApplicant::EXIT) {
            throw ValidationException::withMessages(['stage' => 'Reopen this applicant before moving them.']);
        }
        if ($applicant->stage === $stage) {
            return $applicant;
        }

        return $this->transition($applicant, $stage, $actor, $reason);
    }

    public function exit(RecruitApplicant $applicant, string $reason, ?User $actor): RecruitApplicant
    {
        if ($applicant->stage === 'hired' || $applicant->stage === RecruitApplicant::EXIT) {
            throw ValidationException::withMessages(['stage' => 'This applicant is not in progress.']);
        }

        return LockedWrite::run(fn () => DB::transaction(function () use ($applicant, $reason, $actor) {
            $from = $applicant->stage;
            $applicant->forceFill(['stage' => RecruitApplicant::EXIT, 'stage_before_exit' => $from, 'exit_reason' => $reason, 'stage_changed_at' => now()])->save();
            $this->recordStage($applicant, $from, RecruitApplicant::EXIT, $actor, $reason);

            return $applicant;
        }), 'recruit.applicant.exit');
    }

    public function reopen(RecruitApplicant $applicant, ?User $actor): RecruitApplicant
    {
        if ($applicant->stage !== RecruitApplicant::EXIT) {
            throw ValidationException::withMessages(['stage' => 'Only applicants who are not moving forward can be reopened.']);
        }
        $to = $applicant->stage_before_exit && in_array($applicant->stage_before_exit, RecruitApplicant::STAGES, true) && $applicant->stage_before_exit !== 'hired'
            ? $applicant->stage_before_exit
            : 'applied';

        return LockedWrite::run(fn () => DB::transaction(function () use ($applicant, $to, $actor) {
            $applicant->forceFill(['stage' => $to, 'stage_before_exit' => null, 'exit_reason' => null, 'stage_changed_at' => now()])->save();
            $this->recordStage($applicant, RecruitApplicant::EXIT, $to, $actor, 'Reopened');

            return $applicant;
        }), 'recruit.applicant.reopen');
    }

    /** @param  array<string, int>|null  $scores  1 to 5 for quality, communication, reliability, fit */
    public function addNote(RecruitApplicant $applicant, string $body, ?array $scores, string $kind, ?User $actor): RecruitNote
    {
        $clean = null;
        if ($scores) {
            $clean = collect($scores)->only(self::SCORE_KEYS)->map(fn ($v) => max(1, min(5, (int) $v)))->all() ?: null;
        }
        $note = RecruitNote::create([
            'applicant_id' => $applicant->id,
            'author_id' => $actor?->id,
            'kind' => $clean ? 'feedback' : $kind,
            'body' => $body,
            'scores' => $clean,
        ]);
        if ($clean) {
            $this->rescore($applicant);
        }

        return $note;
    }

    public function rescore(RecruitApplicant $applicant): void
    {
        $sets = RecruitNote::query()->where('applicant_id', $applicant->id)->whereNotNull('scores')->pluck('scores');
        $perKey = [];
        foreach ($sets as $set) {
            foreach ((array) $set as $key => $value) {
                $perKey[$key][] = (int) $value;
            }
        }
        $averages = collect($perKey)->map(fn ($values) => round(array_sum($values) / max(1, count($values)), 1))->all();
        $overall = $averages ? round(array_sum($averages) / count($averages), 1) : null;
        $applicant->forceFill(['scores' => $averages ?: null, 'score' => $overall])->save();
    }

    public function transition(RecruitApplicant $applicant, string $to, ?User $actor, ?string $reason = null): RecruitApplicant
    {
        return LockedWrite::run(fn () => DB::transaction(function () use ($applicant, $to, $actor, $reason) {
            $from = $applicant->stage;
            $applicant->forceFill(['stage' => $to, 'stage_changed_at' => now()])->save();
            $this->recordStage($applicant, $from, $to, $actor, $reason);

            return $applicant;
        }), 'recruit.applicant.move');
    }

    public function roleLabel(string $role): string
    {
        return $role === 'sales_rep' ? 'sales rep' : 'photographer';
    }

    private function recordStage(RecruitApplicant $applicant, ?string $from, string $to, ?User $actor, ?string $reason): void
    {
        RecruitStageChange::create([
            'applicant_id' => $applicant->id,
            'from_stage' => $from,
            'to_stage' => $to,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'changed_by' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }

    private function attributes(array $data, ?RecruitApplicant $existing = null): array
    {
        $out = collect($data)->only(self::FIELDS)->all();
        if (array_key_exists('email', $out)) {
            $out['email'] = $out['email'] !== null ? trim((string) $out['email']) : null;
            $out['email_normalized'] = CrmNormalizer::email($out['email']);
        }
        if (array_key_exists('states', $out)) {
            $out['states'] = collect((array) $out['states'])->map(fn ($s) => RecruitCoverage::stateCode((string) $s))->filter()->unique()->values()->all();
        }
        if (array_key_exists('state', $out) && $out['state'] !== null) {
            $out['state'] = RecruitCoverage::stateCode((string) $out['state']) ?? $out['state'];
        }
        if (array_key_exists('gear', $out)) {
            $out['gear'] = collect((array) $out['gear'])->map(fn ($g) => trim((string) $g))->filter()->take(30)->values()->all();
        }
        if (isset($out['part107_number']) && $out['part107_number'] !== '' && ! array_key_exists('flies_drone', $out)) {
            $out['flies_drone'] = true;
        }

        return $out;
    }
}
