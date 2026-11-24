<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmActivity;
use App\Models\Crm\CrmContact;
use App\Models\Shoot;
use App\Models\User;

/**
 * Leads from reprophotos.com (owner decision 7): a contact-form inquiry or a
 * booking becomes a CRM contact with lead source Website. A new person with
 * no rep waits in the admin-only Unassigned queue with a first-contact
 * target; an existing contact gets the inquiry on their history and their
 * rep is told. Replays of the same website reference do nothing.
 */
class CrmWebsiteIntake
{
    public function __construct(
        private readonly CrmContactService $contacts,
        private readonly CrmActivityLogger $activity,
        private readonly CrmNoticeService $notices,
    ) {}

    /** @param  array{first_name: string, last_name: string, email: string, phone?: string|null, message?: string|null, sms_consent?: bool}  $form */
    public function inquiry(array $form, string $reference): ?CrmContact
    {
        if (! config('crm.enabled') || $this->seen($reference)) {
            return null;
        }
        $name = trim($form['first_name'].' '.$form['last_name']);
        $data = ['name' => $name, 'email' => $form['email'], 'phone' => $form['phone'] ?? null, 'segment' => 'residential', 'lead_source' => 'website'];

        return $this->record($data, 'website_inquiry', 'Website inquiry', [
            'message' => mb_substr((string) ($form['message'] ?? ''), 0, 5000), 'reference' => strtolower($reference), 'sms_consent' => (bool) ($form['sms_consent'] ?? false),
        ], (bool) ($form['sms_consent'] ?? false));
    }

    /** A booking made on the website. The shoot observer has already linked or created the contact. */
    public function booking(Shoot $shoot, ?string $reference, ?string $message = null): ?CrmContact
    {
        if (! config('crm.enabled') || ! $shoot->client_id || ($reference && $this->seen($reference))) {
            return null;
        }
        $client = User::query()->find($shoot->client_id);
        if (! $client) {
            return null;
        }
        $contact = app(CrmClientLink::class)->findOrLinkContact($client);
        if (! $contact) {
            return null;
        }
        if ($contact->wasRecentlyCreated || $contact->created_at?->gt(now()->subMinutes(10))) {
            $contact->forceFill(['lead_source' => 'website', 'first_contact_due_at' => $contact->assigned_rep_id ? null : $this->dueAt()])->save();
        }
        $this->activity->log($contact, 'website_booking', null, 'Booked on the website'.($shoot->address ? ': '.$shoot->address : ''), array_filter([
            'shoot_id' => $shoot->id, 'reference' => $reference ? strtolower($reference) : null, 'message' => $message ? mb_substr($message, 0, 5000) : null,
        ]));
        $this->tell($contact, 'website_booking', $contact->name.' booked on the website');

        return $contact;
    }

    private function record(array $data, string $type, string $summary, array $payload, bool $smsConsent): CrmContact
    {
        $match = app(CrmDuplicateGuard::class)->find($data);
        if ($match && $match->type !== 'name') {
            $contact = $match->contact;
            $this->activity->log($contact, $type, null, $summary, $payload);
            if ($smsConsent && ! $contact->sms_consent) {
                $contact->forceFill(['sms_consent' => true, 'sms_consent_at' => now(), 'sms_consent_source' => 'website'])->save();
            }
            if ($contact->isLost()) {
                app(CrmStageService::class)->reopen($contact, CrmContact::STAGE_INQUIRY, null);
            }
            $this->tell($contact, $type, $contact->name.' sent a website inquiry');

            return $contact;
        }

        // A same-name person is a new contact, flagged for an admin to check.
        $contact = $this->contacts->create($data, null, ['source' => 'system', 'stage' => CrmContact::STAGE_INQUIRY, 'assigned_rep_id' => null, 'skip_duplicate_check' => true, 'origin' => 'website']);
        $contact->forceFill(array_filter([
            'first_contact_due_at' => $this->dueAt(),
            'sms_consent' => $smsConsent ?: null, 'sms_consent_at' => $smsConsent ? now() : null, 'sms_consent_source' => $smsConsent ? 'website' : null,
            'needs_review' => $match ? true : null, 'review_reasons' => $match ? ['Same name as an existing contact'] : null,
        ], fn ($v) => $v !== null))->save();
        $this->activity->log($contact, $type, null, $summary, $payload);
        $this->tell($contact, $type, 'New website lead: '.$contact->name);

        return $contact;
    }

    private function tell(CrmContact $contact, string $type, string $title): void
    {
        $body = 'Contact within '.(int) config('crm.first_contact_hours', 24).' hours.';
        if ($contact->assigned_rep_id) {
            $this->notices->notify((int) $contact->assigned_rep_id, $type, $title, $body, $contact->id);
        } else {
            $this->notices->notifyCrmAdmins($type, $title, 'Waiting in Unassigned. '.$body, $contact->id);
        }
    }

    private function seen(string $reference): bool
    {
        return CrmActivity::query()->whereIn('type', ['website_inquiry', 'website_booking'])->where('data->reference', strtolower($reference))->exists();
    }

    private function dueAt()
    {
        return now()->addHours((int) config('crm.first_contact_hours', 24));
    }
}
