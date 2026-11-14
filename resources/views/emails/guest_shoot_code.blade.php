@extends('emails.layouts.master')

@section('title', 'Your property access code')
@section('preheader', 'Your verification code expires in 10 minutes.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 16px;font-size:11px;line-height:16px;letter-spacing:1.4px;text-transform:uppercase;font-weight:600;">Secure guest access</p>
    <h1 class="dark-title" style="margin:0;font-size:36px;line-height:42px;font-weight:500;letter-spacing:-1.2px;">Your access code.</h1>
    <p class="dark-body" style="margin:20px 0 0;font-size:16px;line-height:26px;">Enter this code in the property overview to view the shared media and tours.</p>
@endsection

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
        <tr><td class="section-card-bg" align="center" style="padding:24px;border-radius:12px;background-color:#f5f7fa;text-align:center;">
            <p class="dark-muted" style="margin:0 0 12px;font-size:11px;line-height:16px;letter-spacing:1.4px;text-transform:uppercase;font-weight:600;">Verification code</p>
            <p class="dark-heading" style="margin:0;font-family:Consolas,Monaco,monospace;font-size:36px;line-height:48px;font-weight:600;letter-spacing:8px;">{{ $verificationCode }}</p>
        </td></tr>
    </table>
    <p class="dark-body" style="margin:0 0 12px;">This code expires in <strong class="dark-strong">10 minutes</strong>. No account is required.</p>
    <p class="dark-muted" style="margin:0;">If you did not request this code, ignore this email.</p>
@endsection

@section('footer_note')
    Keep this code private. Only enter it on the property overview opened from your Magic Link.
@endsection
