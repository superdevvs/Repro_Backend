<?php

namespace App\Services\SystemEmails;

use Carbon\CarbonInterface;
use Carbon\Carbon;
use Illuminate\Support\Str;
use stdClass;

class SystemEmailRenderer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function render(EmailTypeDefinition $definition, array $payload, string $subject): array
    {
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
