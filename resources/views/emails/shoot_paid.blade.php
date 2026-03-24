@extends('emails.layouts.master')

@section('title', 'Payment Confirmed for Your Shoot')
@section('preheader', 'Your payment has been recorded and your shoot account now reflects a paid status.')

@section('hero')
    <div class="eyebrow">Payment Confirmed</div>
    <h1 class="hero-title">Your payment has been recorded.</h1>
    <p class="hero-copy">Your shoot now shows a paid status in the dashboard, and the latest appointment details are included below for reference.</p>
@endsection

@section('content')
<p class="intro"><strong>Thank you. This shoot is now marked as paid.</strong></p>

    <div class="callout callout-success">
        <div class="callout-title">Payment update</div>
        <p class="callout-copy">Amount received: {{ '$' . number_format($amount, 2) }}. You can review the full shoot details and any available deliverables from the dashboard.</p>
    </div>

    <div class="button-row">
        <a href="{{ $shoot->dashboard_url }}" class="button">Open Dashboard</a>
    </div>

    @include('emails.partials.shoot-summary', ['shoot' => $shoot, 'showNotes' => false])
@endsection

@section('footer_note')
    Keep this email for your records as confirmation that payment was applied to this shoot.
@endsection
