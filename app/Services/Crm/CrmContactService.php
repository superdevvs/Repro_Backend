<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmAssignmentChange;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDuplicateAlert;
use App\Models\Crm\CrmStageChange;
use App\Models\User;
use App\Support\LockedWrite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creating and editing contacts.
 *
 * - New contacts start in Outreach. A sales rep's new contact is assigned to them.
 * - Duplicates (email, phone, same name) are blocked against every contact in
 *   the system. A rep who hits one is told it exists, and admins are alerted.
 * - The owner is never taken from the form unless the user may assign.
 */
class CrmContactService
{
    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmDuplicateGuard $duplicates,
        private readonly CrmAssignmentService $assignment,
        private readonly CrmActivityLogger $activity,
        private readonly CrmNoticeService $notices,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated fields
     * @param  array{source?: string, stage?: string, confirm_different_person?: bool, assigned_rep_id?: int|null, origin?: string, skip_duplicate_check?: bool}  $options
     */
    public function create(array $data, ?User $actor, array $options = []): CrmContact
    {
        $source = $options['source'] ?? 'user';
        $canAssign = $actor ? $this->access->canAssign($actor) : true;

        if (array_key_exists('assigned_rep_id', $options) && $options['assigned_rep_id'] !== null && $actor && ! $canAssign && (int) $options['assigned_rep_id'] !== (int) $actor->id) {
            throw new AuthorizationException('Only an admin can choose who owns a contact.');
        }

        if (! ($options['skip_duplicate_check'] ?? false)) {
            $this->guardDuplicates($data, null, $actor, (bool) ($options['confirm_different_person'] ?? false), $options['origin'] ?? 'form');
        }

        if (array_key_exists('assigned_rep_id', $options)) {
            $repId = $options['assigned_rep_id'] !== null ? (int) $options['assigned_rep_id'] : null;
        } else {
            $repId = $actor?->id;
        }

        if ($repId !== null && $repId !== $actor?->id) {
            $this->assignment->assertAssignable($repId);
        }

        $stage = $options['stage'] ?? CrmContact::STAGE_OUTREACH;

        return LockedWrite::run(fn () => DB::transaction(function () use ($data, $actor, $repId, $stage, $source) {
            $contact = new CrmContact;
            $contact->fill($data);
            $this->applyNormalized($contact);
            $contact->forceFill([
                'stage' => $stage,
                'assigned_rep_id' => $repId,
                'created_by' => $actor?->id,
                'stage_changed_at' => now(),
                'lead_source' => $data['lead_source'] ?? 'other',
                'first_contact_due_at' => in_array($stage, [CrmContact::STAGE_OUTREACH, CrmContact::STAGE_INQUIRY], true) && ($data['lead_source'] ?? null) === 'website'
                    ? now()->addHours((int) config('crm.first_contact_hours', 24))
                    : null,
            ]);
            $contact->save();

            CrmStageChange::create([
                'contact_id' => $contact->id,
                'from_stage' => null,
                'to_stage' => $stage,
                'changed_by' => $actor?->id,
                'source' => $source,
                'occurred_at' => now(),
            ]);

            if ($repId !== null) {
                CrmAssignmentChange::create([
                    'contact_id' => $contact->id, 'from_rep_id' => null, 'to_rep_id' => $repId,
                    'changed_by' => $actor?->id, 'source' => $source, 'occurred_at' => now(),
                ]);
            }

            $this->activity->log($contact, 'created', $actor, 'Contact added in '.$contact->stageLabel().($actor ? ' by '.$actor->name : ''), [
                'lead_source' => $contact->lead_source,
                'source' => $source,
            ]);

            return $contact;
        }), 'crm.contact.create');
    }

    /** @param  array<string, mixed>  $data */
    public function update(CrmContact $contact, array $data, ?User $actor, bool $confirmDifferentPerson = false): CrmContact
    {
        if (array_key_exists('assigned_rep_id', $data)) {
            throw new AuthorizationException('Use the assign action to change who owns a contact.');
        }

        $identityChanged = array_intersect_key($data, array_flip(['name', 'email', 'phone'])) !== [];
        if ($identityChanged) {
            $this->guardDuplicates(array_merge($contact->only(['name', 'email', 'phone']), $data), $contact->id, $actor, $confirmDifferentPerson, 'edit');
        }

        return LockedWrite::run(function () use ($contact, $data, $actor) {
            $before = $contact->only(array_keys($data));
            $contact->fill($data);
            $this->applyNormalized($contact);
            $dirty = array_keys($contact->getDirty());
            $contact->save();

            $changed = array_values(array_intersect(array_keys($data), $dirty));
            if ($changed !== []) {
                $this->activity->log($contact, 'updated', $actor, 'Details updated: '.implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $changed)), [
                    'fields' => $changed,
                    'before' => array_intersect_key($before, array_flip($changed)),
                ]);
            }

            return $contact;
        }, 'crm.contact.update');
    }

    public function applyNormalized(CrmContact $contact): void
    {
        $contact->forceFill([
            'name_normalized' => CrmNormalizer::name($contact->name),
            'email_normalized' => CrmNormalizer::email($contact->email),
            'phone_digits' => CrmNormalizer::phone($contact->phone),
        ]);
    }

    /**
     * Throws CrmDuplicateException when the data collides with an existing contact.
     * Admins may let a name-only match through; everyone else triggers an alert.
     */
    public function guardDuplicates(array $data, ?int $ignoreId, ?User $actor, bool $confirmDifferentPerson, string $origin = 'form'): void
    {
        $match = $this->duplicates->find($data, $ignoreId);
        if (! $match) {
            return;
        }

        $isAdmin = $this->access->canViewAll($actor);
        if ($match->isOverridable() && $confirmDifferentPerson && $isAdmin) {
            return;
        }

        $notified = false;
        if (! $isAdmin && $actor) {
            CrmDuplicateAlert::create([
                'attempted_by' => $actor->id,
                'matched_contact_id' => $match->contact->id,
                'match_type' => $match->type,
                'origin' => $origin,
                'payload' => array_intersect_key($data, array_flip(['name', 'company', 'email', 'phone', 'market_state', 'market_city', 'segment'])),
            ]);
            $this->notices->notifyCrmAdmins(
                'duplicate_attempt',
                $actor->name.' tried to add a contact that already exists',
                'Matched on '.$match->type.': '.$match->contact->name,
                $match->contact->id,
            );
            $notified = true;
        }

        throw new CrmDuplicateException($match, $notified);
    }
}
