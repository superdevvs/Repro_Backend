@extends('emails.layouts.master')

@section('title', 'Your Shoot Has Been Delivered')
@section('preheader', 'Your shoot is complete and available to review or download in the dashboard.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Shoot Delivered</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">Your shoot has been delivered.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">Your shoot is now complete and available in the dashboard for review and download.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Your shoot is complete and your delivered files are ready for review and download.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            @if(!empty($paymentLink))
                <td style="padding:0 12px 12px 0;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="border-radius:999px; background-color:#071223;" bgcolor="#071223">
                                <a href="{{ $paymentLink }}" style="display:inline-block; padding:18px 30px; border-radius:999px; background-color:#071223; color:#ffffff; font-weight:800; font-size:16px; line-height:1.2; text-decoration:none; letter-spacing:0.2px;">Pay Now</a>
                            </td>
                        </tr>
                    </table>
                </td>
            @endif
            <td style="padding:0 0 12px 0;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                            <a href="{{ $shoot->dashboard_url }}" style="display:inline-block; padding:18px 30px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:16px; line-height:1.2; text-decoration:none; letter-spacing:0.2px;">Open Deliverables</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-success-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #d9e7ff; background-color:#eff6ff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">What you can do now</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">
                    @if(!empty($paymentLink))
                        Complete the remaining balance to unlock the full delivery, then return to the dashboard to review and download everything for this property.
                    @else
                        Preview the completed files, download the final media, and manage everything for this property from the dashboard.
                    @endif
                </p>
            </td>
        </tr>
    </table>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'showNotes' => false])
@endsection

@section('footer_note')
    We appreciate your business and would love to hear about your experience after delivery.
@endsection
