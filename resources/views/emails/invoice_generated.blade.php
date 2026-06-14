@extends('emails.layouts.master')

@section('title', 'Weekly Invoice - ' . $period)
@section('preheader', 'Your weekly invoice is ready to review.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Weekly Invoicing</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:32px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">Your invoice for {{ $period }} is ready.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">Review the payout window, confirm the line items included this week, and handle any follow-up before approval moves forward.</p>
@endsection

@section('content')
    @php
        $recipientModel = $recipient ?? $photographer;
        $recipientLabel = $recipientRole ?? 'photographer';
    @endphp

<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Your weekly {{ $recipientLabel }} invoice has been generated.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="https://reprodashboard.com" style="display:inline-block; padding:18px 30px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:16px; line-height:1.2; text-decoration:none; letter-spacing:0.2px;">Review Weekly Invoice</a>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Invoice Number</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ $invoice->invoice_number ?? 'N/A' }}</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Status</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">{{ ucfirst($invoice->status) }}</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 0 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Total</p>
                    <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.2; font-weight:800; color:#071223;">{{ '$' . number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</p>
                </td></tr></table>
            </td>
        </tr>
    </table>

    @if($invoice->items && $invoice->items->count() > 0)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Invoice Items</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Line item breakdown</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Description</td>
                        <td class="line-th dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:left; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Type</td>
                        <td class="line-th amount-td dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; font-size:11px; letter-spacing:1.4px; text-transform:uppercase; color:#7b91ac; font-weight:800;">Amount</td>
                    </tr>
                    @foreach($invoice->items as $item)
                    <tr>
                        <td class="line-td detail-border" style="padding:12px 0; border-bottom:1px solid #edf2f7;"><span class="dark-heading" style="color:#071223; font-weight:700; font-size:14px;">{{ $item->description }}</span></td>
                        <td class="line-td detail-border dark-muted" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#7188a6; font-size:12px;">{{ ucfirst($item->type) }}</td>
                        <td class="line-td amount-td detail-border dark-heading" style="padding:12px 0; border-bottom:1px solid #edf2f7; text-align:right; white-space:nowrap; color:#071223; font-weight:800; font-size:14px;">{{ '$' . number_format($item->total_amount, 2) }}</td>
                    </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Next step</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Open the dashboard to review this invoice, add any missing expenses, or flag an issue if something needs adjustment before approval.</p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    Changes made to the invoice after generation may trigger a fresh approval review.
@endsection
