@extends('emails.layouts.master')

@section('title', 'Verify Your Email Address')
@section('preheader', 'Verify your client email so important dashboard updates reach the right inbox.')

@section('extra-styles')
    <style>
        .email-container .hero-pad img {
            width: 126px !important;
            max-width: 126px !important;
        }
    </style>
@endsection

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#8298b4; font-weight:700;">Email Verification</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#e8edf5;">Confirm your email</p>
    <p class="dark-body" style="margin:18px 0 0; max-width:480px; font-size:15px; line-height:1.8; color:#a9b8cb;">Verify this address so your schedule updates, invoices, delivery alerts, and dashboard notifications reach the right inbox.</p>
@endsection

@section('content')
    <p class="dark-heading" style="margin:0 0 18px; font-size:18px; line-height:1.6; color:#e8edf5; font-weight:800;">
        Hi {{ $user->name ?: 'there' }}!
    </p>

    <p class="dark-body" style="margin:0 0 8px; max-width:460px; font-size:16px; line-height:1.85; color:#a9b8cb;">
        Thanks for joining R/E Pro Photos.
    </p>
    <p class="dark-body" style="margin:0 0 24px; max-width:460px; font-size:16px; line-height:1.85; color:#a9b8cb;">
        Please confirm that <strong class="dark-strong" style="color:#e8edf5;">{{ $user->email }}</strong> is your email address by clicking the button below.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $verificationLink }}" style="display:inline-block; padding:16px 30px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:15px; line-height:1.2; text-decoration:none;">Confirm email address</a>
            </td>
        </tr>
    </table>

    <p class="dark-muted" style="margin:0 0 10px; max-width:460px; font-size:14px; line-height:1.8; color:#8298b4;">
        If the button does not work, copy and paste this link into your browser:
    </p>
    <p style="margin:0; max-width:460px; font-size:13px; line-height:1.85;">
        <a href="{{ $verificationLink }}" style="color:{{ data_get($branding ?? null, 'link_color', '#7eb3ff') }}; text-decoration:underline; word-break:break-all;">{{ $verificationLink }}</a>
    </p>

    <p class="dark-muted" style="margin:28px 0 0; max-width:460px; font-size:15px; line-height:1.8; color:#8298b4;">
        Thanks,<br>
        <span class="dark-heading" style="color:#e8edf5; font-weight:700;">The R/E Pro Photos Team</span>
    </p>
@endsection

@section('footer_note')
    If this was not you, contact our team so we can review the account email before any updates are sent.
@endsection
