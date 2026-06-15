@extends('emails.layouts.master')

@section('title', 'Reset Your Password')
@section('preheader', 'Use this secure link to reset your R/E Pro Photos password.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Security</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">Reset your password.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">We received a request to reset the password on your R/E Pro Photos account. Use the secure button below to create a new one.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Your reset link is ready.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 8px;">
        <tr>
            <td style="border-radius:999px; background-color:#1463ff;" bgcolor="#1463ff">
                <a href="{!! $resetLink !!}" style="display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; color:#ffffff; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none;">Reset Password</a>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="callout-bg" style="padding:18px 20px; border-radius:14px; border:1px solid #dce7f5; background-color:#f7fbff;">
                <p class="dark-heading" style="margin:0 0 8px; font-size:16px; line-height:1.4; color:#071223; font-weight:800;">Important</p>
                <p class="dark-body" style="margin:0; font-size:14px; line-height:1.7; color:#47627f;">This link will expire in 60 minutes for security reasons. If you did not request this reset, you can safely ignore this email and your password will stay unchanged.</p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:16px;">
        <tr>
            <td class="note-card-bg" style="border-radius:14px; background-color:#f8fbff; border:1px solid #dbe7f8; padding:18px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:13px; line-height:1.5; letter-spacing:1.3px; text-transform:uppercase; color:#60799a; font-weight:800;">Having trouble with the button?</p>
                <p class="dark-body" style="margin:0; font-size:13px; line-height:1.7; color:#4f6886;">Copy and paste this link into your browser:</p>
                <p class="dark-muted" style="margin:10px 0 0; color:#47627f; font-size:12px; line-height:1.7; word-break:break-all;">{!! $resetLink !!}</p>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    Password reset links are single-purpose security links and should not be shared.
@endsection
