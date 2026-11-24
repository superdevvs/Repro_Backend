<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmAssignmentChange;
use App\Models\Crm\CrmContact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The only place a contact's owner changes. Requires crm-contacts assign for
 * people (sales reps never have it), logs every change, and keeps the
 * Dashboard's own "account rep" on the linked client in step.
 */
class CrmAssignmentService
{
    public function __construct(
        private readonly CrmAccess $access,
        private readonly CrmActivityLogger $activity,
        private readonly CrmNoticeService $notices,
    ) {}

    public function assign(CrmContact $contact, ?int $repId, ?User $actor, string $source = 'user', bool $notifyRep = true): CrmContact
    {
        if ($source === 'user' && ! $this->access->canAssign($actor)) {
            throw new AuthorizationException('Only an admin can change who owns a contact.');
        }

        if ($repId !== null) {
            $this->assertAssignable($repId);
        }

        $from = $contact->assigned_rep_id;
        if ($from === $repId) {
            return $contact;
        }

        $contact->forceFill(['assigned_rep_id' => $repId])->save();

        CrmAssignmentChange::create([
            'contact_id' => $contact->id,
            'from_rep_id' => $from,
            'to_rep_id' => $repId,
            'changed_by' => $actor?->id,
            'source' => $source,
            'occurred_at' => now(),
        ]);

        $toName = $repId ? (User::query()->find($repId)?->name ?? 'a rep') : 'Unassigned';
        $this->activity->log($contact, 'assigned', $actor, 'Assigned to '.$toName.($actor ? ' by '.$actor->name : ''), ['from' => $from, 'to' => $repId]);

        $this->syncClientAccountRep($contact);

        if ($notifyRep && $repId && $repId !== $actor?->id) {
            $this->notices->notify($repId, 'assigned', 'New contact assigned to you: '.$contact->name, $contact->company, $contact->id);
        }

        return $contact;
    }

    /** Sales reps and admins can own contacts. */
    public function assertAssignable(int $repId): void
    {
        $rep = User::query()->find($repId);
        if (! $rep || ! $this->access->can($rep, 'crm-contacts', 'view')) {
            throw ValidationException::withMessages(['assigned_rep_id' => 'Choose a team member who can use the CRM.']);
        }
    }

    /** Existing Dashboard screens read the client's rep from users.metadata.accountRepId. */
    private function syncClientAccountRep(CrmContact $contact): void
    {
        if (! $contact->client_user_id) {
            return;
        }
        $client = User::query()->find($contact->client_user_id);
        if (! $client) {
            return;
        }
        $metadata = is_array($client->metadata) ? $client->metadata : [];
        $metadata['accountRepId'] = $contact->assigned_rep_id;
        $client->forceFill(['metadata' => $metadata])->save();
    }
}
