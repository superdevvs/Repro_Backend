@extends('emails.layouts.master')

@section('title', 'Invoice Requires Approval')
@section('preheader', 'A payout invoice is waiting for admin review.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Approval Needed</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">An invoice requires admin review.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">A {{ $roleLabel ?? 'team member' }} updated a payout invoice for this period. Review the details below and approve or return it in the dashboard.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">There is an invoice waiting for your decision.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Invoice Summary</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">{{ $invoiceLabel ?? $invoice->invoice_number ?? 'N/A' }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">{{ $roleHeading ?? 'Payee' }}</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $recipient->name }}<span class="dark-muted" style="display:block; margin-top:3px; color:#7086a3; font-weight:400; font-size:12px;">{{ $recipient->email }}</span></td>
                    </tr>
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Period</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $period }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Total</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ '$' . number_format($invoice->total_amount ?? $invoice->total ?? 0, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Modified At</td>
                        <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $invoice->modified_at ? $invoice->modified_at->format('M j, Y g:i A') : 'N/A' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if($invoice->modification_notes)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:16px;">
        <tr>
            <td class="note-card-bg" style="border-radius:14px; background-color:#f8fbff; border:1px solid #dbe7f8; padding:18px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:13px; line-height:1.5; letter-spacing:1.3px; text-transform:uppercase; color:#60799a; font-weight:800;">Modification Notes</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#4f6886;">{{ $invoice->modification_notes }}</p>
            </td>
        </tr>
    </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="https://reprodashboard.com" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Review Invoice</a>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    Approving or returning the invoice in the dashboard will keep accounting and the {{ $roleLabel ?? 'team member' }} aligned.
@endsection
