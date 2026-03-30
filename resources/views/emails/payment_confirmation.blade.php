@extends('emails.layouts.master')

@section('title', 'Thank You for Your Payment!')
@section('preheader', 'Your payment for this shoot has been received.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Payment Received</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">Thank you. Your payment is in.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">We have recorded your payment for the shoot below. This receipt summarizes the payment and the booked services tied to it.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Thank you for taking care of the payment.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Receipt</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">{{ '$' . number_format($payment->amount, 2) }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Payment date</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #edf2f7; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $payment->created_at }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td {{ !empty($payment->transaction_id) ? 'detail-border' : '' }} dark-muted" width="34%" style="padding:10px 14px 10px 0; {{ !empty($payment->transaction_id) ? 'border-bottom:1px solid #edf2f7;' : '' }} vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Method</td>
                        <td class="detail-value-td {{ !empty($payment->transaction_id) ? 'detail-border' : '' }} dark-heading" style="padding:10px 0; {{ !empty($payment->transaction_id) ? 'border-bottom:1px solid #edf2f7;' : '' }} vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $payment->payment_method ?? 'Card' }}</td>
                    </tr>
                    @if(!empty($payment->transaction_id))
                    <tr>
                        <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#6f86a4; font-weight:700;">Transaction ID</td>
                        <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#10233b; font-weight:600;">{{ $payment->transaction_id }}</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-success-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #d9e7ff; background-color:#eff6ff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">What happens next</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Your shoot will continue moving through production. Once the media package is complete, you will receive another email letting you know the files are ready.</p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    Keep this confirmation for your accounting records.
@endsection
