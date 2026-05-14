@extends('emails.layouts.master')

@section('title', 'Offline Payment Was Not Accepted')
@section('preheader', 'Your submitted offline payment could not be confirmed.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#8298b4; font-weight:700;">Payment Not Accepted</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#e8edf5;">Your offline payment was declined.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#a9b8cb;">Your submitted {{ $paymentMethodLabel ?? 'Cash' }} payment of <strong class="dark-strong" style="color:#e8edf5;">${{ number_format((float) ($amount ?? 0), 2) }}</strong> was not confirmed by our team.</p>
@endsection

@section('content')
    <p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#a9b8cb;"><strong class="dark-strong" style="color:#e8edf5;">Hi {{ $recipient->name ?? 'there' }},</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#16233a; border:1px solid #24344d; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#8298b4; font-weight:700;">Payment Details</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#e8edf5;">${{ number_format((float) ($amount ?? 0), 2) }} · {{ $paymentMethodLabel ?? 'Cash' }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    @if(!empty($shootAddress))
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Shoot</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">{{ $shootAddress }}</td>
                    </tr>
                    @endif
                    @if(!empty($declineReason))
                    <tr>
                        <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Reason</td>
                        <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">{{ $declineReason }}</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <p class="dark-body" style="margin:0 0 16px; font-size:14px; line-height:1.7; color:#a9b8cb;">Your shoot balance was not changed. You can resubmit the payment or pay online via card from your dashboard.</p>

    @if(!empty($reviewUrl))
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 22px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $reviewUrl }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Open Shoot</a>
            </td>
        </tr>
    </table>
    @endif
@endsection

@section('footer_note')
    Reply to this email if you believe this was a mistake or need help completing payment.
@endsection
