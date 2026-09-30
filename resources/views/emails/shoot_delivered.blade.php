@extends('emails.layouts.master')

@section('title', 'Your Photos Are Ready to Review')
@section('preheader', !empty($paymentLink)
    ? 'Your shoot is ready to review. Complete the remaining balance to unlock downloads.'
    : 'Your shoot is ready to review in your dashboard.')

@php
    // Scoped footer cleanup for the delivered email: present a single canonical
    // URL (the Dashboard) and drop the "Leave a Review" filler. These toggles
    // default ON in the shared layout, so every other template is unaffected.
    $showWebsiteTile = false;
    $showReviewTile = false;
    $formattedBalance = $shoot->formatted_remaining_balance
        ?? '$'.number_format((float) ($shoot->remaining_balance ?? 0), 2);
@endphp

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Photos Ready</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">Your shoot is ready to review.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">
        @if(!empty($paymentLink))
            Open the shoot in your dashboard. Complete the remaining balance to unlock your downloads and receive your Shoot Summary.
        @else
            Open the shoot in your dashboard to review your delivered files.
        @endif
    </p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Your shoot is complete and ready to review.</strong></p>
@if(!empty($paymentLink))
    <p class="dark-body" style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#2d4769;">Remaining balance: <strong>{{ $formattedBalance }}</strong></p>
@endif

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
                        Pay the remaining balance to unlock your files. We will email your Shoot Summary with available download and tour links once payment is complete.
                    @else
                        Open this shoot under Completed Shoots to review the delivered files.
                    @endif
                </p>
            </td>
        </tr>
    </table>

    @include('emails.partials.delivery-share-links', ['deliveryShare' => $deliveryShare ?? null])

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'showNotes' => false, 'showFinancials' => false])
@endsection

@section('footer_note')
    We appreciate your business and would love to hear about your experience after delivery.
@endsection
