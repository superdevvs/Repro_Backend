@extends('emails.layouts.master')

@section('title', 'Your Magic Link')
@section('preheader', 'View shared property media and tours. No account required.')

@section('hero')
    <h1 class="dark-title" style="margin:0;font-size:30px;line-height:36px;font-weight:500;letter-spacing:-0.8px;">Your property overview.</h1>
    <p class="dark-body" style="margin:12px 0 0;font-size:15px;line-height:24px;">The property's released media and tours, in one place.</p>
@endsection

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
        <tr><td class="section-card-bg" style="padding:20px;border-radius:12px;background-color:#f5f7fa;">
            <p class="dark-muted" style="margin:0 0 8px;font-size:11px;line-height:16px;letter-spacing:1.4px;text-transform:uppercase;font-weight:600;">Shared property</p>
            <p class="dark-heading" style="margin:0;font-size:22px;line-height:30px;font-weight:600;">{{ $propertyAddress }}</p>
            <p class="dark-muted" style="margin:10px 0 0;font-size:13px;line-height:20px;">Photos &middot; Videos &middot; Tours</p>
            <p class="dark-muted" style="margin:10px 0 0;font-size:13px;line-height:20px;">Shared by {{ $senderName ?? 'Your sender' }}</p>
        </td></tr>
    </table>
    <p class="dark-body" style="margin:0 0 12px;">Open your Magic Link and verify your email to continue. No account is required.</p>
    <p class="dark-muted" style="margin:0;">{{ $expiryCopy ?? 'Access expires in 30 days.' }} More media will appear in the overview as it is released.</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">
        <tr><td>
            <a class="atelier-button" href="{{ $guestUrl }}" style="display:block;padding:16px;border-radius:8px;background-color:#155bdd;color:#ffffff;font-size:14px;line-height:22px;font-weight:600;text-align:center;text-decoration:none;">Open Magic Link</a>
        </td></tr>
    </table>
@endsection

@section('footer_note')
    This invitation is for your email address. A verification code is required before the property overview opens.
@endsection
