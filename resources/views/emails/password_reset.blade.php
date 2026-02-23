@extends('emails.layouts.master')
@section('title', 'Reset Your Password')
@section('extra-styles')
    <style>
        .button {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 30px;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #1d4ed8;
        }
        .link-text {
            word-break: break-all;
            font-size: 12px;
            color: #666;
            background-color: #f5f5f5;
            padding: 10px;
            border-radius: 4px;
            margin-top: 15px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #888;
        }
    </style>
@endsection
@section('content')
    <h1 style="color:#1a1a1a;font-size:24px;margin-bottom:20px;">Reset Your Password</h1>
    
    <p>Hi {{ $user->first_name }},</p>
    
    <p>We received a request to reset your password for your REPro Photos account. Click the button below to create a new password:</p>
    
    <p style="text-align: center;">
        <a href="{!! $resetLink !!}" class="button">Reset Password</a>
    </p>
    
    <p>This link will expire in 60 minutes for security reasons.</p>
    
    <p>If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.</p>
    
    <div class="link-text">
        <strong>Can't click the button?</strong> Copy and paste this link into your browser:<br>
        {!! $resetLink !!}
    </div>
    
    <div class="footer">
        <p>This email was sent by R/E Pro Photos.<br>
        If you have any questions, please contact us at <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a></p>
    </div>
@endsection
