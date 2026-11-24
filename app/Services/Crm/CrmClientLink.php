<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Connects CRM contacts to Dashboard clients and keeps their stage in step with
 * real shoots:
 *
 * - a shoot booked for a contact in Outreach, Inquiry or Estimate moves them to
 *   Booked 1st shoot (a lost contact who books again is reopened there);
 * - the first completed shoot moves them to the client pool with its date.
 *
 * Import drafts, internal test shoots and imports with notifications muted are
 * ignored. Every automatic move is recorded with source "system".
 */
class CrmClientLink
{
    public function __construct(
        private readonly CrmStageService $stages,
        private readonly CrmContactService $contacts,
        private readonly CrmActivityLogger $activity,
        private readonly CrmAssignmentService $assignment,
    ) {}

    public static function rawRepIdFor(User $client): ?int
    {
        $metadata = is_array($client->metadata) ? $client->metadata : [];
        $repId = $metadata['accountRepId'] ?? $metadata['account_rep_id'] ?? $metadata['repId'] ?? $metadata['rep_id'] ?? null;
        if (is_numeric($repId) && (int) $repId > 0) {
            return (int) $repId;
        }

        $latest = DB::table('shoots')->where('client_id', $client->id)->whereNotNull('rep_id')->orderByDesc('created_at')->value('rep_id');

        return $latest ? (int) $latest : null;
    }

    public function handleShoot(Shoot $shoot, bool $created): void
    {
        if (! config('crm.enabled') || ! $shoot->client_id) {
            return;
        }
        if ($shoot->isImportDraft() || $shoot->isInternalTestShoot() || $shoot->suppressesExternalNotifications()) {
            return;
        }

        $status = strtolower((string) ($shoot->workflow_status ?: $shoot->status));
        if (in_array($status, CrmClientShootStats::IGNORED_STATUSES, true)) {
            return;
        }

        $client = User::query()->find($shoot->client_id);
        if (! $client) {
            return;
        }

        $contact = $this->findOrLinkContact($client) ?? ($created ? $this->createForClient($client, $shoot) : null);
        if (! $contact) {
            return;
        }

        $completed = $shoot->completed_at !== null || $status === Shoot::STATUS_DELIVERED;

        if ($contact->isLost()) {
            $this->stages->reopen($contact, CrmContact::STAGE_BOOKED, null);
            $this->activity->log($contact, 'shoot_booked', null, 'Reopened automatically: a shoot was booked for '.($shoot->address ?? 'a property'), ['shoot_id' => $shoot->id]);
        } elseif (in_array($contact->stage, [CrmContact::STAGE_OUTREACH, CrmContact::STAGE_INQUIRY, CrmContact::STAGE_ESTIMATE], true)) {
            $this->stages->move($contact, CrmContact::STAGE_BOOKED, null, 'system', 'Shoot booked in the Dashboard');
            $this->activity->log($contact, 'shoot_booked', null, 'Shoot booked'.($shoot->scheduled_at ? ' for '.$shoot->scheduled_at->format('D j M Y') : ''), ['shoot_id' => $shoot->id]);
        }

        if ($completed && $contact->stage === CrmContact::STAGE_BOOKED && ! $contact->first_shoot_date) {
            $date = $shoot->completed_at ?? now();
            $type = $this->completedCount($client->id) > 1 ? 'recurring' : 'one_off';
            $this->stages->firstShootCompleted($contact, $date, $type, null, 'system');
        } elseif ($completed && $contact->stage === CrmContact::STAGE_CLIENT_POOL && $contact->client_type === 'one_off' && $this->completedCount($client->id) > 1) {
            $contact->forceFill(['client_type' => 'recurring'])->save();
            $this->activity->log($contact, 'updated', null, 'Client type changed to Recurring after a second completed shoot');
        }
    }

    /** Find the contact for a client, linking an unlinked contact with the same email. */
    public function findOrLinkContact(User $client): ?CrmContact
    {
        $contact = CrmContact::unscopedForSystem()->where('client_user_id', $client->id)->first();
        if ($contact) {
            return $contact;
        }

        $email = CrmNormalizer::email($client->email);
        if (! $email) {
            return null;
        }
        $contact = CrmContact::unscopedForSystem()->whereNull('client_user_id')->where('email_normalized', $email)->orderBy('id')->first();
        if ($contact) {
            $contact->forceFill(['client_user_id' => $client->id])->save();
            $this->activity->log($contact, 'linked', null, 'Linked to Dashboard client account '.$client->email, ['client_user_id' => $client->id]);
        }

        return $contact;
    }

    private function createForClient(User $client, Shoot $shoot): ?CrmContact
    {
        if (! config('crm.auto_create_from_shoots', true) || strtolower((string) $client->role) !== 'client') {
            return null;
        }

        $repId = self::rawRepIdFor($client) ?? ($shoot->rep_id ? (int) $shoot->rep_id : null);
        if ($repId && ! $this->assignment->isAssignable($repId)) {
            $repId = null;
        }

        $contact = $this->contacts->create($this->clientData($client), null, [
            'source' => 'system',
            'stage' => CrmContact::STAGE_INQUIRY,
            'assigned_rep_id' => $repId,
            'skip_duplicate_check' => true,
        ]);
        $contact->forceFill(['client_user_id' => $client->id])->save();
        $this->flagSharedIdentity($contact);

        return $contact;
    }

    public function clientData(User $client): array
    {
        return array_filter([
            'name' => trim((string) $client->name) ?: (string) $client->email,
            'company' => $client->company_name ?: null,
            'email' => $client->email ?: null,
            'phone' => $client->phonenumber ?: ($client->phone ?: null),
            'address' => $client->address ?: null,
            'market_state' => $client->state ?: null,
            'market_city' => $client->city ?: null,
            'segment' => 'residential',
            'lead_source' => 'other',
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Clients that share an office email or phone are kept but flagged for an admin. */
    public function flagSharedIdentity(CrmContact $contact): void
    {
        $reasons = $contact->review_reasons ?? [];
        $others = CrmContact::unscopedForSystem()->whereKeyNot($contact->id)
            ->where(function ($q) use ($contact) {
                $q->when($contact->email_normalized, fn ($q) => $q->orWhere('email_normalized', $contact->email_normalized))
                    ->when($contact->phone_digits, fn ($q) => $q->orWhere('phone_digits', $contact->phone_digits));
            })
            ->when(! $contact->email_normalized && ! $contact->phone_digits, fn ($q) => $q->whereRaw('1 = 0'))
            ->pluck('id');
        if ($others->isNotEmpty()) {
            $reasons[] = 'shares_identity';
            $contact->forceFill(['needs_review' => true, 'review_reasons' => array_values(array_unique($reasons))])->save();
        }
    }

    public function completedCount(int $clientId): int
    {
        return DB::table('shoots')->where('client_id', $clientId)->whereNotNull('completed_at')
            ->whereNotIn('status', CrmClientShootStats::IGNORED_STATUSES)
            ->where(fn ($q) => $q->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_INTERNAL_TEST))
            ->count();
    }
}
