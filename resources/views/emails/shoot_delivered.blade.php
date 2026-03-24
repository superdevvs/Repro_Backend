@extends('emails.layouts.master')

@section('title', 'Your Photos Are Ready')
@section('preheader', 'Your photos are ready to review and download in the dashboard.')

@section('hero')
    <div class="eyebrow">Photos Ready</div>
    <h1 class="hero-title">Your photos are ready.</h1>
    <p class="hero-copy">Your final media package is now available in the dashboard, along with the latest property delivery details.</p>
@endsection

@section('content')
<p class="intro"><strong>Your finished photos are ready for review and download.</strong></p>

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
