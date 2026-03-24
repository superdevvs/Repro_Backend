@extends('emails.layouts.master')

@section('title', 'Your Shoot Has Been Cancelled')
@section('preheader', 'Your scheduled shoot has been cancelled and the latest details are included for your records.')

@section('hero')
    <div class="eyebrow">Cancelled</div>
    <h1 class="hero-title">Your shoot has been cancelled.</h1>
    <p class="hero-copy">The last confirmed details are included below for your records, along with the fastest way to rebook if needed.</p>
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
