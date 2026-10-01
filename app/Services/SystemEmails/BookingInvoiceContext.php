<?php

namespace App\Services\SystemEmails;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Payments\PublicPaymentAccessTokenService;
use App\Services\Payments\ShootPaymentEligibilityService;
use App\Support\InvoiceReference;

/** Keep the booking invoice bound to the same property and client as the email. */
class BookingInvoiceContext
{
    /** Enrich saved email shortcodes without replacing the operator's template. */
    public function forAutomationEmail(string $trigger, array $recipient, array $context): ?array
    {
        if (! in_array($trigger, ['SHOOT_SCHEDULED', 'SHOOT_BOOKED'], true)
            || ($recipient['type'] ?? null) !== 'client') {
            return $context;
        }
        $shootId = $context['shoot_id'] ?? data_get($context, 'shoot.id');
        if (! $shootId) {
            return $context;
        }
        $shoot = Shoot::with('client')->find($shootId);
        $client = $shoot?->client;
        if (! $client
            || (isset($recipient['id']) && (int) $recipient['id'] !== (int) $client->id)
            || (isset($context['account_id']) && (int) $context['account_id'] !== (int) $client->id)
            || strtolower(trim((string) ($recipient['email'] ?? ''))) !== strtolower(trim((string) $client->email))) {
            return null;
        }

        $invoice = $this->forClient($shoot, $client);
        $carriedInvoiceId = $context['invoice_id'] ?? data_get($context, 'invoice.id');
        if ($carriedInvoiceId && (int) $carriedInvoiceId !== (int) ($invoice['id'] ?? 0)) {
            return null;
        }
        // Never trust an invoice carried by a deferred or caller-supplied context.
        // The actual shoot/client association is the only source of invoice data.
        foreach (array_keys($context) as $key) {
            if ($key === 'invoice' || str_starts_with($key, 'invoice_')) {
                unset($context[$key]);
            }
        }
        $context['payment_cta_html'] = '';
        $context['payment_cta_text'] = '';
        $context['payment_link'] = '';
        $context['pay_link'] = '';
        $context['amount_due'] = '';
        $context['due_date'] = '';
        if ($invoice === []) {
            return $context;
        }

        $payment = app(ShootPaymentEligibilityService::class)->summarize($shoot);
        $dashboardUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/shoots/'.$shoot->id;
        $link = $payment['payable']
            ? app(PublicPaymentAccessTokenService::class)->buildPublicUrl($shoot)
            : $dashboardUrl;
        $label = InvoiceReference::label($invoice['invoice_number'], $invoice['id']);
        $status = $shoot->isComplimentaryReshoot() ? 'No payment required'
            : ($payment['remaining'] <= 0.01 ? 'Paid' : 'Your booking invoice is ready to review.');

        return array_replace($context, [
            'invoice_id' => $invoice['id'],
            'invoice_number' => $label,
            'invoice_label' => $label,
            'invoice_reference' => InvoiceReference::number($invoice['invoice_number'], $invoice['id']),
            'invoice_total' => '$'.number_format((float) ($invoice['total'] ?? $invoice['total_amount'] ?? 0), 2),
            'invoice_status' => $invoice['status'],
            'amount_due' => $payment['remaining'],
            'account_id' => $client->id,
            'dashboard_link' => $dashboardUrl,
            'dashboard_url' => $dashboardUrl,
            'payment_link' => $link,
            'pay_link' => $link,
            'invoice_link' => $link,
            'payment_cta_html' => '<p><strong>'.e($label).'</strong> &middot; '.e($status).'</p>'
                .'<div style="margin:24px 0;"><a href="'.e($link).'" style="display:inline-block;background:#2563eb;color:#ffffff !important;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600;">View Invoice</a></div>',
            'payment_cta_text' => $label."\n".$status."\nView Invoice: ".$link,
        ]);
    }

    public function forClient(Shoot $shoot, User $client): array
    {
        if ((int) $shoot->client_id !== (int) $client->id) {
            return [];
        }

        $invoice = Invoice::query()
            ->where('role', Invoice::ROLE_CLIENT)
            ->where('shoot_id', $shoot->id)
            ->where('client_id', $client->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'canceled', 'void'])
            ->latest('id')->first();

        if (! $invoice || $invoice->suppressesExternalNotifications()) {
            return [];
        }

        return $invoice->only(['id', 'invoice_number', 'status', 'total', 'total_amount', 'amount_paid']);
    }
}
