@extends('emails.layouts.master')

@section('title', 'Your Photos Are Ready!')
@section('preheader', 'Your completed media is ready to review and download.')

@section('hero')
    <div class="eyebrow">Delivery Ready</div>
    <h1 class="hero-title">Your media package is ready.</h1>
    <p class="hero-copy">The shoot has moved into its finished stage and the final files are now available in your dashboard.</p>
@endsection

@section('content')
<p class="intro"><strong>Your finished photos are now ready for download.</strong></p>

    <div class="button-row">
        <a href="{{ $shoot->dashboard_url }}" class="button">Open Deliverables</a>
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
