@extends('emails.layouts.master')
@section('title', 'Your '.$planName.' Listing Studio plan is ready')
@section('preheader', 'Your payment is confirmed and '.$credit.' editing credit has been added to your account.')
@section('hero')
<p class="dark-muted" style="margin:0 0 12px;color:#0088ff;font-size:12px;font-weight:700;letter-spacing:1.2px;">LISTING STUDIO</p>
<h1>Your {{ $planName }} plan is ready.</h1>
@endsection
@section('content')
<p>Hi {{ $clientName }},</p>
<p>Your payment is confirmed. Your {{ $planName }} subscription and editing credit are now linked to your dashboard account.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;border:1px solid #0088ff;border-radius:16px;">
    <tr><td style="padding:24px;">
        <p class="dark-muted" style="margin:0 0 10px;font-size:12px;">EDITING CREDIT ADDED</p>
        <p style="margin:0 0 8px;font-size:40px;line-height:48px;font-weight:700;">{{ $credit }}</p>
        <p class="dark-muted" style="margin:0;font-size:13px;">For your next listing. Available through {{ $expires }}.</p>
    </td></tr>
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
    <tr><td class="dark-muted" style="padding:10px 0;">Your plan</td><td align="right" style="padding:10px 0;font-weight:600;">{{ $planName }}</td></tr>
    <tr><td class="dark-muted" style="padding:10px 0;">Monthly plan price</td><td align="right" style="padding:10px 0;font-weight:600;">{{ $monthlyPrice }} USD / month</td></tr>
    <tr><td class="dark-muted" style="padding:10px 0;">Billed today</td><td align="right" style="padding:10px 0;font-weight:600;">{{ $paid }} USD</td></tr>
    <tr><td class="dark-muted" style="padding:10px 0;">Credit expires</td><td align="right" style="padding:10px 0;font-weight:600;">{{ $expires }}</td></tr>
</table>
<h2>Ready for your next listing?</h2>
<p>Stage a room, clear everyday clutter, refresh a lawn or create a twilight exterior. Each paid AI edit includes up to three AI revisions.</p>
<p><a href="{{ $dashboardUrl }}" class="button">Open Listing Studio</a></p>
<p class="dark-muted" style="font-size:12px;line-height:20px;">This is a monthly subscription. Editing credit expires at the end of each billing period and does not roll over. Your account shows your current balance after any edits or reservations. Manage your plan and invoices from Listing Studio.</p>
@endsection
