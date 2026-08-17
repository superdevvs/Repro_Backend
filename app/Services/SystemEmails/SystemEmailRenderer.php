<?php

namespace App\Services\SystemEmails;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateVariableResolver;
use App\Support\InvoiceReference;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use stdClass;

class SystemEmailRenderer
{
    public function __construct(
        private readonly ?TemplateRenderer $templateRenderer = null,
        private readonly ?TemplateVariableResolver $variableResolver = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function render(EmailTypeDefinition $definition, array $payload, string $subject): array
    {
        // Opt-in override: if an admin has enabled a DB template for this
        // protected email type, render that instead of the hardcoded Blade
        // view. Disabled by default, so behavior is unchanged until enabled.
        if (! $this->mustUseProtectedBlade($definition, $payload)) {
            if ($override = $this->resolveOverrideTemplate($definition)) {
                $rendered = $this->renderOverride($override, $definition, $payload, $subject);
                if ($rendered !== null) {
                    return $rendered;
                }
            }
        }

        $viewData = $this->viewData($definition, $payload);
        $html = view($definition->templateView, $viewData)->render();
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        return [
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
            'view_data' => $viewData,
        ];
    }

    /**
     * The SHOOT_REQUESTED alias serves both the client receipt and the internal
     * admin review notification. Its editable template is client-authored copy,
     * so admin recipients must keep the audience-specific protected Blade.
     *
     * @param  array<string, mixed>  $payload
     */
    private function mustUseProtectedBlade(EmailTypeDefinition $definition, array $payload): bool
    {
        return $definition->alias === 'SHOOT_REQUESTED'
            && (bool) Arr::get($payload, 'meta.is_admin', false);
    }

    private function resolveOverrideTemplate(EmailTypeDefinition $definition): ?MessageTemplate
    {
        if (! Schema::hasColumn('message_templates', 'override_enabled')) {
            return null;
        }

        return MessageTemplate::query()
            ->where('channel', 'EMAIL')
            ->where('email_type', $definition->alias)
            ->where('override_enabled', true)
            ->where('is_active', true)
            ->latest('updated_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function renderOverride(MessageTemplate $template, EmailTypeDefinition $definition, array $payload, string $subject): ?array
    {
        $renderer = $this->templateRenderer ?? app(TemplateRenderer::class);

        $rendered = $renderer->render($template, $this->overrideVariables($payload));

        $html = (string) ($rendered['html'] ?? $rendered['body_html'] ?? '');
        if (trim($html) === '') {
            // Fall back to the Blade view if the override produced nothing.
            return null;
        }

        $resolvedSubject = trim((string) ($rendered['subject'] ?? ''));
        $text = (string) ($rendered['text'] ?? $rendered['body_text'] ?? '');
        if (trim($text) === '') {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        }

        return [
            'subject' => $resolvedSubject !== '' ? $resolvedSubject : $subject,
            'body_html' => $html,
            'body_text' => $text,
            'view_data' => $this->viewData($definition, $payload),
        ];
    }

    /**
     * Best-effort flattening of the structured system-email payload into the
     * flat shortcode variables that TemplateRenderer expects. Only used when an
     * admin opts a template in as an override.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function overrideVariables(array $payload): array
    {
        $recipient = (array) ($payload['recipient'] ?? []);
        $account = (array) ($payload['account'] ?? []);
        $shoot = (array) ($payload['shoot'] ?? []);
        $invoice = (array) ($payload['invoice'] ?? []);
        $payment = (array) ($payload['payment'] ?? []);
        $links = (array) ($payload['links'] ?? []);
        $branding = (array) ($payload['branding'] ?? []);
        $meta = (array) ($payload['meta'] ?? []);

        $recipientName = trim((string) (Arr::get($recipient, 'name') ?? ''));
        $firstName = (string) (Arr::get($recipient, 'first_name') ?? Str::before($recipientName, ' '));
        $lastName = (string) (Arr::get($recipient, 'last_name') ?? trim(Str::after($recipientName, ' ')));
        $portalUrl = (string) (Arr::get($links, 'dashboard') ?? Arr::get($branding, 'dashboard_url') ?? '');
        $recipientType = strtolower((string) (Arr::get($meta, 'recipient_type') ?? Arr::get($recipient, 'role') ?? 'client'));
        $invoiceId = Arr::get($invoice, 'id');
        $storedInvoiceNumber = Arr::get($invoice, 'invoice_number')
            ?? Arr::get($invoice, 'number')
            ?? Arr::get($meta, 'invoice_number');
        $invoiceReference = InvoiceReference::number($storedInvoiceNumber, $invoiceId);
        $invoiceLabel = InvoiceReference::label($storedInvoiceNumber, $invoiceId);
        $invoiceTotal = (float) (Arr::get($invoice, 'total_amount') ?? Arr::get($invoice, 'total') ?? 0);
        $amountPaid = (float) (Arr::get($invoice, 'amount_paid') ?? 0);
        $invoiceItems = (array) (Arr::get($invoice, 'items') ?? []);

        $resolverContext = array_merge($this->scalarPayloadVariables($payload), [
            'recipient' => $recipient,
            'recipient_type' => $recipientType,
            'recipient_name' => $recipientName,
            'account_id' => Arr::get($account, 'id') ?? Arr::get($recipient, 'id'),
            'shoot_id' => Arr::get($shoot, 'id'),
            'invoice_id' => $invoiceId,
            'shoot_changes' => Arr::get($meta, 'shoot_changes') ?? Arr::get($meta, 'changes_summary'),
        ]);

        // formatShootData exposes a display-only `shoot.photographer` string.
        // The resolver's `photographer` context key is intentionally a User or
        // user-shaped array, so do not let the flattened display value collide.
        unset($resolverContext['photographer']);

        if (in_array($recipientType, ['client', 'photographer', 'rep'], true)) {
            $resolverContext[$recipientType] = $recipient;
        }

        $payloadPhotographerName = trim((string) (Arr::get($shoot, 'primary_photographer')
            ?? Arr::get($shoot, 'photographer')
            ?? ''));
        if ($recipientType !== 'photographer' && $payloadPhotographerName !== '') {
            $resolverContext['photographer'] = ['name' => $payloadPhotographerName];
        }

        $resolver = $this->variableResolver ?? app(TemplateVariableResolver::class);
        $resolved = $resolver->resolve(array_filter(
            $resolverContext,
            fn (mixed $value) => $value !== null
        ));

        $photographerName = trim((string) ($resolved['photographer_name'] ?? ''));
        if ($photographerName === '') {
            $photographerName = $payloadPhotographerName;
        }
        [$photographerFirstName, $photographerLastName] = $this->splitName($photographerName);

        return array_merge($resolved, $this->scalarPayloadVariables($payload), [
            'greeting' => $firstName !== '' ? "Hello {$firstName}" : 'Hello',
            'client_first_name' => $firstName,
            'client_last_name' => $lastName,
            'client_name' => $recipientName !== '' ? $recipientName : trim("{$firstName} {$lastName}"),
            'client_email' => (string) (Arr::get($recipient, 'email') ?? ''),
            'client_phone' => (string) (Arr::get($recipient, 'phone') ?? Arr::get($recipient, 'phonenumber') ?? ''),
            'client_company' => (string) (Arr::get($account, 'company_name') ?? Arr::get($recipient, 'company_name') ?? ''),
            'company_name' => (string) (Arr::get($branding, 'product_name') ?? Arr::get($account, 'company_name') ?? 'R/E Pro Photos'),
            'company_email' => (string) (Arr::get($branding, 'support_email') ?? 'contact@reprophotos.com'),
            'portal_url' => $portalUrl,
            'password_reset_link' => (string) (Arr::get($links, 'reset_password') ?? ''),
            'shoot_location' => (string) (Arr::get($shoot, 'location') ?? Arr::get($shoot, 'address') ?? ''),
            'shoot_date' => $this->overrideShootDate($shoot, $resolved),
            'shoot_time' => (string) ($resolved['shoot_time'] ?? Arr::get($shoot, 'time') ?? ''),
            'photographer_name' => $photographerName,
            'photographer_first_name' => $photographerFirstName,
            'photographer_last_name' => $photographerLastName,
            'invoice_number' => $invoiceLabel,
            'invoice_label' => $invoiceLabel,
            'invoice_reference' => $invoiceReference,
            'amount_due' => (string) (Arr::get($invoice, 'amount_due') ?? Arr::get($meta, 'amount_due') ?? max($invoiceTotal - $amountPaid, 0)),
            'due_date' => (string) (Arr::get($invoice, 'due_date') ?? Arr::get($meta, 'due_date') ?? ''),
            'payment_link' => (string) (Arr::get($links, 'payment') ?? ''),
            'payment_amount' => (string) (Arr::get($payment, 'amount') ?? Arr::get($meta, 'amount') ?? ''),
            'recipient_role' => (string) (Arr::get($meta, 'recipient_role') ?? $recipientType),
            'billing_period' => (string) (Arr::get($meta, 'billing_period') ?? Arr::get($meta, 'period') ?? ''),
            'invoice_status' => Str::headline((string) (Arr::get($invoice, 'status') ?? 'draft')),
            'invoice_total' => '$'.number_format($invoiceTotal, 2),
            'invoice_items_html' => $this->invoiceItemsHtml($invoiceItems),
            'invoice_items_text' => $this->invoiceItemsText($invoiceItems),
            'dashboard_url' => $portalUrl,
            'invoice_next_step' => (string) (Arr::get($meta, 'invoice_next_step')
                ?? 'Open the dashboard to review the invoice, confirm line items, and add any missing expenses before approval moves forward.'),
            'approval_note' => (string) (Arr::get($meta, 'approval_note')
                ?? 'Changes made after generation may trigger a fresh approval review before payout is finalized.'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $shoot
     * @param  array<string, mixed>  $resolved
     */
    private function overrideShootDate(array $shoot, array $resolved): string
    {
        $resolvedDate = trim((string) ($resolved['shoot_date'] ?? ''));
        if ($resolvedDate !== '') {
            return $resolvedDate;
        }

        $date = trim((string) (Arr::get($shoot, 'date') ?? ''));
        $time = trim((string) (Arr::get($shoot, 'time') ?? ''));

        if ($date !== '' && $time !== '') {
            $date = preg_replace('/\s+at\s+'.preg_quote($time, '/').'$/i', '', $date) ?? $date;
        }

        return $date;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $firstName = array_shift($parts) ?? '';

        return [$firstName, implode(' ', $parts)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    private function scalarPayloadVariables(array $payload): array
    {
        $variables = [];

        foreach (['recipient', 'account', 'shoot', 'invoice', 'payment', 'links', 'branding', 'meta'] as $section) {
            foreach ((array) ($payload[$section] ?? []) as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $variables[(string) $key] = $value;
                }
            }
        }

        return $variables;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function invoiceItemsHtml(array $items): string
    {
        if ($items === []) {
            return '<p style="margin:0;">Line items will appear here once charges or expenses are attached to the invoice.</p>';
        }

        return collect($items)->map(function (mixed $item): string {
            $item = (array) $item;
            $type = e(Str::headline((string) ($item['type'] ?? 'line item')));
            $description = e((string) ($item['description'] ?? 'Line item'));
            $amount = e('$'.number_format((float) ($item['total_amount'] ?? $item['total'] ?? 0), 2));

            return '<div class="info-row"><span class="info-label">'.$type.'</span> '
                .$description.' <strong style="float:right;">'.$amount.'</strong></div>';
        })->implode("\n");
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function invoiceItemsText(array $items): string
    {
        if ($items === []) {
            return '- No line items have been attached yet.';
        }

        return collect($items)->map(function (mixed $item): string {
            $item = (array) $item;
            $type = Str::headline((string) ($item['type'] ?? 'line item'));
            $description = trim((string) ($item['description'] ?? 'Line item'));
            $amount = '$'.number_format((float) ($item['total_amount'] ?? $item['total'] ?? 0), 2);

            return "- {$description} ({$type}): {$amount}";
        })->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function viewData(EmailTypeDefinition $definition, array $payload): array
    {
        $recipient = $this->objectify($payload['recipient'] ?? []);
        $account = $this->objectify($payload['account'] ?? []);
        $shoot = $this->objectify($payload['shoot'] ?? []);
        $invoice = $this->objectify($payload['invoice'] ?? []);
        $payment = $this->objectify($payload['payment'] ?? []);
        $links = (array) ($payload['links'] ?? []);
        $branding = $this->objectify($payload['branding'] ?? []);
        $meta = $this->objectify($payload['meta'] ?? []);
        $invoiceReference = InvoiceReference::number(
            $invoice->invoice_number ?? $invoice->number ?? null,
            $invoice->id ?? null
        );
        $invoiceLabel = InvoiceReference::label(
            $invoice->invoice_number ?? $invoice->number ?? null,
            $invoice->id ?? null
        );

        $shared = [
            'payload' => $payload,
            'recipient' => $recipient,
            'account' => $account,
            'shoot' => $shoot,
            'invoice' => $invoice,
            'payment' => $payment,
            'links' => $links,
            'branding' => $branding,
            'meta' => $meta,
            'user' => $recipient,
            'invoiceReference' => $invoiceReference,
            'invoiceLabel' => $invoiceLabel,
        ];

        return match ($definition->alias) {
            'ACCOUNT_CREATED' => $shared + [
                'resetLink' => $links['reset_password'] ?? null,
                'verificationLink' => $links['verification'] ?? null,
                'equipmentVerificationUrl' => $links['equipment_verification'] ?? null,
                'equipmentCount' => (int) ($meta->pending_equipment_count ?? 0),
                'includePasswordCreationLink' => (bool) ($meta->include_password_creation_link ?? false),
            ],
            'CLIENT_EMAIL_VERIFICATION' => $shared + [
                'verificationLink' => $links['verification'] ?? null,
                'dashboardUrl' => $links['dashboard'] ?? $branding->dashboard_url ?? null,
            ],
            'CLIENT_EMAIL_VERIFIED' => $shared + [
                'dashboardUrl' => $links['dashboard'] ?? $branding->dashboard_url ?? null,
                'settingsUrl' => $links['settings'] ?? null,
            ],
            'PHOTOGRAPHER_EQUIPMENT_VERIFICATION' => $shared + [
                'equipmentVerificationUrl' => $links['equipment_verification'] ?? null,
                'equipmentCount' => (int) ($meta->pending_equipment_count ?? 0),
            ],
            'PHOTOGRAPHER_EQUIPMENT_APPROVED' => $shared + [
                'dashboardUrl' => $links['dashboard'] ?? $branding->dashboard_url ?? null,
                'equipmentUrl' => $links['equipment'] ?? null,
                'equipmentName' => $meta->equipment_name ?? null,
                'equipmentSerialNumber' => $meta->equipment_serial_number ?? null,
                'verifiedAt' => $this->hydrateCarbon($meta->verified_at ?? null),
            ],
            'ROLE_CHANGED' => $shared + [
                'oldRoleLabel' => $meta->old_role_label ?? null,
                'newRoleLabel' => $meta->new_role_label ?? null,
                'secondaryRoles' => (array) ($meta->secondary_roles ?? []),
            ],
            'PASSWORD_RESET' => $shared + [
                'resetLink' => $links['reset_password'] ?? null,
            ],
            'SHOOT_SCHEDULED' => $shared + [
                'paymentLink' => $links['payment'] ?? null,
                'isPhotographer' => (bool) ($meta->is_photographer ?? false),
            ],
            'SHOOT_UPDATED' => $shared + [
                'changesSummary' => $meta->changes_summary ?? null,
                'isPhotographer' => (bool) ($meta->is_photographer ?? false),
            ],
            'SHOOT_REMINDER' => $shared + [
                'scheduledAt' => $this->hydrateCarbon($meta->scheduled_at ?? null),
                'isPhotographer' => (bool) ($meta->is_photographer ?? false),
            ],
            'SHOOT_REQUEST_DECLINED' => $shared + [
                'declineReason' => $meta->decline_reason ?? null,
            ],
            'SHOOT_REQUESTED' => $shared + [
                'isAdmin' => (bool) ($meta->is_admin ?? false),
            ],
            'SHOOT_CANCELLATION_REQUESTED' => $shared + [
                'isPhotographer' => (bool) ($meta->is_photographer ?? false),
                'cancellationReason' => $meta->cancellation_reason ?? null,
            ],
            'SHOOT_DELIVERED' => $shared + [
                'paymentLink' => $links['payment'] ?? null,
            ],
            'INVOICE_GENERATED' => $shared + [
                'photographer' => $recipient,
                'recipientRole' => $meta->recipient_role ?? null,
                'period' => $meta->period ?? null,
            ],
            'INVOICE_PENDING_APPROVAL' => $shared + [
                'admin' => $recipient,
                'period' => $meta->period ?? null,
                'roleLabel' => $meta->role_label ?? null,
                'roleHeading' => $meta->role_heading ?? null,
            ],
            'INVOICE_APPROVED', 'INVOICE_REJECTED' => $shared + [
                'period' => $meta->period ?? null,
                'roleLabel' => $meta->role_label ?? null,
            ],
            'SHOOT_PAID' => $shared + [
                'amount' => $meta->amount ?? null,
            ],
            'PHOTOGRAPHER_CHANGED' => $shared + [
                'changesSummary' => $meta->changes_summary ?? null,
                'previousPhotographer' => $this->objectify($meta->previous_photographer ?? []),
                'isAssignedAfterChange' => (bool) ($meta->is_assigned_after_change ?? false),
            ],
            'CANCELLATION_FEE_INVOICE' => $shared + [
                'client' => $recipient,
                'address' => $meta->address ?? null,
            ],
            'OFFLINE_PAYMENT_INTENT_SUBMITTED' => $shared + [
                'amount' => $meta->amount ?? null,
                'paymentMethodLabel' => $meta->payment_method_label ?? 'Cash',
                'checkNumber' => $meta->check_number ?? null,
                'paymentDate' => $meta->payment_date ?? null,
                'notes' => $meta->notes ?? null,
                'submittedByName' => $meta->submitted_by_name ?? null,
                'submittedByRole' => $meta->submitted_by_role ?? null,
                'shootAddress' => $meta->shoot_address ?? null,
                'reviewUrl' => $links['shoot'] ?? $links['dashboard'] ?? null,
                'dashboardUrl' => $links['dashboard'] ?? null,
            ],
            'OFFLINE_PAYMENT_INTENT_DECLINED' => $shared + [
                'amount' => $meta->amount ?? null,
                'paymentMethodLabel' => $meta->payment_method_label ?? 'Cash',
                'declineReason' => $meta->decline_reason ?? null,
                'shootAddress' => $meta->shoot_address ?? null,
                'reviewUrl' => $links['shoot'] ?? $links['dashboard'] ?? null,
                'dashboardUrl' => $links['dashboard'] ?? null,
            ],
            default => $shared,
        };
    }

    private function objectify(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($item) => $this->objectify($item), $value);
            }

            $object = new stdClass;
            foreach ($value as $key => $item) {
                $object->{$key} = $this->objectify($item);
            }

            return $object;
        }

        return $value;
    }

    private function hydrateCarbon(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface || $value === null || $value === '') {
            return $value;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return Str::of((string) $value)->trim()->value();
        }
    }
}
