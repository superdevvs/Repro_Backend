<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Services\Schedule\ScheduleInstantResolver;
use Carbon\CarbonImmutable;

/** Classify the message's purpose, independently of the transport or automation engine. */
class ClientNotificationTimingPolicy
{
    private const IMMEDIATE = [
        'PASSWORD_RESET', 'CLIENT_EMAIL_VERIFICATION', 'CLIENT_EMAIL_VERIFIED',
        'VOICE_PHONE_VERIFICATION', 'VOICE_CALLER_VERIFICATION', 'SMS_COMPLIANCE', 'AI_SMS_AGENT',
        'ACCOUNT_CREATED', 'ACCOUNT_VERIFIED', 'PHONE_NUMBER_CHANGED_PREVIOUS', 'PHONE_NUMBER_CHANGED_NEW',
        'SHOOT_REQUESTED', 'SHOOT_SCHEDULED', 'SHOOT_BOOKED', 'SHOOT_REQUEST_APPROVED',
        'SHOOT_REQUEST_MODIFIED', 'SHOOT_REQUEST_DECLINED', 'SHOOT_CANCELLATION_REQUESTED',
        'SHOOT_CANCELLATION_APPROVED', 'SHOOT_CANCELLATION_REJECTED',
        'SHOOT_CANCELLED', 'SHOOT_CANCELED', 'SHOOT_REMOVED', 'SHOOT_DELETED', 'SHOOT_ON_HOLD',
        'SHOOT_RESCHEDULE_REQUESTED', 'SHOOT_RESCHEDULE_APPROVED', 'SHOOT_RESCHEDULE_DECLINED',
        'SHOOT_HOLD_REQUESTED', 'SHOOT_HOLD_APPROVED', 'SHOOT_HOLD_DECLINED',
        'PAYMENT_COMPLETED', 'PAYMENT_CONFIRMATION', 'PAYMENT_RECEIPT', 'PAYMENT_THANK_YOU',
        'SHOOT_PAID', 'INVOICE_PAID', 'PAYMENT_REFUNDED', 'REFUND_SUBMITTED', 'OFFLINE_PAYMENT_INTENT_DECLINED',
    ];

    private const ROUTINE = [
        'PAYMENT_DUE', 'INVOICE_DUE', 'INVOICE_OVERDUE', 'PROPERTY_CONTACT_REMINDER',
        'SHOOT_COMPLETED', 'SHOOT_READY', 'SHOOT_DELIVERED', 'SHOOT_SUMMARY',
    ];

    private const UPDATES = ['SHOOT_UPDATED', 'PHOTOGRAPHER_ASSIGNED', 'PHOTOGRAPHER_CHANGED', 'ASSIGNMENT_CHANGE'];

    public static function urgentShootUpdate(string $summary): bool
    {
        return preg_match('/^(Schedule|Date|Time|Timezone|Location|Address|Services|Photographer|Access Type|Access Contact Name|Access Contact Phone|Lockbox Code|Lockbox Location):/m', $summary) === 1;
    }

    public function requiresQuietHours(Message $message, string $timezone): bool
    {
        if ($message->direction !== 'OUTBOUND' || $message->provider === 'INTERNAL') {
            return false;
        }

        $types = $this->types($message);
        // Sending a reminder manually, or attaching it to a transaction, does not
        // turn the reminder into a receipt. The actual notification type wins.
        foreach ($types as $type) {
            if (in_array($type, self::ROUTINE, true)
                || str_contains($type, 'REMINDER')
                || str_starts_with($type, 'WEEKLY_')
                || preg_match('/(?:^|_)(SUMMARY|REPORT|DIGEST|SURVEY|PROMOTION|MARKETING|NEWSLETTER)$/', $type)) {
                return true;
            }
        }
        if (array_intersect($types, self::IMMEDIATE)) {
            return false;
        }

        $clientAction = data_get($message->metadata, 'notification.origin') === 'client_action';
        if (in_array('PAYMENT_FAILED', $types, true)) {
            return ! $clientAction;
        }
        if (array_intersect($types, self::UPDATES)) {
            $operational = ! in_array('SHOOT_UPDATED', $types, true)
                || data_get($message->metadata, 'notification.urgent') === true;

            return ! $clientAction && ! ($operational && $this->affectsUpcomingAppointmentToday($message, $timezone));
        }
        if ($clientAction && data_get($message->metadata, 'notification.intent') === 'requested_link') {
            return false;
        }

        // Conversation replies can reference a shoot/invoice without becoming
        // scheduled notifications. Template-driven sends remain classified above.
        return strtoupper((string) $message->send_source) !== 'MANUAL'
            || $message->template_id !== null || $types !== [];
    }

    /** @return list<string> */
    private function types(Message $message): array
    {
        $values = [
            data_get($message->metadata, 'notification.trigger'),
            data_get($message->metadata, 'notification.type'),
            data_get($message->metadata, 'canonical_email.email_alias'),
        ];
        $source = strtoupper((string) $message->send_source);
        if (! in_array($source, ['', 'MANUAL', 'AUTOMATION'], true)) {
            $values[] = $source;
        }
        if ($message->template_id) {
            $values[] = $message->template?->email_type;
            $values[] = $message->template?->slug;
        }
        foreach ($message->tags_json ?? [] as $tag) {
            // Historical appointment reminders predate notification metadata.
            if (str_starts_with((string) $tag, 'SHOOT_REMINDER:')) {
                $values[] = 'SHOOT_REMINDER';
            }
        }

        return array_values(array_unique(array_filter(array_map(function ($value) {
            $type = strtoupper(str_replace('-', '_', trim((string) $value)));

            return preg_replace('/^(?:SYSTEM_|AUTOMATION_)|_SMS$/', '', $type);
        }, $values))));
    }

    private function affectsUpcomingAppointmentToday(Message $message, string $timezone): bool
    {
        $shoot = $message->shoot;
        $inactive = ['requested', 'cancelled', 'canceled', 'declined', 'completed', 'delivered', 'on_hold', 'hold_on'];
        if (! $shoot || in_array(strtolower((string) $shoot->workflow_status), $inactive, true)
            || in_array(strtolower((string) $shoot->status), $inactive, true)) {
            return false;
        }
        $resolver = app(ScheduleInstantResolver::class);
        $items = $shoot->serviceItems->whereNotNull('scheduled_at');
        $appointments = $items->isNotEmpty()
            ? $items->filter(fn ($item) => ! in_array(strtolower((string) $item->workflow_status), $inactive, true))
                ->map(fn ($item) => $resolver->forServiceItem($shoot, $item))->filter()
            : collect([$resolver->forShoot($shoot)])->filter();
        $now = CarbonImmutable::now($timezone);

        return $appointments->contains(fn ($at) => $at->greaterThan($now)
            && $at->copy()->setTimezone($timezone)->isSameDay($now));
    }
}
