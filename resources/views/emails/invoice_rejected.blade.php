@extends('emails.layouts.master')

@section('title', 'Invoice Rejected')
@section('preheader', 'Your payout invoice needs revisions before approval.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Invoice Rejected</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">Your invoice needs a revision.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">The {{ $roleLabel ?? 'payout' }} invoice for this period was returned during review. Use the reason below to update it and resubmit through the dashboard.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Your invoice for {{ $period }} was rejected.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Invoice Review</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">{{ '$' . number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Invoice Number</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $invoice->invoice_number ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Period</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $period }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Rejected At</td>
                        <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $invoice->rejected_at ? $invoice->rejected_at->format('M j, Y g:i A') : 'N/A' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($invoice->rejection_reason)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-danger-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #ffc8cf; background-color:#fff0f1;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Rejection reason</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">{{ $invoice->rejection_reason }}</p>
            </td>
        </tr>
    </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="https://reprodashboard.com" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Review and Update</a>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    Once corrected, the invoice can be resubmitted for approval.
@endsection
