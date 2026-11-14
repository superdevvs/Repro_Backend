@extends('emails.layouts.master')

@section('title', 'Your Magic Link')
@section('preheader', 'View shared property media and tours. No account required.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 16px;font-size:11px;line-height:16px;letter-spacing:1.4px;text-transform:uppercase;font-weight:600;">Shared property</p>
    <h1 class="dark-title" style="margin:0;font-size:36px;line-height:42px;font-weight:500;letter-spacing:-1.2px;">Your property overview.</h1>
    <p class="dark-body" style="margin:20px 0 0;font-size:16px;line-height:26px;">You have been invited to view released media and tours for this property.</p>
@endsection

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
        <tr><td class="section-card-bg" style="padding:24px;border-radius:12px;background-color:#f5f7fa;">
            <p class="dark-muted" style="margin:0 0 8px;font-size:11px;line-height:16px;letter-spacing:1.4px;text-transform:uppercase;font-weight:600;">Property</p>
            <p class="dark-heading" style="margin:0;font-size:20px;line-height:28px;font-weight:600;">{{ $propertyAddress }}</p>
        </td></tr>
    </table>
    <p class="dark-body" style="margin:0 0 12px;">Open your Magic Link, then request an email verification code to continue. No account is required.</p>
    <p class="dark-muted" style="margin:0;">Access expires in 30 days. More media will appear in the overview as it is released.</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">
        <tr><td>
            <a class="atelier-button" href="{{ $guestUrl }}" style="display:block;padding:16px;border-radius:8px;background-color:#155bdd;color:#ffffff;font-size:14px;line-height:22px;font-weight:600;text-align:center;text-decoration:none;">Open Magic Link</a>
        </td></tr>
    </table>
@endsection

@section('footer_note')
    This invitation is for your email address. A verification code is required before the property overview opens.
@endsection
