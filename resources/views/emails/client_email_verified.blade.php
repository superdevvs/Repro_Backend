@extends('emails.layouts.master')

@section('title', 'Your Email Is Verified')
@section('preheader', 'Your client email is verified and ready for normal scheduling and notification updates.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#8298b4; font-weight:700;">Email Verified</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:48px; line-height:0.96; font-weight:300; letter-spacing:-2.4px; color:#e8edf5;">You are all set for updates.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#a9b8cb;">Your client email is now verified, so schedule changes, reminders, delivery notices, and account alerts can reach you normally.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#a9b8cb;">
    Hi <strong class="dark-strong" style="color:#e8edf5;">{{ $user->name ?: 'there' }}</strong>, we confirmed <strong class="dark-strong" style="color:#e8edf5;">{{ $user->email }}</strong> for your R/E Pro Photos account.
</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff; padding-right:10px;" bgcolor="#1463ff">
                <a href="{{ $dashboardUrl }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Open Dashboard</a>
            </td>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{{ $settingsUrl }}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Notification Settings</a>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#16233a; border:1px solid #24344d; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#8298b4; font-weight:700;">What this unlocks</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#e8edf5;">Stay current on every schedule and delivery update.</p>
                <p class="dark-body" style="margin:12px 0 0; font-size:14px; line-height:1.75; color:#a9b8cb;">You can now stay up on scheduled shoots, reminders, delivery notifications, invoice emails, and other account updates without restrictions.</p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #24344d; background-color:#16233a;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#e8edf5; font-weight:800;">Need to change what you receive?</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#a9b8cb;">You can review and modify your notification preferences anytime in dashboard settings.</p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    If you did not verify this email, contact our team so we can review the account.
@endsection
