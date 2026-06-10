<?php

namespace App\Services\Messaging;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;

class TemplateVariableResolver
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function resolve(array $context): array
    {
        $portalUrl = $this->resolvePortalUrl();
        $recipientType = strtolower((string) ($context['recipient_type'] ?? 'client'));
        $derived = [
            'company_name' => $this->configValue('mail.from.name', $this->configValue('app.name', '')),
            'company_email' => $this->configValue('mail.from.address', ''),
            'company_phone' => $this->configValue('app.company_phone', ''),
            'company_address' => $this->configValue('app.company_address', ''),
            'portal_url' => $portalUrl,
            'current_date' => now()->format('M j, Y'),
            'recipient_type' => $recipientType,
        ];

        if (!isset($context['client'])) {
            $accountId = $context['account_id'] ?? $context['related_account_id'] ?? null;
            if ($accountId) {
                $client = User::find($accountId);
                if ($client) {
                    $context['client'] = $client;
                }
            }
        }

        if (isset($context['client'])) {
            $derived = array_merge($derived, $this->resolveUser($context['client'], 'client'));
        }

        if (isset($context['photographer'])) {
            $derived = array_merge($derived, $this->resolveUser($context['photographer'], 'photographer'));
        }

        if (!isset($context['previous_photographer']) && !empty($context['previous_photographer_id'])) {
            $context['previous_photographer'] = User::find($context['previous_photographer_id']);
        }
        if (!isset($context['new_photographer']) && !empty($context['new_photographer_id'])) {
            $context['new_photographer'] = User::find($context['new_photographer_id']);
        }

        if (isset($context['previous_photographer']) && ($context['previous_photographer'] instanceof User || is_array($context['previous_photographer']))) {
            $derived = array_merge($derived, $this->resolveUser($context['previous_photographer'], 'previous_photographer'));
        }

        if (isset($context['new_photographer']) && ($context['new_photographer'] instanceof User || is_array($context['new_photographer']))) {
            $derived = array_merge($derived, $this->resolveUser($context['new_photographer'], 'new_photographer'));
        }

        if (isset($context['rep'])) {
            $derived = array_merge($derived, $this->resolveUser($context['rep'], 'rep'));
        }

        if (isset($context['recipient']) && ($context['recipient'] instanceof User || is_array($context['recipient']))) {
            $recipientUser = $this->resolveUser($context['recipient'], 'recipient');
            $derived = array_merge($derived, $recipientUser, [
                'recipient_name' => $recipientUser['recipient_name'] ?? null,
                'recipient_first_name' => $recipientUser['recipient_first_name'] ?? null,
                'recipient_email' => $recipientUser['recipient_email'] ?? null,
            ]);
        } elseif (!empty($context['recipient_name'])) {
            [$recipientFirstName] = $this->splitName((string) $context['recipient_name']);
            $derived['recipient_name'] = (string) $context['recipient_name'];
            $derived['recipient_first_name'] = $recipientFirstName;
        }

        $shoot = $this->resolveShoot($context);
        if ($shoot) {
            $derived = array_merge($derived, $this->resolveShootVariables($shoot));
        }

        if (!empty($derived['client_first_name'])) {
            $derived['realtor_first'] = $derived['client_first_name'];
        }
        if (!empty($derived['client_last_name'])) {
            $derived['realtor_last'] = $derived['client_last_name'];
        }
        if (!empty($derived['client_company'])) {
            $derived['realtor_company'] = $derived['client_company'];
        }
        if (!empty($derived['client_email'])) {
            $derived['realtor_email'] = $derived['client_email'];
        }
        if (!empty($derived['client_phone'])) {
            $derived['phone_number'] = $derived['client_phone'];
        }

        $shootChanges = trim((string) ($context['shoot_changes'] ?? ''));
        $derived['shoot_changes'] = $shootChanges !== ''
            ? $shootChanges
            : '';
        $derived['shoot_change_summary'] = $derived['shoot_changes'];
        $derived['shoot_changes_html'] = $this->formatChangeSummaryHtml(
            $context['shoot_changes_html'] ?? null,
            $derived['shoot_changes']
        );
        $derived['photographer_change_summary'] = $context['photographer_change_summary']
            ?? $derived['shoot_change_summary'];

        $invoice = $this->resolveInvoice($context);
        if ($invoice) {
            $derived = array_merge($derived, $this->resolveInvoiceVariables($invoice));
        }

        $recipientFirstName = $this->resolveRecipientFirstName($context, $derived, $recipientType);
        if ($recipientFirstName !== '') {
            $derived['greeting'] = 'Hi ' . $recipientFirstName;
            $derived['recipient_first_name'] = $recipientFirstName;
        }

        $derived = array_merge($derived, $this->resolveRecipientContent($recipientType, $derived));

        if (empty($derived['email_signature'])) {
            $derived['email_signature'] = $derived['company_name'] ?? '';
        }

        $resolved = array_merge($derived, $context);

        foreach ([
            'recipient_name',
            'recipient_first_name',
            'recipient_email',
            'greeting',
            'shoot_changes',
            'shoot_change_summary',
            'shoot_changes_html',
            'services_provided',
            'services_provided_html',
            'assigned_photographers',
        ] as $canonicalKey) {
            if (array_key_exists($canonicalKey, $derived)) {
                $resolved[$canonicalKey] = $derived[$canonicalKey];
            }
        }

        return $resolved;
    }

    private function resolvePortalUrl(): string
    {
        $portalUrl = $this->configValue('app.frontend_url', $this->configValue('app.url', ''));
        if (empty($portalUrl)) {
            return 'https://reprodashboard.com';
        }

        $lower = strtolower($portalUrl);
        if (str_contains($lower, 'localhost') || str_contains($lower, '127.0.0.1')) {
            return 'https://reprodashboard.com';
        }

        return $portalUrl;
    }

    private function configValue(string $key, mixed $default = null): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable $exception) {
            return $default;
        }
    }

    private function formatShootTime(Shoot $shoot): string
    {
        $time = $shoot->time;
        if (!empty($time)) {
            try {
                return \Carbon\Carbon::parse($time)->format('g:i A');
            } catch (\Exception $e) {
                return $time;
            }
        }

        $scheduledAt = $this->getDateAttribute($shoot, 'scheduled_at');
        if ($scheduledAt) {
            return $scheduledAt->format('g:i A');
        }

        $scheduledDate = $this->getDateAttribute($shoot, 'scheduled_date');
        if ($scheduledDate && $scheduledDate->format('H:i') !== '00:00') {
            return $scheduledDate->format('g:i A');
        }

        return 'TBD';
    }

    private function formatShootNotes(Shoot $shoot): string
    {
        $notes = [];

        if (!empty($shoot->shoot_notes)) {
            $notes[] = $shoot->shoot_notes;
        }

        if (!$shoot->relationLoaded('notes') && $this->canLoadShootRelations($shoot)) {
            $shoot->load('notes');
        }

        $loadedNotes = $shoot->relationLoaded('notes')
            ? ($shoot->getRelation('notes') ?? [])
            : [];

        foreach ($loadedNotes as $note) {
            if (!empty($note->content) && $note->visibility === 'client_visible') {
                $notes[] = $note->content;
            }
        }

        $notes = array_filter($notes, fn($note) => trim((string) $note) !== '');

        return $notes ? implode("\n", $notes) : 'N/A';
    }

    /**
     * @param  User|array<string, mixed>  $user
     * @return array<string, mixed>
     */
    private function resolveUser(User|array $user, string $prefix): array
    {
        $name = is_array($user) ? ($user['name'] ?? '') : ($user->name ?? '');
        $email = is_array($user) ? ($user['email'] ?? '') : ($user->email ?? '');
        $company = is_array($user) ? ($user['company_name'] ?? '') : ($user->company_name ?? '');
        $phone = is_array($user)
            ? ($user['phonenumber'] ?? $user['phone'] ?? '')
            : ($user->phonenumber ?? $user->phone ?? '');

        [$firstName, $lastName] = $this->splitName($name);

        return [
            $prefix . '_name' => $name,
            $prefix . '_first_name' => $firstName,
            $prefix . '_last_name' => $lastName,
            $prefix . '_company' => $company,
            $prefix . '_email' => $email,
            $prefix . '_phone' => $phone,
        ];
    }

    private function resolveShoot(array $context): ?Shoot
    {
        if (isset($context['shoot']) && $context['shoot'] instanceof Shoot) {
            if ($this->canLoadShootRelations($context['shoot'])) {
                $context['shoot']->loadMissing(['client', 'photographer', 'service', 'services', 'notes']);
            }
            return $context['shoot'];
        }

        $shootId = $context['shoot_id'] ?? null;
        if ($shootId) {
            return Shoot::with(['client', 'photographer', 'service', 'services', 'notes'])->find($shootId);
        }

        return null;
    }

    private function resolveInvoice(array $context): ?Invoice
    {
        if (isset($context['invoice']) && $context['invoice'] instanceof Invoice) {
            return $context['invoice'];
        }

        $invoiceId = $context['invoice_id'] ?? null;
        if ($invoiceId) {
            return Invoice::find($invoiceId);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveShootVariables(Shoot $shoot): array
    {
        if ($this->canLoadShootRelations($shoot)) {
            $shoot->loadMissing(['photographer', 'services', 'notes']);
        }
        $location = $this->buildShootLocation($shoot);
        $scheduledDate = $this->getDateAttribute($shoot, 'scheduled_date');
        $scheduledAt = $this->getDateAttribute($shoot, 'scheduled_at');
        $shootDate = $scheduledDate
            ? $scheduledDate->format('M j, Y')
            : ($scheduledAt?->format('M j, Y'));
        $shootTime = $this->formatShootTime($shoot);
        $total = $shoot->total_quote ?? $shoot->base_quote ?? null;
        $paymentLink = ($shoot->exists && $shoot->id && $this->canUseApplicationContainer())
            ? app(\App\Services\Payments\PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot)
            : null;
        $formattedServices = $this->formatServices($shoot);
        $servicesProvided = $formattedServices['text'];
        $servicesProvidedHtml = $formattedServices['html'];
        $assignedPhotographers = $this->formatAssignedPhotographers($shoot);
        $smallZipLink = ($shoot->exists && $shoot->id && $this->canUseApplicationContainer())
            ? app(\App\Services\Shoots\ShootMediaArchiveService::class)->buildPublicDownloadUrl($shoot, 'edited', 'small')
            : null;
        $fullZipLink = ($shoot->exists && $shoot->id && $this->canUseApplicationContainer())
            ? app(\App\Services\Shoots\ShootMediaArchiveService::class)->buildPublicDownloadUrl($shoot, 'edited', 'original')
            : null;

        return [
            'shoot_id' => $shoot->id,
            'shoot_location' => $location,
            'shoot_address' => $location,
            'shoot_date' => $shootDate,
            'shoot_time' => $shootTime,
            'shoot_packages' => $servicesProvided,
            'services_provided' => $servicesProvided,
            'services_provided_html' => $servicesProvidedHtml,
            'assigned_photographers' => $assignedPhotographers,
            'small_zip_link' => $smallZipLink,
            'full_zip_link' => $fullZipLink,
            'shoot_total' => $total !== null ? (float) $total : null,
            'shoot_quote' => $total !== null ? '$' . number_format((float) $total, 2) : null,
            'shoot_notes' => $this->formatShootNotes($shoot),
            'shoot_completed_date' => $this->getDateAttribute($shoot, 'completed_at')?->format('M j, Y')
                ?? $this->getDateAttribute($shoot, 'editing_completed_at')?->format('M j, Y')
                ?? $this->getDateAttribute($shoot, 'admin_verified_at')?->format('M j, Y'),
            'photo_count' => $shoot->edited_photo_count ?? $shoot->expected_final_count ?? null,
            'mls_tour_link' => $shoot->iguide_tour_url ?? null,
            'pay_link' => $paymentLink,
            'payment_link' => $paymentLink,
            'cancellation_reason' => $shoot->cancellation_reason ?? null,
            'decline_reason' => filled(trim((string) ($shoot->declined_reason ?? '')))
                ? trim((string) $shoot->declined_reason)
                : 'No reason was provided.',
        ];
    }

    /**
     * @return array{text: string, html: string}
     */
    private function formatServices(Shoot $shoot): array
    {
        $services = $shoot->relationLoaded('services')
            ? collect($shoot->getRelation('services') ?? [])
            : collect();
        $photographerName = $this->resolveLoadedPhotographerName($shoot);

        if ($services->count() > 0) {
            $textLines = [];
            $htmlLines = [];

            foreach ($services as $service) {
                $lineParts = [$service->name ?? 'Service'];

                $quantity = (int) ($service->pivot->quantity ?? 1);
                if ($quantity > 1) {
                    $lineParts[] = 'x' . $quantity;
                }

                $price = $service->pivot->price ?? $service->price ?? null;
                if ($price !== null && $price !== '') {
                    $lineParts[] = '$' . number_format((float) $price, 2);
                }

                $assignedPhotographerName = '';
                $assignedPhotographerId = $service->pivot->photographer_id ?? null;
                if ($assignedPhotographerId && $this->canUseApplicationContainer()) {
                    $assignedPhotographerName = User::find($assignedPhotographerId)?->name ?? '';
                } elseif ($photographerName !== '') {
                    $assignedPhotographerName = $photographerName;
                }

                $line = implode(' - ', array_filter($lineParts, fn ($part) => $part !== ''));
                if ($assignedPhotographerName !== '') {
                    $line .= ' (Photographer: ' . $assignedPhotographerName . ')';
                }

                $textLines[] = '- ' . $line;
                $htmlLines[] = '<li style="margin:0 0 8px 0;">'
                    . e($service->name ?? 'Service')
                    . ($quantity > 1 ? ' <span style="color:#64748b;">x' . e((string) $quantity) . '</span>' : '')
                    . ($price !== null && $price !== '' ? ' <strong style="color:#0f172a;">$' . e(number_format((float) $price, 2)) . '</strong>' : '')
                    . ($assignedPhotographerName !== '' ? '<div style="font-size:12px;color:#64748b;margin-top:2px;">Assigned photographer: ' . e($assignedPhotographerName) . '</div>' : '')
                    . '</li>';
            }

            return [
                'text' => implode("\n", $textLines),
                'html' => '<ul style="margin:0;padding-left:18px;">' . implode('', $htmlLines) . '</ul>',
            ];
        }

        $fallback = $shoot->package_services_included;
        if (is_array($fallback) && count($fallback) > 0) {
            $textLines = array_map(fn ($service) => '- ' . trim((string) $service), $fallback);
            $htmlLines = array_map(
                fn ($service) => '<li style="margin:0 0 8px 0;">' . e(trim((string) $service)) . '</li>',
                $fallback
            );

            return [
                'text' => implode("\n", $textLines),
                'html' => '<ul style="margin:0;padding-left:18px;">' . implode('', $htmlLines) . '</ul>',
            ];
        }

        $singleServiceName = $shoot->relationLoaded('service')
            ? ($shoot->getRelation('service')?->name ?? null)
            : null;
        $single = $shoot->package_name ?: ($singleServiceName ?? $shoot->service_category ?? 'Service details will appear in the dashboard.');

        return [
            'text' => '- ' . $single,
            'html' => '<ul style="margin:0;padding-left:18px;"><li style="margin:0 0 8px 0;">' . e($single) . '</li></ul>',
        ];
    }

    private function formatAssignedPhotographers(Shoot $shoot): string
    {
        $names = [];
        $loadedPhotographerName = $this->resolveLoadedPhotographerName($shoot);

        if ($loadedPhotographerName !== '') {
            $names[] = $loadedPhotographerName;
        }

        $services = $shoot->relationLoaded('services')
            ? ($shoot->getRelation('services') ?? [])
            : [];

        foreach ($services as $service) {
            $photographerId = $service->pivot->photographer_id ?? null;
            if ($photographerId && $this->canUseApplicationContainer()) {
                $name = User::find($photographerId)?->name;
                if ($name) {
                    $names[] = $name;
                }
            }
        }

        $names = array_values(array_unique(array_filter($names)));

        return $names ? implode(', ', $names) : '';
    }

    private function resolveLoadedPhotographerName(Shoot $shoot): string
    {
        if (!$shoot->relationLoaded('photographer')) {
            return '';
        }

        return (string) ($shoot->getRelation('photographer')?->name ?? '');
    }

    private function resolveRecipientFirstName(array $context, array $derived, string $recipientType): string
    {
        if (!empty($derived['recipient_first_name'])) {
            return (string) $derived['recipient_first_name'];
        }

        if (!empty($derived['recipient_name'])) {
            [$firstName] = $this->splitName((string) $derived['recipient_name']);
            if ($firstName !== '') {
                return $firstName;
            }
        }

        if (!empty($context['recipient_name'])) {
            [$firstName] = $this->splitName((string) $context['recipient_name']);
            if ($firstName !== '') {
                return $firstName;
            }
        }

        return match ($recipientType) {
            'photographer' => (string) ($derived['photographer_first_name'] ?? ''),
            'rep' => (string) ($derived['rep_first_name'] ?? ''),
            default => (string) ($derived['client_first_name'] ?? ''),
        };
    }

    /**
     * @param  array<string, mixed>  $derived
     * @return array<string, string>
     */
    private function resolveRecipientContent(string $recipientType, array $derived): array
    {
        $portalUrl = (string) ($derived['portal_url'] ?? 'https://reprodashboard.com');
        $isPhotographer = $recipientType === 'photographer';
        $isRep = $recipientType === 'rep';

        if ($isPhotographer) {
            return [
                'recipient_booking_intro' => 'A shoot has been added to your assignment queue.',
                'recipient_update_intro' => 'One of your assigned shoots has been updated. Please review the latest details below.',
                'recipient_manage_copy' => 'You can review this assignment in your dashboard at <a href="' . e($portalUrl) . '">' . e($portalUrl) . '</a>.',
                'recipient_manage_copy_text' => 'You can review this assignment in your dashboard at ' . $portalUrl . '.',
                'property_prep_html' => '',
                'property_prep_text' => '',
                'payment_cta_html' => '',
                'payment_cta_text' => '',
                'cancellation_policy_html' => '',
                'cancellation_policy_text' => '',
            ];
        }

        if ($isRep) {
            return [
                'recipient_booking_intro' => 'A new shoot has been scheduled for one of your accounts.',
                'recipient_update_intro' => 'A scheduled shoot for one of your accounts has been updated. The latest details are below.',
                'recipient_manage_copy' => 'You can review this shoot in the dashboard at <a href="' . e($portalUrl) . '">' . e($portalUrl) . '</a>.',
                'recipient_manage_copy_text' => 'You can review this shoot in the dashboard at ' . $portalUrl . '.',
                'property_prep_html' => '',
                'property_prep_text' => '',
                'payment_cta_html' => '',
                'payment_cta_text' => '',
                'cancellation_policy_html' => '',
                'cancellation_policy_text' => '',
            ];
        }

        $paymentLink = (string) ($derived['payment_link'] ?? $derived['pay_link'] ?? '');

        return [
            'recipient_booking_intro' => 'A new photo shoot has been scheduled under your account.',
            'recipient_update_intro' => 'One of your scheduled photo shoots has been updated. Please review the latest details below.',
            'recipient_manage_copy' => 'You can find the shoot in your dashboard under <strong>Scheduled Shoots</strong> after logging into <a href="' . e($portalUrl) . '">' . e($portalUrl) . '</a>.',
            'recipient_manage_copy_text' => 'You can find the shoot in your dashboard under Scheduled Shoots after logging into ' . $portalUrl . '.',
            'property_prep_html' => '<p>To keep the appointment running smoothly, please make sure the property is ready before the scheduled time.</p>',
            'property_prep_text' => 'To keep the appointment running smoothly, please make sure the property is ready before the scheduled time.',
            'payment_cta_html' => $paymentLink !== ''
                ? '<div style="margin:24px 0;"><a href="' . e($paymentLink) . '" style="display:inline-block;background:#2563eb;color:#ffffff !important;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600;">Pay Now</a><p style="margin:12px 0 0;color:#64748b;font-size:13px;">Payment can be completed anytime before final delivery. Final assets remain locked until the invoice is paid in full.</p></div>'
                : '',
            'payment_cta_text' => $paymentLink !== ''
                ? "Payment link: {$paymentLink}\nPayment can be completed anytime before final delivery. Final assets remain locked until the invoice is paid in full."
                : '',
            'cancellation_policy_html' => '<div style="margin-top:20px;padding:16px 18px;border:1px solid #fde68a;background:#fffbeb;border-radius:14px;"><strong style="display:block;color:#92400e;margin-bottom:6px;">Cancellation Policy</strong><span style="color:#92400e;">If an appointment is cancelled on-site, a $60 cancellation fee may apply. Please cancel or reschedule at least 6 hours before the appointment start time whenever possible.</span></div>',
            'cancellation_policy_text' => 'Cancellation policy: If an appointment is cancelled on-site, a $60 cancellation fee may apply. Please cancel or reschedule at least 6 hours before the appointment start time whenever possible.',
        ];
    }

    private function formatChangeSummaryHtml(?string $explicitHtml, string $fallbackText): string
    {
        $html = trim((string) $explicitHtml);
        $normalizedFallback = trim($fallbackText);
        $decodedHtml = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        if (
            $normalizedFallback !== ''
            && (
                $html === ''
                || str_contains($decodedHtml, '→')
                || str_contains($decodedHtml, '->')
                || str_contains(strtolower($decodedHtml), 'removed (was')
            )
        ) {
            return $this->buildStructuredChangeSummaryHtml($normalizedFallback);
        }

        if ($html !== '') {
            return $html;
        }

        $lines = preg_split('/\r\n|\r|\n/', $fallbackText) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));

        if ($lines === []) {
            return '';
        }

        if (count($lines) === 1) {
            return '<p>' . e($lines[0]) . '</p>';
        }

        $items = array_map(fn ($line) => '<li style="margin:0 0 8px 0;">' . e($line) . '</li>', $lines);

        return '<ul style="margin:0;padding-left:18px;">' . implode('', $items) . '</ul>';
    }

    private function buildStructuredChangeSummaryHtml(string $summary): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $summary) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));

        if ($lines === []) {
            return '';
        }

        return implode('', array_map(
            fn (string $line) => $this->renderStructuredChangeSummaryLine($line),
            $lines
        ));
    }

    private function renderStructuredChangeSummaryLine(string $line): string
    {
        $change = $this->parseChangeSummaryLine($line);

        if (($change['type'] ?? 'text') === 'comparison') {
            $label = e((string) ($change['label'] ?? 'Updated Detail'));
            $beforeHtml = $this->buildChangeSummaryBeforeHtml(
                (string) ($change['label'] ?? ''),
                (string) ($change['before'] ?? ''),
                (string) ($change['after'] ?? '')
            );
            $afterValue = trim((string) ($change['after'] ?? ''));
            $afterHtml = e($afterValue !== '' ? $afterValue : 'Not set');

            return <<<HTML
<div class="change-summary-block" style="margin:0 0 12px; padding:16px 18px; border:1px solid #dbe6f3; border-radius:14px; background-color:#f8fbff;">
    <div style="margin:0 0 12px; font-size:15px; line-height:1.5; color:#10233b; font-weight:800;">{$label}</div>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td width="50%" style="padding-right:8px; vertical-align:top;">
                <div style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Before</div>
                <div style="margin:0; font-size:14px; line-height:1.7; color:#2d4769;">{$beforeHtml}</div>
            </td>
            <td width="50%" style="padding-left:8px; vertical-align:top;">
                <div style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">After</div>
                <div style="margin:0; font-size:14px; line-height:1.7; color:#10233b; font-weight:700;">{$afterHtml}</div>
            </td>
        </tr>
    </table>
</div>
HTML;
        }

        if (($change['type'] ?? 'text') === 'single') {
            $label = e((string) ($change['label'] ?? 'Updated Detail'));
            $value = trim((string) ($change['value'] ?? ''));
            $valueHtml = e($value !== '' ? $value : 'Not set');

            return <<<HTML
<div class="change-summary-block" style="margin:0 0 12px; padding:16px 18px; border:1px solid #dbe6f3; border-radius:14px; background-color:#f8fbff;">
    <div style="margin:0 0 8px; font-size:15px; line-height:1.5; color:#10233b; font-weight:800;">{$label}</div>
    <div style="margin:0 0 4px; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Updated Value</div>
    <div style="margin:0; font-size:14px; line-height:1.7; color:#10233b;">{$valueHtml}</div>
</div>
HTML;
        }

        return '<p class="change-summary-block" style="margin:0 0 12px; font-size:14px; line-height:1.7; color:#2d4769;">' . e((string) ($change['text'] ?? $line)) . '</p>';
    }

    /**
     * @return array<string, string>
     */
    private function parseChangeSummaryLine(string $line): array
    {
        $line = trim($line);

        if (!str_contains($line, ':')) {
            return [
                'type' => 'text',
                'text' => $line,
            ];
        }

        [$label, $value] = explode(':', $line, 2);
        $label = trim($label);
        $value = trim($value);

        if (preg_match('/^removed\s+\(was\s+(.+)\)$/i', $value, $matches)) {
            return [
                'type' => 'comparison',
                'label' => $label,
                'before' => trim((string) ($matches[1] ?? '')),
                'after' => 'Removed',
            ];
        }

        if (preg_match('/\s(?:→|->)\s/u', $value) === 1) {
            [$before, $after] = preg_split('/\s*(?:→|->)\s*/u', $value, 2);

            return [
                'type' => 'comparison',
                'label' => $label,
                'before' => trim((string) $before),
                'after' => trim((string) $after),
            ];
        }

        return [
            'type' => 'single',
            'label' => $label,
            'value' => $value,
        ];
    }

    private function buildChangeSummaryBeforeHtml(string $label, string $before, string $after): string
    {
        $before = trim($before);
        $after = trim($after);

        if ($before === '') {
            return e('Not set');
        }

        if (strcasecmp($after, 'Removed') === 0) {
            return '<span style="text-decoration:line-through; color:#8c5f68;">' . e($before) . '</span>';
        }

        if (strcasecmp($label, 'Services') !== 0) {
            return e($before);
        }

        $beforeItems = array_values(array_filter(array_map(
            'trim',
            preg_split('/\s*,\s*/', $before) ?: []
        ), fn ($item) => $item !== ''));

        if ($beforeItems === []) {
            return e($before);
        }

        $afterCounts = [];
        foreach (preg_split('/\s*,\s*/', $after) ?: [] as $item) {
            $normalizedItem = trim((string) $item);
            if ($normalizedItem === '') {
                continue;
            }

            $key = strtolower($normalizedItem);
            $afterCounts[$key] = ($afterCounts[$key] ?? 0) + 1;
        }

        $parts = array_map(function (string $item) use (&$afterCounts) {
            $key = strtolower($item);
            $remaining = (int) ($afterCounts[$key] ?? 0);

            if ($remaining > 0) {
                $afterCounts[$key] = $remaining - 1;

                return e($item);
            }

            return '<span style="text-decoration:line-through; color:#8c5f68;">' . e($item) . '</span>';
        }, $beforeItems);

        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveInvoiceVariables(Invoice $invoice): array
    {
        $amountPaid = (float) ($invoice->amount_paid ?? 0);
        $amountDue = max((float) $invoice->balanceDue(), 0);

        $variables = [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number ?? ('Invoice #' . $invoice->id),
            'amount_due' => $amountDue > 0 ? $amountDue : null,
            'payment_amount' => $amountPaid > 0 ? $amountPaid : null,
            'payment_date' => $invoice->paid_at?->format('M j, Y') ?? null,
            'due_date' => $invoice->due_date?->format('M j, Y') ?? null,
            'payment_link' => $invoice->paymentLink(),
        ];

        if ($invoice->client_id) {
            $client = User::find($invoice->client_id);
            if ($client) {
                $variables = array_merge($variables, $this->resolveUser($client, 'client'));
            }
        }

        return $variables;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $clean = trim((string) $name);
        if ($clean === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $clean) ?: [];
        $first = array_shift($parts) ?? '';
        $last = $parts ? implode(' ', $parts) : '';

        return [$first, $last];
    }

    private function buildShootLocation(Shoot $shoot): string
    {
        $parts = array_filter([
            $shoot->address,
            $shoot->city,
            $shoot->state,
            $shoot->zip,
        ]);

        return $parts ? implode(', ', $parts) : 'N/A';
    }

    private function getDateAttribute(object $model, string $attribute): ?\Carbon\CarbonInterface
    {
        if ($this->canUseEloquentRelations()) {
            $value = $model->{$attribute} ?? null;

            return $value instanceof \Carbon\CarbonInterface
                ? $value
                : ($value ? \Carbon\Carbon::parse($value) : null);
        }

        if (!method_exists($model, 'getRawOriginal')) {
            return null;
        }

        $value = $model->getRawOriginal($attribute);
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function canUseApplicationContainer(): bool
    {
        try {
            return function_exists('app') && app()->bound('config');
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function canUseEloquentRelations(): bool
    {
        return Shoot::getConnectionResolver() !== null;
    }

    private function canLoadShootRelations(Shoot $shoot): bool
    {
        return $this->canUseEloquentRelations() && $shoot->exists;
    }
}
