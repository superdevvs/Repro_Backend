@extends('emails.layouts.master')

@section('title', 'Cancellation Fee Invoice')
@section('preheader', 'Your cancellation invoice is ready to review.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 16px;font-size:11px;line-height:16px;letter-spacing:1.4px;text-transform:uppercase;font-weight:600;">Your invoice</p>
    <h1 class="hero-title-td dark-title" style="margin:0;font-size:36px;line-height:42px;font-weight:500;letter-spacing:-1.2px;">Your cancellation invoice.</h1>
    <p class="dark-body" style="margin:16px 0 0;font-size:16px;line-height:26px;">A cancellation fee has been added to your account. The property, invoice reference, and due date are shown below.</p>
@endsection

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 32px;">
        <tr><td class="section-card-bg section-inner" style="background-color:#f5f7fa;border-radius:12px;padding:24px;">
            <p class="dark-muted" style="margin:0 0 16px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;">Invoice details</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td class="detail-label-td dark-muted">Invoice</td><td class="detail-value-td dark-heading">{{ $invoiceReference ?? $invoice->invoice_number }}</td></tr>
                <tr><td class="detail-label-td dark-muted">Property</td><td class="detail-value-td dark-heading">{{ $address }}</td></tr>
                <tr><td class="detail-label-td dark-muted">Amount due</td><td class="detail-value-td dark-heading"><span style="font-size:22px;line-height:28px;">{{ '$'.number_format(max(0, (float) ($invoice->total_amount ?? $invoice->total ?? 0) - (float) ($invoice->amount_paid ?? 0)), 2) }}</span></td></tr>
                <tr><td class="detail-label-td dark-muted">Issued</td><td class="detail-value-td dark-heading">{{ $invoice->issue_date?->format('F j, Y') ?? 'See invoice' }}</td></tr>
                <tr><td class="detail-label-td dark-muted">Due</td><td class="detail-value-td dark-heading">{{ $invoice->due_date?->format('F j, Y') ?? 'See invoice' }}</td></tr>
            </table>
        </td></tr>
    </table>
    <p class="dark-heading" style="margin:0 0 4px;font-size:14px;line-height:24px;font-weight:600;">Payment and support</p>
    <p class="dark-body" style="margin:0 0 24px;font-size:14px;line-height:24px;">Please pay the invoice by the due date. If you have questions about the fee, reply to this email and our team will help.</p>
    <p class="dark-heading" style="margin:0 0 4px;font-size:14px;line-height:24px;font-weight:600;">Policy context</p>
    <p class="dark-body" style="margin:0 0 24px;font-size:14px;line-height:24px;">This fee is issued according to the current cancellation policy on your account.</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td><a class="atelier-button" href="{{ data_get($links ?? [], 'invoice') ?: data_get($branding ?? [], 'dashboard_url', config('app.frontend_url')) }}" style="display:block;padding:16px;background-color:#155bdd;color:#ffffff;border-radius:8px;text-align:center;font-size:14px;line-height:22px;font-weight:600;">View invoice</a></td></tr></table>
@endsection
