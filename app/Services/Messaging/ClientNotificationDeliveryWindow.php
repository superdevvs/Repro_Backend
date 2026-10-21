<?php

namespace App\Services\Messaging;

use App\Models\Contact;
use App\Models\Message;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Schedule\ScheduleInstantResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Client notification mornings are evaluated again immediately before provider delivery. */
class ClientNotificationDeliveryWindow
{
    public function nextAllowedAt(Message $message): ?CarbonImmutable
    {
        if ($message->direction !== 'OUTBOUND' || $message->provider === 'INTERNAL') {
            return null;
        }

        $next = null;
        foreach ($this->clientTimezones($message) as $timezone) {
            if (! app(ClientNotificationTimingPolicy::class)->requiresQuietHours($message, $timezone)) {
                continue;
            }
            $local = CarbonImmutable::now($timezone);
            $opens = $local->startOfDay()->setTime(9, 0);
            $appointment = $this->appointment($message);
            if ($appointment) {
                $appointment = $appointment->setTimezone($timezone);
                if ($appointment->isSameDay($local) && $appointment->format('H:i') >= '09:00'
                    && $appointment->format('H:i') <= '11:00') {
                    $opens = $appointment->subHours(2);
                }
            }
            if ($local->lt($opens) && (! $next || $opens->gt($next))) {
                $next = $opens->utc();
            }
        }

        return $next;
    }

    public function staleAppointment(Message $message): bool
    {
        if (! $this->isReminder($message) || ! $message->related_shoot_id || $this->clientTimezones($message) === []) {
            return false;
        }

        $appointment = $this->appointment($message);

        return ! $appointment || $appointment->lte(CarbonImmutable::now());
    }

    /** Release only a schedule owned by quiet hours, never a later explicit reschedule. */
    public function canReleaseQuietHoursDeferral(Message $message): bool
    {
        if ($message->status !== 'SCHEDULED' || ! $message->scheduled_at
            || data_get($message->metadata, 'delivery.deferred_reason') !== 'client_notification_quiet_hours') {
            return false;
        }
        $until = data_get($message->metadata, 'delivery.deferred_until');
        if (! is_string($until) || $until === '') {
            return false;
        }
        try {
            return $message->scheduled_at->equalTo(CarbonImmutable::parse($until))
                && $this->nextAllowedAt($message) === null;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return list<string> */
    private function clientTimezones(Message $message): array
    {
        $addresses = array_filter(array_merge([$message->to_address], $message->cc_addresses_json ?? [], $message->bcc_addresses_json ?? []));
        $zones = [];
        foreach ($addresses as $address) {
            $email = str_contains($address, '@');
            $normalized = $email ? strtolower(trim($address)) : $this->phone($address);
            if ($normalized === '') {
                continue;
            }
            $users = $email
                ? User::whereRaw('LOWER(email) = ?', [$normalized])->get()
                : $this->phoneUsers($normalized);
            foreach ($users as $user) {
                if (strtolower((string) $user->role) === 'client') {
                    $zones[] = $this->timezone($user->timezone);
                }
            }

            $contacts = $email
                ? Contact::whereRaw('LOWER(email) = ?', [$normalized])->get()
                : Contact::where('phone', 'like', $this->phonePattern($normalized))->get()
                    ->filter(fn ($contact) => $this->phone($contact->phone) === $normalized);
            if ($message->thread?->contact && $address === $message->to_address) {
                $contacts->push($message->thread->contact);
            }
            foreach ($contacts as $contact) {
                $user = $contact->user_id ? User::find($contact->user_id) : null;
                if (strtolower((string) ($user?->role ?? $contact->type)) === 'client') {
                    $zones[] = $this->timezone($user?->timezone ?? $message->shoot?->timezone);
                }
            }
        }

        return array_values(array_unique($zones));
    }

    private function phoneUsers(string $phone): Collection
    {
        $pattern = $this->phonePattern($phone);

        return User::where(fn ($query) => $query->where('phonenumber', 'like', $pattern)->orWhere('phone', 'like', $pattern))->get()
            ->filter(fn ($user) => $this->phone($user->phonenumber) === $phone || $this->phone($user->phone) === $phone);
    }

    private function phonePattern(string $phone): string
    {
        $suffix = strlen($phone) === 11 && str_starts_with($phone, '1') ? substr($phone, 1) : $phone;

        return '%'.implode('%', str_split($suffix)).'%';
    }

    private function phone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($digits) === 10 ? '1'.$digits : $digits;
    }

    private function timezone(?string $timezone): string
    {
        $timezone = \App\Support\Timezone::canonical(trim((string) $timezone));

        return in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $timezone : 'America/New_York';
    }

    private function isReminder(Message $message): bool
    {
        if (data_get($message->metadata, 'notification.trigger') === 'SHOOT_REMINDER'
            || data_get($message->metadata, 'notification.type') === 'SHOOT_REMINDER'
            || $message->send_source === 'SHOOT_REMINDER') {
            return true;
        }
        if ($message->template_id && in_array($message->template?->slug,
            ['shoot-reminder', 'shoot-reminder-sms', 'system-shoot-reminder', 'automation-shoot-reminder-sms'], true)) {
            return true;
        }
        foreach ($message->tags_json ?? [] as $tag) {
            if (str_starts_with((string) $tag, 'SHOOT_REMINDER:')) {
                return true;
            }
        }

        return false;
    }

    /** Match the specific appointment, including service-level scheduling and legacy timestamps. */
    private function appointment(Message $message): ?CarbonImmutable
    {
        if (! $this->isReminder($message) || ! $message->related_shoot_id) {
            return null;
        }
        $shoot = Shoot::find($message->related_shoot_id);
        $inactive = ['cancelled', 'canceled', 'declined', 'requested', 'on_hold', 'hold_on', 'completed', 'delivered'];
        if (! $shoot || in_array(strtolower((string) $shoot->status), $inactive, true)
            || in_array(strtolower((string) $shoot->workflow_status), $inactive, true)) {
            return null;
        }
        $expected = data_get($message->metadata, 'notification.appointment_at');
        if (! $expected) {
            foreach ($message->tags_json ?? [] as $tag) {
                if (preg_match('/^SHOOT_REMINDER:.*:(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2}))$/', (string) $tag, $match)) {
                    $expected = $match[1];
                    break;
                }
            }
        }
        $resolver = app(ScheduleInstantResolver::class);
        $items = $shoot->serviceItems->whereNotNull('scheduled_at');
        $appointments = $items->isNotEmpty()
            ? $items->filter(fn ($item) => ! in_array(strtolower((string) $item->workflow_status), $inactive, true))
                ->map(fn ($item) => $resolver->forServiceItem($shoot, $item))->filter()->values()
            : collect([$resolver->forShoot($shoot)])->filter();
        foreach ($appointments as $at) {
            if (! $expected || $at->equalTo($expected)) {
                return CarbonImmutable::instance($at);
            }
        }

        return null;
    }
}
