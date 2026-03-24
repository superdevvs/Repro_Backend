@extends('emails.layouts.master')

@section('title', 'New Contact Form Submission')
@section('preheader', 'A new message has arrived through your portfolio contact form.')

@section('hero')
    <div class="eyebrow">New Inquiry</div>
    <h1 class="hero-title">You have a new portfolio lead.</h1>
    <p class="hero-copy">A visitor submitted a message through your contact form. Their full details and message are listed below for quick follow-up.</p>
@endsection

@section('content')
<p class="intro"><strong>A new contact form submission just came in.</strong></p>

    <div class="section-card">
        <div class="section-pad">
            <div class="section-kicker">Lead Details</div>
            <div class="section-title">{{ $submission->sender_name }}</div>
            <table class="detail-table" role="presentation" style="margin-top:12px;">
                <tr>
                    <td class="detail-label">Email</td>
                    <td class="detail-value"><a href="mailto:{{ $submission->sender_email }}">{{ $submission->sender_email }}</a></td>
                </tr>
                @if($submission->sender_phone)
                    <tr>
                        <td class="detail-label">Phone</td>
                        <td class="detail-value">{{ $submission->sender_phone }}</td>
                    </tr>
                @endif
                <tr>
                    <td class="detail-label">Received</td>
                    <td class="detail-value">{{ $submission->created_at->format('F j, Y \a\t g:i A') }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="note-card">
        <div class="note-title">Message</div>
        <div class="body-copy" style="margin-top:0;">{!! nl2br(e($submission->message)) !!}</div>
    </div>
@endsection

@section('footer_note')
    This inquiry was submitted through your REPRO HQ portfolio experience.
@endsection
