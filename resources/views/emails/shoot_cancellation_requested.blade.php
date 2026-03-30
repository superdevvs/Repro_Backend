@extends('emails.layouts.master')

@section('title', 'Shoot Cancellation Request Received')
@section('preheader', !empty($isPhotographer) ? 'A client has asked to cancel an upcoming assignment.' : 'We received your shoot cancellation request and will review it shortly.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Cancellation Requested</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#10192f;">
        {{ !empty($isPhotographer) ? 'A client requested to cancel this shoot.' : 'Your cancellation request has been received.' }}
    </p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">
        {{ !empty($isPhotographer)
            ? 'Please pause any prep for this assignment while our team reviews the request and confirms the final status.'
            : 'Our team is reviewing the request now. We will send a confirmation once the cancellation is approved and finalized.' }}
    </p>
@endsection

@section('content')
    <p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;">
        <strong class="dark-strong" style="color:#071223;">
            {{ !empty($isPhotographer) ? 'This shoot is still pending review.' : 'Your request has been logged successfully.' }}
        </strong>
    </p>

    @if(!empty($cancellationReason))
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
            <tr>
                <td class="callout-warning-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #ffdcae; background-color:#fff3e3;">
                    <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Cancellation request reason</p>
                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">{{ $cancellationReason }}</p>
                </td>
            </tr>
        </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">What happens next</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">
                    {{ !empty($isPhotographer)
                        ? 'We will send a follow-up once the office approves or rejects the cancellation request.'
                        : 'You do not need to take any further action right now. We will follow up as soon as the request is reviewed.' }}
                </p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px;">
                <a href="{{ $shoot->dashboard_url }}" class="btn-secondary-bg" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#ffffff; color:#173963; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none; border:1px solid #cfe0f5;">Open Dashboard</a>
            </td>
        </tr>
    </table>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'isPhotographer' => !empty($isPhotographer)])
@endsection

@section('footer_note')
    Keep this email for your records until the cancellation request is fully resolved.
@endsection
