@extends('emails.layouts.master')

@section('title', 'Photo Shoot Cancelled')
@section('preheader', 'A scheduled shoot has been cancelled or removed.')

@section('hero')
    <div class="eyebrow">Shoot Cancelled</div>
    <h1 class="hero-title">This appointment is no longer on the schedule.</h1>
    <p class="hero-copy">The shoot shown below has been cancelled or removed. The last confirmed details are included here for your records.</p>
@endsection

@section('content')
<p class="intro"><strong>This shoot has been cancelled.</strong></p>

    <div class="callout callout-danger">
        <div class="callout-title">What happens next</div>
        <p class="callout-copy">If you still need media for this property, reply to this email or rebook through the dashboard and our team will help you get a new appointment scheduled quickly.</p>
    </div>

    <div class="button-row">
        <a href="{{ $shoot->dashboard_url }}" class="button button-secondary">Open Dashboard</a>
    </div>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot])
@endsection

@section('footer_note')
    Keep this email if you need a record of the cancelled appointment details.
@endsection
