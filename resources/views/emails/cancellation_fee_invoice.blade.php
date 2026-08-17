@extends('emails.layouts.master')

@section('title', 'Cancellation Fee Invoice')
@section('preheader', 'A cancellation fee invoice has been issued for a property appointment.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Cancellation Fee</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">A cancellation fee invoice has been issued.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">This invoice reflects a cancellation or on-site appointment issue covered by our cancellation policy. The billing details are listed below.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">A cancellation fee has been added to your account.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Amount Due</p>
                    <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.2; font-weight:800; color:#071223;">{{ '$' . number_format($invoice->total, 2) }}</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Issue Date</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ $invoice->issue_date->format('F j, Y') }}</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 0 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Due Date</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ $invoice->due_date->format('F j, Y') }}</p>
                </td></tr></table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Invoice Details</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">{{ $invoiceLabel ?? $invoice->invoice_number }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Property</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $address }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Invoice Number</td>
                        <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $invoiceReference ?? $invoice->invoice_number }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-warning-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #ffdcae; background-color:#fff3e3;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Payment reminder</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Please pay the invoice by the due date to avoid additional collection friction. If you have questions about the fee, reply to this email and our team will help.</p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    This fee is issued according to the current cancellation policy on your account.
@endsection
