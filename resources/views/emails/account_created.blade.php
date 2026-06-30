@extends('emails.layouts.master')

@section('title', 'New Account Information')
@section('preheader', 'Your new R/E Pro Photos dashboard account is ready.')

@php
    // New Account uses a single URL type (Dashboard); hide the Website tile to
    // avoid showing both the company website and the dashboard URL.
    $showWebsiteTile = false;
@endphp

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#8298b4; font-weight:700;">Account Created</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#e8edf5;">Your dashboard access is ready.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#a9b8cb;">We created your R/E Pro Photos account so you can schedule shoots, track production, and manage billing in one place.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#a9b8cb;"><strong class="dark-strong" style="color:#e8edf5;">Welcome to the R/E Pro Photos dashboard.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 8px;">
        @if(!empty($verificationLink))
        <tr>
            <td align="center" style="padding-bottom:10px;">
                <a href="{{ $verificationLink }}" style="display:block; width:100%; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none; text-align:center; white-space:nowrap;">Verify Email</a>
            </td>
        </tr>
        @endif
        @if(!empty($resetLink) && !empty($includePasswordCreationLink))
        <tr>
            <td align="center" style="padding-bottom:10px;">
                <a href="{{ $resetLink }}" style="display:block; width:100%; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none; text-align:center; white-space:nowrap;">Create Password</a>
            </td>
        </tr>
        @endif
        @if(!empty($equipmentVerificationUrl))
        <tr>
            <td align="center" style="padding-bottom:10px;">
                <a href="{{ $equipmentVerificationUrl }}" style="display:block; width:100%; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none; text-align:center; white-space:nowrap;">Verify Equipment</a>
            </td>
        </tr>
        @endif
        <tr>
            <td align="center">
                <a href="{{ data_get($branding ?? null, 'dashboard_url', 'https://reprodashboard.com') }}" style="display:block; width:100%; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none; text-align:center; white-space:nowrap;">Open Dashboard</a>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#16233a; border:1px solid #24344d; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#8298b4; font-weight:700;">Account Details</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#e8edf5;">{{ $user->name }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:12px;">
                    @if(!empty($user->company_name))
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Company</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">{{ $user->company_name }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="detail-label-td detail-border dark-muted" width="34%" style="padding:10px 14px 10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Email</td>
                        <td class="detail-value-td detail-border dark-heading" style="padding:10px 0; border-bottom:1px solid #24344d; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">{{ $user->email }}</td>
                    </tr>
                    <tr>
                        <td class="detail-label-td dark-muted" width="34%" style="padding:10px 14px 10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#8298b4; font-weight:700;">Phone</td>
                        <td class="detail-value-td dark-heading" style="padding:10px 0; vertical-align:top; font-size:14px; line-height:1.65; color:#e8edf5; font-weight:600;">{{ $user->phonenumber ?? 'N/A' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #24344d; background-color:#16233a;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#e8edf5; font-weight:800;">Your next step</p>
                @if(!empty($equipmentVerificationUrl))
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#a9b8cb;">Open the dashboard to verify {{ ($equipmentCount ?? 0) > 1 ? 'your assigned equipments' : 'your assigned equipment' }} by uploading clear photos from your profile settings. This lets the admin team approve your equipment record before upcoming work.</p>
                @elseif(!empty($verificationLink) && !empty($resetLink) && !empty($includePasswordCreationLink))
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#a9b8cb;">Verify your email, then create your password to access the dashboard. If you already verified your email and need to return later, use Create Password from this email.</p>
                @elseif(!empty($resetLink) && !empty($includePasswordCreationLink))
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#a9b8cb;">Create your password to access the dashboard, then open it anytime to manage your account.</p>
                @else
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#a9b8cb;">Verify your email to make sure booking updates, delivery notifications, and account alerts reach the right inbox, then open the dashboard anytime to manage your account.</p>
                @endif
            </td>
        </tr>
    </table>

    <p class="dark-body" style="margin:18px 0 0; font-size:16px; line-height:1.75; color:#a9b8cb;">Thank you for the opportunity.</p>
@endsection

@section('footer_note')
    If you were not expecting this account, contact our team right away.
@endsection
