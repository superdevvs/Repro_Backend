@extends('emails.layouts.master')

@section('title', 'Terms/Conditions Accepted')
@section('preheader', 'A record of the terms and conditions accepted for your account.')

@section('hero')
    <p class="dark-muted" style="margin:0 0 12px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">Terms Accepted</p>
    <p class="hero-title-td dark-title" style="margin:0; font-size:30px; line-height:1.1; font-weight:300; letter-spacing:-1.2px; color:#10192f;">Your terms acceptance has been recorded.</p>
    <p class="dark-body" style="margin:20px 0 0; font-size:15px; line-height:1.8; color:#667a96;">This email keeps a copy of the current terms and conditions for your records, along with a quick summary of the most important points.</p>
@endsection

@section('content')
<p class="dark-body" style="margin:0 0 16px; font-size:16px; line-height:1.75; color:#2d4769;"><strong class="dark-strong" style="color:#071223;">Thank you for accepting the terms and conditions.</strong></p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Payment</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">Payment is due in full at the time of booking.</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 8px 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Changes</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">Changes or cancellations close to the appointment may incur fees.</p>
                </td></tr></table>
            </td>
            <td class="stat-td" style="padding:0 0 10px 0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td class="stat-card-bg" style="border-radius:14px; background-color:#f5f9ff; border:1px solid #dbe6f3; padding:16px 16px 14px;">
                    <p class="dark-muted" style="margin:0 0 6px; font-size:11px; line-height:1.3; letter-spacing:1.6px; text-transform:uppercase; color:#7f95b1; font-weight:700;">Usage Rights</p>
                    <p class="dark-body" style="margin:0; font-size:13px; line-height:1.6; color:#69819f;">Usage rights are granted after full payment is received.</p>
                </td></tr></table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:18px;">
        <tr>
            <td class="section-card-bg section-inner" style="background-color:#ffffff; border:1px solid #dbe6f3; border-radius:18px; padding:20px 22px;">
                <p class="dark-muted" style="margin:0 0 8px; font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Full Agreement</p>
                <p class="dark-heading" style="margin:0; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">Terms and conditions</p>
                <div class="dark-body" style="margin:12px 0 0; font-size:15px; line-height:1.75; color:#4f6886;">
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Property.</strong> The Property is the real property identified in your agreement with R/E Pro Photos. You certify that you are authorized to engage our services for that property and to accept these terms on behalf of the owner if applicable.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Payment.</strong> Payment is due in full at the time of booking. Usage rights are not granted until full payment is received.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Changes and cancellations.</strong> Shoot dates or times may be changed or cancelled without penalty up to twenty-four (24) hours before the scheduled time. Late changes, cancellations, or camera-unready properties may result in additional fees.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Grant of rights.</strong> You grant R/E Pro Photos a non-exclusive, perpetual, transferable, sublicensable worldwide right to create, reproduce, display, transmit, and distribute the work and derivative works through any media now known or later developed.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Usage rights.</strong> In exchange, we grant you a non-exclusive, non-transferable right to use the media for marketing the property, your real estate business, the listing, and your brokerage, including MLS and portal distribution while the listing remains active.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Releases and authorizations.</strong> You warrant that you have obtained all permissions, waivers, and clearances needed for the creation and use of the work, including owner and occupant permissions where required.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Indemnification.</strong> You agree to indemnify and hold harmless R/E Pro Photos from claims, costs, or damages related to your breach of these warranties or your negligent or intentional acts or omissions.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Fees due.</strong> Both you and any party on whose behalf you contract with us remain jointly and severally liable for payment and performance under the agreement.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Modifications.</strong> R/E Pro Photos may modify these terms at any time. The relationship is governed by the laws of the State of Maryland.</p>
                    <p style="margin:0 0 12px;"><strong class="dark-strong" style="color:#071223;">Identifying information.</strong> We collect identifying information needed to schedule shoots and manage your account, and we safeguard that information appropriately.</p>
                    <p style="margin:0;"><strong class="dark-strong" style="color:#071223;">Email permissions.</strong> Email is our primary communication method for account updates and marketing communications. You may opt out of marketing emails by contacting <a href="mailto:contact@reprophotos.com" style="color:#1463ff; text-decoration:underline;">contact@reprophotos.com</a>.</p>
                </div>
            </td>
        </tr>
    </table>
@endsection

@section('footer_note')
    Keep this message as your acceptance record. If you need the latest policy wording, contact our team.
@endsection
