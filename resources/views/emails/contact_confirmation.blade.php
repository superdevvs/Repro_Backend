@extends('emails.layouts.master')

@section('title', 'Thank You for Contacting Us')
@section('preheader', 'We received your message and will follow up soon.')

@section('hero')
    <div class="eyebrow">Message Received</div>
    <h1 class="hero-title">Thanks for reaching out.</h1>
    <p class="hero-copy">Your message has been delivered and a team member will follow up as soon as possible.</p>
@endsection

@section('content')
<p class="intro"><strong>We have your message on file.</strong></p>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Your Message</div>
            <div class="section-title">{{ $client->name ?? 'R/E Pro Photos' }}</div>
            <div class="note-card" style="margin-top:16px; margin-bottom:0;">
                <div class="body-copy" style="margin-top:0;">{!! nl2br(e($submission->message)) !!}</div>
            </div>
        </div>
    </div>

    <div class="callout">
        <div class="callout-title">What to expect next</div>
        <p class="callout-copy">Our team will review your message and respond using the contact information you provided. There is no need to send it again unless you want to add more details.</p>
    </div>
@endsection

@section('footer_note')
    This is an automated confirmation message.
@endsection
