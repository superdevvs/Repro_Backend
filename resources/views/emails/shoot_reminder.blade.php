@extends('emails.layouts.master')

@section('title', 'Shoot Reminder')
@section('preheader', !empty($isPhotographer) ? 'A scheduled shoot on your calendar is coming up in about 24 hours.' : 'Your R/E Pro Photos shoot is coming up in about 24 hours.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">{{ !empty($isPhotographer) ? '24-Hour Assignment Reminder' : '24-Hour Shoot Reminder' }}</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">{{ !empty($isPhotographer) ? 'Your scheduled shoot is coming up soon.' : 'Your photo shoot is almost here.' }}</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">
        {{ !empty($isPhotographer)
            ? 'Please review the timing, address, services, and notes below so you are ready before arrival.'
            : 'Here is a quick reminder of the appointment details so you can make sure the property is ready on time.' }}
    </p>
@endsection

@section('content')
    <p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;">
        <strong class="dark-strong" style="color:#071223;">{{ !empty($isPhotographer) ? 'Please double-check your assignment details below.' : 'Your appointment details are confirmed below.' }}</strong>
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $shoot->dashboard_url }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">{{ !empty($isPhotographer) ? 'Open Dashboard' : 'View Shoot' }}</a>
            </td>
        </tr>
    </table>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'isPhotographer' => !empty($isPhotographer)])

    @if(empty($isPhotographer))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-success-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #d9e7ff; background-color:#eff6ff;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Before the photographer arrives</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Turn on lights, open blinds, clear counters, and make sure any access details are finalized. A little prep makes a big difference in the final gallery.</p>
                </td>
            </tr>
        </table>
    @else
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Arrival check</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Please confirm travel timing, access instructions, and service coverage before heading out. If anything looks off, contact the office before the appointment starts.</p>
                </td>
            </tr>
        </table>
    @endif
@endsection

@section('footer_note')
    This reminder is sent once, about 24 hours before the scheduled shoot time.
@endsection
