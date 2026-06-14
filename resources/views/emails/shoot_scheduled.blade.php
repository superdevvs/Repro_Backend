@extends('emails.layouts.master')

@section('title', 'New Shoot Scheduled')
@section('preheader', !empty($isPhotographer) ? 'A new shoot assignment is ready for review.' : 'Your new R/E Pro Photos shoot has been confirmed.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">{{ !empty($isPhotographer) ? 'New Assignment' : 'Shoot Confirmed' }}</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:32px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">{{ !empty($isPhotographer) ? 'You have been assigned to a new shoot.' : 'Your photo shoot is officially on the calendar.' }}</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">
        {{ !empty($isPhotographer)
            ? 'Review the property details, service lineup, and notes below so you are fully prepared before arrival.'
            : 'Your booking has been scheduled with the R/E Pro Photos team. Everything you need to review before the appointment is organized below.' }}
    </p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">{{ !empty($isPhotographer) ? 'Your upcoming assignment is ready.' : 'Thanks for scheduling with us.' }}</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff; padding-right:10px;" bgcolor="#1463ff">
                <a href="{{ $shoot->dashboard_url }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">{{ !empty($isPhotographer) ? 'Open Dashboard' : 'View Shoot' }}</a>
            </td>
            @if(empty($isPhotographer) && !empty($paymentLink))
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $paymentLink }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Pay Now</a>
            </td>
            @endif
        </tr>
    </table>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'isPhotographer' => !empty($isPhotographer)])

    @if(!empty($isPhotographer))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Before the appointment</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Please confirm timing, access, and service coverage in advance. If anything creates a scheduling conflict, contact the office as soon as possible so we can realign quickly.</p>
                </td>
            </tr>
        </table>
    @else
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-success-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #d9e7ff; background-color:#eff6ff;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">How to get the property camera-ready</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">A little prep goes a long way. Use our property-prep guide to help the home look polished and consistent across photos, video, and tours.</p>
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                        <tr>
                            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                                <a href="{{ $shoot->property_prep_url }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">View Prep Guide</a>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-warning-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #ffdcae; background-color:#fff3e3;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Cancellation policy</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">If an appointment is cancelled on-site, a $60 cancellation fee applies. To avoid that charge, please reschedule or cancel at least 6 hours before the start of the appointment.</p>
                </td>
            </tr>
        </table>
    @endif
@endsection

@section('footer_note')
    {{ !empty($isPhotographer)
        ? 'Need help before the shoot? Call or email the office so we can support the assignment.'
        : 'Payments can be made at any time during the shoot process. Final media access is released once the balance is paid in full.' }}
@endsection
