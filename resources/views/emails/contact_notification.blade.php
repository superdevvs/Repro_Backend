@extends('emails.layouts.master')
@section('title', 'New Contact Form Submission')
@section('extra-styles')
    <style>
        .field {
            margin-bottom: 15px;
        }
        .field-label {
            font-weight: bold;
            color: #374151;
            font-size: 14px;
        }
        .field-value {
            color: #1f2937;
            margin-top: 4px;
        }
        .message-box {
            background-color: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            margin-top: 8px;
        }
        .contact-footer {
            text-align: center;
            color: #6b7280;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
@endsection
@section('content')
    <h2 style="margin:0 0 16px;font-size:20px;color:#1a1a1a;">New Contact Form Submission</h2>

    <p>Hello {{ $client->name }},</p>
    
    <p>You have received a new message through your portfolio contact form.</p>
    
    <div class="field">
        <div class="field-label">From:</div>
        <div class="field-value">{{ $submission->sender_name }}</div>
    </div>
    
    <div class="field">
        <div class="field-label">Email:</div>
        <div class="field-value">
            <a href="mailto:{{ $submission->sender_email }}">{{ $submission->sender_email }}</a>
        </div>
    </div>
    
    @if($submission->sender_phone)
    <div class="field">
        <div class="field-label">Phone:</div>
        <div class="field-value">{{ $submission->sender_phone }}</div>
    </div>
    @endif
    
    <div class="field">
        <div class="field-label">Message:</div>
        <div class="message-box">
            {!! nl2br(e($submission->message)) !!}
        </div>
    </div>
    
    <p style="margin-top: 20px; font-size: 14px; color: #6b7280;">
        Received on {{ $submission->created_at->format('F j, Y \a\t g:i A') }}
    </p>

    <div class="contact-footer">
        <p>This message was sent via your REPRO HQ portfolio.</p>
    </div>
@endsection
