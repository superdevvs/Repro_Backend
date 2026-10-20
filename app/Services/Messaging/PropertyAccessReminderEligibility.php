<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\Shoot;
use App\Services\Schedule\ScheduleInstantResolver;

/** Use the saved access choice for scheduling and again before provider delivery. */
class PropertyAccessReminderEligibility
{
    public function presence(array $details): ?string
    {
        $value = strtolower(trim((string) ($details['presenceOption'] ?? $details['presence_option'] ?? '')));

        return in_array($value, ['self', 'other', 'lockbox'], true) ? $value : null;
    }

    public function missing(array $details): bool
    {
        $code = trim((string) ($details['lockboxCode'] ?? $details['lockbox_code'] ?? ''));

        return match ($this->presence($details)) {
            'self' => false,
            'other' => trim((string) ($details['accessContactName'] ?? $details['access_contact_name'] ?? '')) === ''
                || trim((string) ($details['accessContactPhone'] ?? $details['access_contact_phone'] ?? '')) === '',
            // Existing code-only bookings are sufficient; a location alone is not an entry code.
            default => $code === '',
        };
    }

    public function storedReminderIsStale(Message $message): bool
    {
        if ($message->send_source !== 'AUTOMATION') return false;
        $isReminder = data_get($message->metadata, 'notification.trigger') === 'PROPERTY_CONTACT_REMINDER'
            || in_array($message->template?->slug, ['property-contact-reminder', 'property-contact-reminder-sms'], true)
            || collect((array) $message->tags_json)->contains(fn ($tag) => is_string($tag)
                && (str_starts_with($tag, 'PROPERTY_CONTACT_REMINDER:') || str_contains($tag, ':PROPERTY_CONTACT_REMINDER:')));
        if (! $isReminder) return false;

        $shoot = Shoot::find($message->related_shoot_id);
        if (! $shoot || ! $this->missing($shoot->property_details ?? [])) return true;
        $inactive = ['cancelled', 'canceled', 'declined', 'completed', 'delivered', 'hold_on', 'on_hold', 'requested'];
        if (in_array(strtolower((string) $shoot->status), $inactive, true)
            || in_array(strtolower((string) $shoot->workflow_status), $inactive, true)) return true;
        if ($message->related_account_id && (int) $message->related_account_id !== (int) $shoot->client_id) return true;
        $appointment = app(ScheduleInstantResolver::class)->forShoot($shoot);
        $expected = data_get($message->metadata, 'notification.appointment_at');

        return ! $appointment || $appointment->isPast() || ($expected && ! $appointment->equalTo($expected));
    }
}
