<?php

namespace App\Services\SystemEmails;

use App\Models\MessageTemplate;
use Illuminate\Support\Str;

class EmailArtwork
{
    private ?array $catalog = null;

    public function forView(string $view, array $data = []): array
    {
        $design = str_replace('-', '_', Str::after($view, 'emails.'));

        return $this->resolve($design, $data);
    }

    public function forTemplate(MessageTemplate $template, array $variables = []): array
    {
        $design = strtolower((string) ($template->email_type ?: $template->slug));
        $design = str_replace('-', '_', $design);
        $design = match ($design) {
            'weekly_invoice_generated' => 'invoice_generated',
            'shoot_ready' => 'shoot_delivered',
            'payment_thank_you' => 'payment_confirmation',
            'shoot_deleted' => 'shoot_removed',
            'photographer_assigned' => 'shoot_scheduled__photographer',
            default => $design,
        };

        return $this->resolve($design, $variables);
    }

    public function resolve(string $design, array $data = []): array
    {
        $catalog = $this->catalog();
        $role = strtolower((string) (data_get($data, 'recipient.role') ?? data_get($data, 'user.role') ?? data_get($data, 'meta.recipient_type') ?? $data['recipient_type'] ?? ''));
        if ($design === 'account_created') {
            if ($role === 'photographer') {
                $design = 'account_created__photographer';
            } elseif (array_key_exists('includePasswordCreationLink', $data) && ! $data['includePasswordCreationLink']) {
                $design = 'account_created__existing_password';
            }
        }
        if ($design === 'offline_payment_intent_submitted') {
            $method = strtolower((string) ($data['paymentMethodLabel'] ?? $data['payment_method_label'] ?? $data['payment_method'] ?? data_get($data, 'meta.payment_method_label') ?? ''));
            if (in_array($method, ['check', 'cheque'], true) || ! empty($data['checkNumber']) || ! empty($data['check_number'])) {
                $design = 'offline_payment_intent_submitted__cheque';
            }
        }
        if ($design === 'shoot_delivered' && (! empty($data['paymentLink']) || ! empty($data['pay_link']) || ! empty($data['payment_link']))) {
            $design = 'shoot_delivered__balance_due';
        }

        $entry = $catalog['templates'][$design] ?? ['kind' => 'none', 'asset' => null, 'eyebrow' => 'A NOTE FROM REPRO', 'marketing' => false];
        $entry['design'] = $design;
        $entry['version'] = 6;
        $entry['photos'] = [];
        if ($entry['kind'] === 'existing_shoot_photos') {
            $entry['photos'] = app(EmailShootPhotos::class)->resolve($data);
            // A delivery can have no released/approved photos. Never substitute a stock property.
            $entry['asset'] = $entry['photos'] ? null : 'delivery_access';
            $entry['kind'] = $entry['photos'] ? 'shoot_photos' : 'illustration';
        }

        $asset = $entry['asset'] ?? null;
        $entry['src'] = $asset && isset($catalog['assets'][$asset])
            ? rtrim((string) config('app.url'), '/').'/images/email-atelier/v6/'.$asset.'.png'
            : null;
        $entry['alt'] = '';
        $entry['footer_reason'] ??= 'This message relates to your RePro account, booking, or work.';

        return $entry;
    }

    public function catalog(): array
    {
        return $this->catalog ??= json_decode(file_get_contents(resource_path('email-atelier/catalog.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
