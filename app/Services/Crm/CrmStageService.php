<?php

namespace App\Services\Crm;

use App\Events\Crm\CrmContactStageChanged;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmStageChange;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stage moves. Every change is saved to the contact's history with the date,
 * old stage, new stage and who made it.
 *
 * - The four sales stages can be moved between freely.
 * - The client pool is reached only by marking the first shoot done.
 * - Lost can be set from any stage, with a required reason, and reopened to any stage.
 */
class CrmStageService
{
    public function __construct(private readonly CrmActivityLogger $activity) {}

    public function move(CrmContact $contact, string $stage, ?User $actor, string $source = 'user', ?string $reason = null): CrmContact
    {
        if (! in_array($stage, CrmContact::PIPELINE_STAGES, true)) {
            throw ValidationException::withMessages(['stage' => 'Choose one of the four sales stages. Use "First shoot completed" for the client pool and "Mark lost" for Lost.']);
        }
        if ($contact->isLost()) {
            throw ValidationException::withMessages(['stage' => 'Reopen this contact first.']);
        }

        return $this->change($contact, $stage, $actor, $source, $reason);
    }

    public function markLost(CrmContact $contact, string $reason, ?string $note, ?User $actor, string $source = 'user'): CrmContact
    {
        if (! in_array($reason, CrmContact::LOST_REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Choose why this contact was lost.']);
        }
        if ($contact->isLost()) {
            throw ValidationException::withMessages(['reason' => 'This contact is already marked lost.']);
        }

        $label = CrmContact::LOST_REASON_LABELS[$reason];

        return $this->change($contact, CrmContact::STAGE_LOST, $actor, $source, $label, [
            'stage_before_lost' => $contact->stage,
            'lost_reason' => $reason,
            'lost_note' => $note,
            'lost_at' => now(),
        ]);
    }

    public function reopen(CrmContact $contact, string $stage, ?User $actor): CrmContact
    {
        if (! $contact->isLost()) {
            throw ValidationException::withMessages(['stage' => 'Only lost contacts can be reopened.']);
        }
        if (! in_array($stage, [...CrmContact::PIPELINE_STAGES, CrmContact::STAGE_CLIENT_POOL], true)) {
            throw ValidationException::withMessages(['stage' => 'Choose the stage to return to.']);
        }
        if ($stage === CrmContact::STAGE_CLIENT_POOL && ! $contact->first_shoot_date) {
            throw ValidationException::withMessages(['stage' => 'This contact has no completed first shoot, so it cannot return to the client pool.']);
        }

        return $this->change($contact, $stage, $actor, 'user', 'Reopened', [
            'lost_reason' => null, 'lost_note' => null, 'lost_at' => null, 'stage_before_lost' => null,
        ]);
    }

    public function firstShootCompleted(CrmContact $contact, Carbon|string $date, ?string $clientType, ?User $actor, string $source = 'user'): CrmContact
    {
        if ($contact->stage !== CrmContact::STAGE_BOOKED && $source === 'user') {
            throw ValidationException::withMessages(['date' => 'Only contacts with a booked first shoot can move to the client pool.']);
        }
        if ($clientType !== null && ! in_array($clientType, CrmContact::CLIENT_TYPES, true)) {
            throw ValidationException::withMessages(['client_type' => 'Choose one-off or recurring.']);
        }

        $day = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return $this->change($contact, CrmContact::STAGE_CLIENT_POOL, $actor, $source, 'First shoot completed '.$day, [
            'first_shoot_date' => $day,
            'client_type' => $clientType ?? $contact->client_type ?? 'one_off',
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function change(CrmContact $contact, string $to, ?User $actor, string $source, ?string $reason, array $extra = []): CrmContact
    {
        $from = $contact->stage;
        if ($from === $to && $extra === []) {
            return $contact;
        }

        LockedWrite::run(fn () => DB::transaction(function () use ($contact, $from, $to, $actor, $source, $reason, $extra) {
            $contact->forceFill(array_merge($extra, ['stage' => $to, 'stage_changed_at' => now()]))->save();

            CrmStageChange::create([
                'contact_id' => $contact->id,
                'from_stage' => $from,
                'to_stage' => $to,
                'reason' => $reason,
                'changed_by' => $actor?->id,
                'source' => $source,
                'occurred_at' => now(),
            ]);

            $summary = match (true) {
                $to === CrmContact::STAGE_LOST => 'Marked lost from '.(CrmContact::STAGE_LABELS[$from] ?? $from).($reason ? ' · '.$reason : ''),
                $from === CrmContact::STAGE_LOST => 'Reopened to '.CrmContact::STAGE_LABELS[$to],
                default => 'Moved '.(CrmContact::STAGE_LABELS[$from] ?? $from).' → '.CrmContact::STAGE_LABELS[$to],
            };
            $this->activity->log($contact, 'stage', $actor, $summary.($source === 'system' ? ' (automatic)' : ''), [
                'from' => $from, 'to' => $to, 'reason' => $reason, 'source' => $source,
            ], $source === 'robbie');
        }), 'crm.contact.stage');

        DB::afterCommit(fn () => event(new CrmContactStageChanged($contact, $from, $to, $actor?->id, $source)));

        return $contact;
    }
}
