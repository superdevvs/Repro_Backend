@extends('emails.layouts.master')

@section('title', 'Your Shoot Has Been Cancelled')
@section('preheader', 'Your scheduled shoot has been cancelled and the latest details are included for your records.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Cancelled</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:32px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">Your shoot has been cancelled.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">The last confirmed details are included below for your records, along with the fastest way to rebook if needed.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">This shoot has been cancelled.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-danger-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #ffc8cf; background-color:#fff0f1;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">What happens next</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">If you still need media for this property, reply to this email or rebook through the dashboard and our team will help you get a new appointment scheduled quickly.</p>
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

    @include('emails.partials.shoot-summary', ['shoot' => $shoot])
@endsection

@section('footer_note')
    Keep this email if you need a record of the cancelled appointment details.
@endsection
