<?php

namespace App\Services\SystemEmails;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Carbon\CarbonInterface;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use stdClass;

class SystemEmailRenderer
{
    public function __construct(
        private readonly ?TemplateRenderer $templateRenderer = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function render(EmailTypeDefinition $definition, array $payload, string $subject): array
    {
        // Opt-in override: if an admin has enabled a DB template for this
        // protected email type, render that instead of the hardcoded Blade
        // view. Disabled by default, so behavior is unchanged until enabled.
        if ($override = $this->resolveOverrideTemplate($definition)) {
            $rendered = $this->renderOverride($override, $definition, $payload, $subject);
            if ($rendered !== null) {
                return $rendered;
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

        return [
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
            'shoot_date' => (string) (Arr::get($shoot, 'date') ?? ''),
            'shoot_time' => (string) (Arr::get($shoot, 'time') ?? ''),
            'invoice_number' => (string) (Arr::get($invoice, 'number') ?? Arr::get($meta, 'invoice_number') ?? ''),
            'amount_due' => (string) (Arr::get($invoice, 'amount_due') ?? Arr::get($meta, 'amount_due') ?? ''),
            'due_date' => (string) (Arr::get($invoice, 'due_date') ?? Arr::get($meta, 'due_date') ?? ''),
            'payment_link' => (string) (Arr::get($links, 'payment') ?? ''),
            'payment_amount' => (string) (Arr::get($payment, 'amount') ?? Arr::get($meta, 'amount') ?? ''),
        ];
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

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function objectify(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn ($item) => $this->objectify($item), $value);
            }

            $object = new stdClass();
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
