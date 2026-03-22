@extends('emails.layouts.master')

@section('title', 'Reset Your Password')
@section('preheader', 'Use this secure link to reset your R/E Pro Photos password.')

@section('hero')
    <div class="eyebrow">Security</div>
    <h1 class="hero-title">Reset your password.</h1>
    <p class="hero-copy">We received a request to reset the password on your R/E Pro Photos account. Use the secure button below to create a new one.</p>
@endsection

@section('content')
    <p class="intro">Hi {{ $user->first_name }}, <strong>your reset link is ready.</strong></p>

    <div class="button-row">
        <a href="{!! $resetLink !!}" class="button">Reset Password</a>
    </div>

    <div class="callout">
        <div class="callout-title">Important</div>
        <p class="callout-copy">This link will expire in 60 minutes for security reasons. If you did not request this reset, you can safely ignore this email and your password will stay unchanged.</p>
    </div>

    <div class="note-card">
        <div class="note-title">Having trouble with the button?</div>
        <div class="body-copy" style="margin-top:0; font-size:13px;">Copy and paste this link into your browser:</div>
        <div class="fineprint" style="margin-top:10px; color:#47627f;">{!! $resetLink !!}</div>
    </div>
@endsection

@section('footer_note')
    Password reset links are single-purpose security links and should not be shared.
@endsection
