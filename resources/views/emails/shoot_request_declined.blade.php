@extends('emails.layouts.master')

@section('title', 'Your Shoot Request Was Declined')
@section('preheader', 'Your requested shoot could not be approved. The request details are included below for your records.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Request Declined</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">Your requested shoot could not be approved.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">The submitted request details are included below, along with the fastest way to follow up with the team.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">This request was not approved.</strong></p>

    @if(!empty($declineReason))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-danger-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #ffc8cf; background-color:#fff0f1;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Reason provided</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">{{ $declineReason }}</p>
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px;">
                <a href="{{ $shoot->dashboard_url }}" class="btn-secondary-bg" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#ffffff; color:#173963; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none; border:1px solid #cfe0f5;">Open Dashboard</a>
            </td>
        </tr>
    </table>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot])

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Need help resubmitting?</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Reply to this email or contact the office if you want help updating the request and submitting a new appointment.</p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    Keep this email if you need a record of the declined request details.
@endsection
