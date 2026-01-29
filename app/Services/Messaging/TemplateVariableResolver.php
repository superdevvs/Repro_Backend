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
        $derived = [
            'company_name' => config('mail.from.name', config('app.name', '')),
            'company_email' => config('mail.from.address', ''),
            'company_phone' => config('app.company_phone', ''),
            'company_address' => config('app.company_address', ''),
            'portal_url' => config('app.frontend_url', config('app.url', '')),
            'current_date' => now()->format('M j, Y'),
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

        if (isset($context['rep'])) {
            $derived = array_merge($derived, $this->resolveUser($context['rep'], 'rep'));
        }

        $shoot = $this->resolveShoot($context);
        if ($shoot) {
            $derived = array_merge($derived, $this->resolveShootVariables($shoot));
        }

        $invoice = $this->resolveInvoice($context);
        if ($invoice) {
            $derived = array_merge($derived, $this->resolveInvoiceVariables($invoice));
        }

        if (!empty($derived['client_first_name'])) {
            $derived['greeting'] = 'Hi ' . $derived['client_first_name'];
        }

        if (empty($derived['email_signature'])) {
            $derived['email_signature'] = $derived['company_name'] ?? '';
        }

        return array_merge($derived, $context);
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
            return $context['shoot'];
        }

        $shootId = $context['shoot_id'] ?? null;
        if ($shootId) {
            return Shoot::with(['client', 'photographer', 'service'])->find($shootId);
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
        $location = $this->buildShootLocation($shoot);
        $shootDate = $shoot->scheduled_date
            ? $shoot->scheduled_date->format('M j, Y')
            : ($shoot->scheduled_at?->format('M j, Y'));
        $shootTime = $shoot->time ?? ($shoot->scheduled_at?->format('g:i A') ?? '');
        $total = $shoot->total_quote ?? $shoot->base_quote ?? null;
        $paymentLink = $shoot->id
            ? rtrim(config('app.frontend_url', config('app.url', '')), '/') . "/payment/{$shoot->id}"
            : null;

        $servicesProvided = $shoot->package_services_included
            ?? $shoot->package_name
            ?? ($shoot->service?->name ?? $shoot->service_category ?? '');

        return [
            'shoot_id' => $shoot->id,
            'shoot_location' => $location,
            'shoot_address' => $location,
            'shoot_date' => $shootDate,
            'shoot_time' => $shootTime,
            'shoot_packages' => $servicesProvided,
            'services_provided' => $servicesProvided,
            'shoot_total' => $total,
            'shoot_quote' => $total,
            'shoot_notes' => $shoot->notes ?? $shoot->shoot_notes ?? '',
            'shoot_completed_date' => $shoot->completed_at?->format('M j, Y')
                ?? $shoot->editing_completed_at?->format('M j, Y')
                ?? $shoot->admin_verified_at?->format('M j, Y'),
            'photo_count' => $shoot->edited_photo_count ?? $shoot->expected_final_count ?? null,
            'mls_tour_link' => $shoot->iguide_tour_url ?? null,
            'payment_link' => $paymentLink,
            'cancellation_reason' => $shoot->cancellation_reason ?? null,
            'decline_reason' => $shoot->declined_reason ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveInvoiceVariables(Invoice $invoice): array
    {
        $amountPaid = (float) ($invoice->amount_paid ?? 0);
        $totalAmount = (float) ($invoice->total_amount ?? $invoice->total ?? 0);
        $amountDue = max($totalAmount - $amountPaid, 0);

        $variables = [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number ?? null,
            'amount_due' => $amountDue > 0 ? $amountDue : null,
            'payment_amount' => $amountPaid > 0 ? $amountPaid : null,
            'payment_date' => $invoice->paid_at?->format('M j, Y') ?? null,
            'due_date' => $invoice->due_date?->format('M j, Y') ?? null,
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
}
