@extends('emails.layouts.master')

@section('title', 'Scheduled Photo Shoot Updated')
@section('preheader', 'Updated details are available for one of your scheduled shoots.')

@section('hero')
    <div class="eyebrow">{{ !empty($isPhotographer) ? 'Assignment Updated' : 'Shoot Updated' }}</div>
    <h1 class="hero-title">{{ !empty($isPhotographer) ? 'A shoot on your calendar has changed.' : 'One of your upcoming shoots has been updated.' }}</h1>
    <p class="hero-copy">The changed fields are highlighted first, followed by the latest full shoot details so you can confirm everything in one pass.</p>
@endsection

@section('content')
    <p class="intro">Hi {{ $user->first_name }}, <strong>please review the latest shoot details below.</strong></p>

    <div class="button-row">
        <a href="{{ $shoot->dashboard_url }}" class="button">Review in Dashboard</a>
    </div>

    @include('emails.partials.change-summary', ['changesSummary' => $changesSummary ?? null])
    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'isPhotographer' => !empty($isPhotographer)])

    @if(empty($isPhotographer))
        <div class="callout callout-warning">
            <div class="callout-title">Need to adjust the appointment?</div>
            <p class="callout-copy">If any of these changes affect your availability or property readiness, please reply as soon as possible so the team can help you reschedule smoothly.</p>
        </div>
    @else
        <div class="callout">
            <div class="callout-title">Please re-check access and timing</div>
            <p class="callout-copy">Any schedule, access, or service change can affect your coverage plan. If something looks off, reach out to the office before heading to the property.</p>
        </div>
    @endif
@endsection

@section('footer_note')
    This message reflects the latest saved shoot details currently visible in the dashboard.
@endsection
