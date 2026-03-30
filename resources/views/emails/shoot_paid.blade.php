@extends('emails.layouts.master')

@section('title', 'Payment Confirmed for Your Shoot')
@section('preheader', 'Your payment has been recorded and your shoot account now reflects a paid status.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Payment Confirmed</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">Your payment has been recorded.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">Your shoot now shows a paid status in the dashboard, and the latest appointment details are included below for reference.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Thank you. This shoot is now marked as paid.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-success-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #d9e7ff; background-color:#eff6ff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Payment update</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Amount received: {{ '$' . number_format($amount, 2) }}. You can review the full shoot details and any available deliverables from the dashboard.</p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $shoot->dashboard_url }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Open Dashboard</a>
            </td>
        </tr>
    </table>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'showNotes' => false])
@endsection

@section('footer_note')
    Keep this email for your records as confirmation that payment was applied to this shoot.
@endsection
