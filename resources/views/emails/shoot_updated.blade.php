@extends('emails.layouts.master')

@section('title', 'Scheduled Photo Shoot Updated')
@section('preheader', 'Updated details are available for one of your scheduled shoots.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">{{ !empty($isPhotographer) ? 'Assignment Updated' : 'Shoot Updated' }}</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">{{ !empty($isPhotographer) ? 'A shoot on your calendar has changed.' : 'One of your upcoming shoots has been updated.' }}</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Please review the latest shoot details below.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $shoot->dashboard_url }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Review in Dashboard</a>
            </td>
        </tr>
    </table>

    @include('emails.partials.change-summary', ['changesSummary' => $changesSummary ?? null])
    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'isPhotographer' => !empty($isPhotographer)])

    @if(empty($isPhotographer))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-warning-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #ffdcae; background-color:#fff3e3;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Need to adjust the appointment?</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">If any of these changes affect your availability or property readiness, please reply as soon as possible so the team can help you reschedule smoothly.</p>
                </td>
            </tr>
        </table>
    @else
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Please re-check access and timing</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">Any schedule, access, or service change can affect your coverage plan. If something looks off, reach out to the office before heading to the property.</p>
                </td>
            </tr>
        </table>
    @endif
@endsection

@section('footer_note')
    This message reflects the latest saved shoot details currently visible in the dashboard.
@endsection
