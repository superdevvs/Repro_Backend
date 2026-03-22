@extends('emails.layouts.master')

@section('title', 'New Shoot Scheduled')
@section('preheader', !empty($isPhotographer) ? 'A new shoot assignment is ready for review.' : 'Your new R/E Pro Photos shoot has been confirmed.')

@section('hero')
    <div class="eyebrow">{{ !empty($isPhotographer) ? 'New Assignment' : 'Shoot Confirmed' }}</div>
    <h1 class="hero-title">{{ !empty($isPhotographer) ? 'You have been assigned to a new shoot.' : 'Your photo shoot is officially on the calendar.' }}</h1>
    <p class="hero-copy">
        {{ !empty($isPhotographer)
            ? 'Review the property details, service lineup, and notes below so you are fully prepared before arrival.'
            : 'Your booking has been scheduled with the R/E Pro Photos team. Everything you need to review before the appointment is organized below.' }}
    </p>
@endsection

@section('content')
    <p class="intro">Hi {{ $user->first_name }}{{ !empty($isPhotographer) ? '' : ',' }}<strong>{{ !empty($isPhotographer) ? ' your upcoming assignment is ready.' : ' thanks for scheduling with us.' }}</strong></p>

    <div class="button-row">
        <a href="{{ $shoot->dashboard_url }}" class="button">{{ !empty($isPhotographer) ? 'Open Dashboard' : 'View Shoot' }}</a>
        @if(empty($isPhotographer) && !empty($paymentLink))
            <a href="{{ $paymentLink }}" class="button button-secondary">Pay Now</a>
        @endif
    </div>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'isPhotographer' => !empty($isPhotographer)])

    @if(!empty($isPhotographer))
        <div class="callout">
            <div class="callout-title">Before the appointment</div>
            <p class="callout-copy">Please confirm timing, access, and service coverage in advance. If anything creates a scheduling conflict, contact the office as soon as possible so we can realign quickly.</p>
        </div>
    @else
        <div class="callout callout-success">
            <div class="callout-title">How to get the property camera-ready</div>
            <p class="callout-copy">A little prep goes a long way. Use our property-prep guide to help the home look polished and consistent across photos, video, and tours.</p>
            <div class="button-row" style="margin-bottom:0;">
                <a href="{{ $shoot->property_prep_url }}" class="button button-secondary">View Prep Guide</a>
            </div>
        </div>

        <div class="callout callout-warning">
            <div class="callout-title">Cancellation policy</div>
            <p class="callout-copy">If an appointment is cancelled on-site, a $60 cancellation fee applies. To avoid that charge, please reschedule or cancel at least 6 hours before the start of the appointment.</p>
        </div>
    @endif
@endsection

@section('footer_note')
    {{ !empty($isPhotographer)
        ? 'Need help before the shoot? Call or email the office so we can support the assignment.'
        : 'Payments can be made at any time during the shoot process. Final media access is released once the balance is paid in full.' }}
@endsection
