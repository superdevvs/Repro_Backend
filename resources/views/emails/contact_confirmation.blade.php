@extends('emails.layouts.master')
@section('title', 'Thank You for Contacting Us')
@section('extra-styles')
    <style>
        .confirm-footer {
            text-align: center;
            color: #6b7280;
            font-size: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
    </style>
@endsection
@section('content')
    <h1 style="margin:0 0 16px;font-size:24px;color:#1f2937;">Thank You!</h1>

    <p>Hello {{ $submission->sender_name }},</p>
    
    <p>Thank you for reaching out to {{ $client->name ?? 'us' }}. We have received your message and will get back to you as soon as possible.</p>
    
    <p>Here's a copy of your message:</p>
    
    <blockquote style="background-color: #f9fafb; padding: 15px; border-left: 4px solid #2563eb; margin: 20px 0;">
        {!! nl2br(e($submission->message)) !!}
    </blockquote>
    
    <p>We appreciate your interest and look forward to connecting with you.</p>
    
    <p>Best regards,<br>{{ $client->name ?? 'The Team' }}</p>

    <div class="confirm-footer">
        <p>This is an automated confirmation email. Please do not reply directly to this message.</p>
    </div>
@endsection
