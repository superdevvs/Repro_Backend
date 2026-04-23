@extends('emails.layouts.master')

@section('title', 'Verify Your Email Address')
@section('preheader', 'Verify your client email so important dashboard updates reach the right inbox.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#8298b4; font-weight:700;">Email Verification</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#e8edf5;">Verify your email to unlock normal updates.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#a9b8cb;">Confirm this address so booking confirmations, invoices, delivery updates, and portal access emails can reach you without restrictions.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#a9b8cb;">
    Hi <strong class="dark-strong" style="color:#e8edf5;">{{ $user->name ?: 'there' }}</strong>, please confirm that <strong class="dark-strong" style="color:#e8edf5;">{{ $user->email }}</strong> is the right email address for this account.
</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff; padding-right:10px;" bgcolor="#1463ff">
                <a href="{{ $verificationLink }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Verify Email</a>
            </td>
            <td style="border-radius:999px;">
                <a href="{{ $dashboardUrl }}" class="btn-secondary-bg" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#16233a; color:#e8edf5; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none; border:1px solid #24344d;">Open Dashboard</a>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#16233a; border:1px solid #24344d; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#8298b4; font-weight:700;">Email Details</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#e8edf5;">{{ $user->email }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Account</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">{{ $user->name ?: 'Client account' }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Status</td>
                        <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">Pending verification</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #24344d; background-color:#16233a;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#e8edf5; font-weight:800;">What this changes</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#a9b8cb;">Once verified, your account can receive normal automated booking confirmations, invoice reminders, delivery notifications, and portal access emails.</p>
            </td>
        </tr>
    </table>

    <p class="dark-muted" style="margin:0; font-size:13px; line-height:1.75; color:#8298b4;">
        If the button does not work, copy and paste this link into your browser:<br>
        <a href="{{ $verificationLink }}" style="color:{{ data_get($branding ?? null, 'link_color', '#7eb3ff') }}; text-decoration:underline; word-break:break-all;">{{ $verificationLink }}</a>
    </p>
@endsection

@section('footer_note')
    If this was not you, contact our team so we can review the account email.
@endsection
