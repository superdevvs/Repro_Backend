<?php

namespace App\Services\Messaging;

use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Support\Collection;

/** Recipient preferences are evaluated from fresh account data at each delivery attempt. */
class SmsNotificationPreferences
{
    public const CATEGORIES = ['bookingUpdates', 'shootReminders', 'deliveryUpdates', 'payments', 'accountUpdates', 'other'];

    public function category(array $payload): string
    {
        $explicit = $payload['sms_category'] ?? data_get($payload, 'metadata.sms_notification.category');
        if (in_array($explicit, self::CATEGORIES, true)) {
            return $explicit;
        }

        $template = ! empty($payload['template_id']) ? MessageTemplate::query()->find($payload['template_id']) : null;
        foreach ([$payload['notification_type'] ?? null, $payload['automation_trigger'] ?? null, $template?->slug, $payload['send_source'] ?? null] as $type) {
            if ($category = $this->categoryForType((string) $type)) {
                return $category;
            }
        }

        return match (strtoupper((string) $template?->category)) {
            'BOOKING' => 'bookingUpdates',
            'REMINDER' => 'shootReminders',
            'PAYMENT', 'INVOICE' => 'payments',
            'ACCOUNT' => 'accountUpdates',
            default => 'other',
        };
    }

    public function isWeeklySummary(array $payload): bool
    {
        if (data_get($payload, 'metadata.sms_notification.weekly_summary') === true) {
            return true;
        }
        foreach (['notification_type', 'automation_trigger', 'send_source'] as $key) {
            if (strtoupper(str_replace('-', '_', (string) ($payload[$key] ?? ''))) === 'WEEKLY_SALES_REPORT') {
                return true;
            }
        }

        return ! empty($payload['template_id']) && MessageTemplate::query()->whereKey($payload['template_id'])
            ->whereIn('slug', ['weekly-sales-report', 'weekly-sales-report-sms', 'automation-weekly-sales-report-sms'])->exists();
    }

    private function categoryForType(string $type): ?string
    {
        $type = strtolower(str_replace('_', '-', $type));
        $type = preg_replace('/^automation-|\-sms$/', '', $type);

        return match ($type) {
            'shoot-booked', 'shoot-scheduled', 'shoot-requested', 'shoot-request-approved',
            'shoot-request-modified', 'shoot-request-declined', 'shoot-updated', 'shoot-on-hold',
            'shoot-canceled', 'shoot-cancelled', 'shoot-removed', 'shoot-deleted',
            'shoot-cancellation-requested', 'shoot-cancellation-approved', 'shoot-cancellation-rejected',
            'photographer-assigned', 'photographer-changed' => 'bookingUpdates',
            'shoot-reminder', 'photographer-shoot-reminder', 'property-contact-reminder' => 'shootReminders',
            'shoot-completed', 'shoot-ready', 'media-upload-complete', 'editing-complete', 'photo-uploaded' => 'deliveryUpdates',
            'shoot-payment-reminder', 'shoot-paid', 'payment-due', 'payment-due-reminder', 'payment-receipt',
            'payment-completed', 'payment-thank-you', 'payment-failed', 'payment-refunded', 'refund-submitted',
            'invoice-due', 'invoice-overdue', 'invoice-paid', 'invoice-summary', 'weekly-automated-invoicing',
            'weekly-invoice-generated', 'weekly-client-invoice-summary', 'weekly-rep-invoice',
            'weekly-rep-invoice-summary', 'weekly-photographer-invoice', 'weekly-payout-report', 'weekly-payout-digest',
            'payout-report', 'payout-digest' => 'payments',
            'account-created', 'account-verified', 'phone-number-changed-previous', 'phone-number-changed-new',
            'voice-caller-verification', 'voice-phone-verification' => 'accountUpdates',
            'weekly-sales-report' => 'other',
            default => null,
        };
    }

    /** Null means allowed. Neither the sending user nor the shoot's client owns another recipient's settings. */
    public function blockedReason(array $payload, string $category): ?string
    {
        // Compliance replies are requested by an inbound STOP, START or HELP keyword.
        // This exemption does not bypass the separate carrier opt-out gate.
        if (($payload['send_source'] ?? '') === 'SMS_COMPLIANCE'
            && in_array(strtolower((string) data_get($payload, 'metadata.compliance_keyword')), ['stop', 'start', 'help'], true)) {
            return null;
        }

        foreach ($this->recipients($payload) as $user) {
            $preferences = data_get($user->metadata, 'preferences', []);
            $preferences = is_array($preferences) ? $preferences : [];
            $master = $preferences['notificationSMS'] ?? data_get($preferences, 'notificationSettings.sms') ?? true;
            if (! $this->enabled($master)) {
                return 'Recipient has disabled all text messages in notification settings.';
            }

            $legacy = match ($category) {
                'shootReminders' => data_get($preferences, 'notifications.shootReminders', true),
                'payments' => data_get($preferences, 'notifications.paymentReminders', true),
                default => true,
            };
            if (! $this->enabled(data_get($preferences, 'smsCategories.'.$category) ?? $legacy)) {
                return 'Recipient has disabled this type of text message in notification settings.';
            }
            if ($this->isWeeklySummary($payload) && ! $this->enabled(data_get($preferences, 'notifications.weeklySummaries', true))) {
                return 'Recipient has disabled this type of text message in notification settings.';
            }
        }

        return null;
    }

    private function enabled(mixed $value): bool
    {
        return $value === null ? true : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);
    }

    /** Match formatted and E.164 numbers, without confusing different international prefixes. */
    private function normalizePhone(mixed $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($digits) === 10 ? '1'.$digits : $digits;
    }

    /** @return Collection<int, User> */
    private function recipients(array $payload): Collection
    {
        $phone = $this->normalizePhone($payload['to'] ?? '');
        $ids = array_filter([(int) ($payload['contact_user_id'] ?? 0)]);
        if ($phone === '') {
            return User::query()->whereKey($ids)->get(['id', 'metadata']);
        }

        // LIKE narrows candidates while permitting punctuation in historical phone values.
        // Compare the full normalized number afterward, including the country code.
        $suffix = str_starts_with($phone, '1') && strlen($phone) === 11 ? substr($phone, 1) : $phone;
        $pattern = '%'.implode('%', str_split($suffix)).'%';
        $linkedIds = Contact::query()->whereNotNull('user_id')->where(function ($query) use ($pattern): void {
            $query->where('phone', 'like', $pattern)->orWhere('phones_json', 'like', $pattern);
        })->get(['phone', 'phones_json', 'user_id'])
            ->filter(function (Contact $contact) use ($phone): bool {
                $numbers = [$contact->phone];
                foreach ((array) $contact->phones_json as $entry) {
                    $numbers[] = is_array($entry) ? ($entry['number'] ?? null) : $entry;
                }

                return collect($numbers)->contains(fn ($number) => $this->normalizePhone($number) === $phone);
            })
            ->pluck('user_id')->all();
        $ids = array_values(array_unique(array_merge($ids, $linkedIds)));

        return User::query()->where(function ($query) use ($ids, $pattern): void {
            $query->whereIn('id', $ids)->orWhere('phonenumber', 'like', $pattern)->orWhere('phone', 'like', $pattern);
        })->get(['id', 'metadata', 'phonenumber', 'phone'])
            ->filter(fn (User $user) => in_array((int) $user->id, array_map('intval', $ids), true)
                || $this->normalizePhone($user->phonenumber) === $phone
                || $this->normalizePhone($user->phone) === $phone);
    }
}
