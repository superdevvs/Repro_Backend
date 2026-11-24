<?php

namespace App\Services\Crm\Email;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEmailEvent;
use App\Models\Crm\CrmEmailSend;
use App\Services\Crm\CrmActivityLogger;
use Illuminate\Support\Carbon;

/**
 * Resend webhook events for CRM sends: delivered, opened, clicked, bounced,
 * complained, failed. A hard bounce or a spam complaint suppresses the
 * address everywhere (which stops any drip). Each svix id is recorded once.
 */
class CrmEmailEventRecorder
{
    public function __construct(
        private readonly CrmSuppression $suppression,
        private readonly CrmActivityLogger $activity,
    ) {}

    public function record(CrmEmailSend $send, string $event, array $payload, string $eventId): void
    {
        if ($eventId !== '' && CrmEmailEvent::query()->where('provider_event_id', $eventId)->exists()) {
            return;
        }
        $type = str_starts_with($event, 'email.') ? substr($event, 6) : $event;
        $at = isset($payload['created_at']) ? Carbon::parse($payload['created_at']) : now();
        $link = data_get($payload, 'data.click.link');

        CrmEmailEvent::create([
            'send_id' => $send->id,
            'provider_event_id' => $eventId !== '' ? $eventId : null,
            'type' => mb_substr($type, 0, 24),
            'link' => is_string($link) ? mb_substr($link, 0, 500) : null,
            'payload' => ['bounce' => data_get($payload, 'data.bounce'), 'reason' => data_get($payload, 'data.failed.reason') ?? data_get($payload, 'data.suppressed.reason')],
            'occurred_at' => $at,
        ]);

        $contact = $send->contact_id ? CrmContact::unscopedForSystem()->find($send->contact_id) : null;
        switch ($type) {
            case 'delivered':
                $send->forceFill(['delivered_at' => $send->delivered_at ?? $at, 'status' => in_array($send->status, ['sent', 'uncertain'], true) ? 'sent' : $send->status])->save();
                break;
            case 'opened':
                $send->forceFill(['first_opened_at' => $send->first_opened_at ?? $at, 'open_count' => $send->open_count + 1])->save();
                break;
            case 'clicked':
                $send->forceFill(['click_count' => $send->click_count + 1, 'first_opened_at' => $send->first_opened_at ?? $at])->save();
                break;
            case 'bounced':
                $send->forceFill(['bounced_at' => $at, 'status' => 'bounced'])->save();
                // Soft bounces (mailbox full, greylisting) do not suppress the address.
                if (strtolower((string) data_get($payload, 'data.bounce.type', 'permanent')) !== 'transient') {
                    $this->suppression->suppress($send->to_email, 'bounce', 'resend', $contact?->id);
                }
                break;
            case 'complained':
                $send->forceFill(['complained_at' => $at, 'status' => 'complained'])->save();
                $this->suppression->suppress($send->to_email, 'complaint', 'resend', $contact?->id);
                break;
            case 'failed':
            case 'suppressed':
                $send->forceFill(['status' => 'failed', 'error' => mb_substr((string) (data_get($payload, 'data.failed.reason') ?? data_get($payload, 'data.suppressed.reason') ?? 'Delivery failed'), 0, 500)])->save();
                if ($contact) {
                    $this->activity->log($contact, 'email_failed', null, 'Email not delivered: '.$send->subject, ['send_id' => $send->id]);
                }
                break;
        }
    }
}
