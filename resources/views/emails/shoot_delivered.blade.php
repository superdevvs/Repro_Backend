@extends('emails.layouts.master')

@section('title', 'Your Shoot Has Been Delivered')
@section('preheader', 'Your final media has been delivered and is ready to review.')

@section('hero')
    <div class="eyebrow">Delivery Complete</div>
    <h1 class="hero-title">Your shoot has been delivered.</h1>
    <p class="hero-copy">The final media package is now available in your dashboard, along with the latest property delivery details.</p>
@endsection

@section('content')
    <p class="intro">Hi {{ $user->first_name }}, <strong>your finished photos are now delivered and ready for download.</strong></p>

    <div class="button-row">
        <a href="{{ $shoot->dashboard_url }}" class="button button-large">Open Deliverables</a>
    </div>

    <div class="callout callout-success">
        <div class="callout-title">What you can do now</div>
        <p class="callout-copy">Preview the completed files, download the final media, and manage everything for this property from the dashboard.</p>
    </div>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'showNotes' => false])
@endsection

@section('footer_note')
    We appreciate your business and would love to hear about your experience after delivery.
@endsection
