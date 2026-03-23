@php
    $greetingName = trim((string) ($user->first_name ?? $user->name ?? 'there'));
    $supportEmail = $shoot->support_email ?? 'contact@reprophotos.com';
    $supportPhone = $shoot->support_phone ?? '202-868-1663';
    $dashboardUrl = $shoot->dashboard_url ?? 'https://reprodashboard.com';
    $websiteUrl = $shoot->website_url ?? 'https://reprophotos.com';
    $reviewUrl = $shoot->review_url ?? 'https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews';
    $recordedOn = now()->format('M j, Y');
    $propertyHighlights = is_iterable($shoot->property_highlights ?? null) ? $shoot->property_highlights : [];
    $services = is_iterable($shoot->services ?? null) ? $shoot->services : [];
    $accessDetails = is_iterable($shoot->access_details ?? null) ? $shoot->access_details : [];
    $notesLines = is_iterable($shoot->notes_lines ?? null) ? $shoot->notes_lines : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Your Shoot Has Been Marked as Paid</title>
</head>
<body style="margin:0; padding:0; background-color:#eef3f8; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; color:#10233b;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all; color:transparent;">
        This shoot has now been marked paid.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; background-color:#eef3f8;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:680px; border-collapse:collapse; margin:0 auto;">
                    <tr>
                        <td style="padding-bottom:16px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; background-color:#ffffff; border:1px solid #dbe6f3; border-radius:28px;">
                                <tr>
                                    <td style="padding:32px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse;">
                                            <tr>
                                                <td valign="top">
                                                    <img src="https://api.reprodashboard.com/images/Repro%20HQ%20dark.png" alt="R/E Pro Photos" width="154" style="display:block; width:154px; max-width:154px; height:auto; border:0;">
                                                </td>
                                                <td valign="top" align="right" style="padding-left:16px; color:#6f86a4; font-size:13px; line-height:1.5; font-weight:700;">
                                                    <div style="font-size:11px; letter-spacing:1.5px; text-transform:uppercase; color:#7f90a7;">Email Update</div>
                                                    <div style="font-size:16px; line-height:1.4; color:#1d2940; font-weight:800;">R/E Pro Photos</div>
                                                </td>
                                            </tr>
                                        </table>

                                        <div style="margin-top:24px; font-size:11px; line-height:1.4; letter-spacing:2px; text-transform:uppercase; color:#5d7493; font-weight:700;">
                                            Paid in Full
                                        </div>
                                        <div style="margin-top:12px; font-size:40px; line-height:1.08; letter-spacing:-1.6px; color:#10192f; font-weight:300;">
                                            This shoot has been marked as paid.
                                        </div>
                                        <div style="margin-top:16px; font-size:15px; line-height:1.8; color:#667a96;">
                                            Your account now reflects a paid status for the appointment below, and any delivery access tied to payment can proceed normally.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; background-color:#ffffff; border:1px solid #dbe6f3; border-radius:28px;">
                                <tr>
                                    <td style="padding:32px;">
                                        <div style="font-size:16px; line-height:1.75; color:#2d4769; margin:0 0 18px 0;">
                                            Hi {{ $greetingName }}, <strong style="color:#071223;">good news: this shoot is now marked paid.</strong>
                                        </div>

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; border:1px solid #dbe6f3; border-radius:22px; background-color:#ffffff; margin:0 0 18px 0;">
                                            <tr>
                                                <td style="padding:20px 22px;">
                                                    <div style="font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Payment Update</div>
                                                    <div style="margin-top:8px; font-size:22px; line-height:1.25; font-weight:800; color:#071223;">{{ '$' . number_format($amount, 2) }}</div>
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; margin-top:12px;">
                                                        <tr>
                                                            <td style="padding:10px 0; border-bottom:1px solid #edf2f7; width:34%; color:#6f86a4; font-size:14px; line-height:1.65; font-weight:700;">Recorded on</td>
                                                            <td style="padding:10px 0; border-bottom:1px solid #edf2f7; color:#10233b; font-size:14px; line-height:1.65; font-weight:600;">{{ $recordedOn }}</td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding:10px 0; color:#6f86a4; font-size:14px; line-height:1.65; font-weight:700;">Payment status</td>
                                                            <td style="padding:10px 0; color:#10233b; font-size:14px; line-height:1.65; font-weight:600;">Paid</td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; margin:0 0 18px 0;">
                                            <tr>
                                                <td align="center" bgcolor="#1463ff" style="border-radius:999px; background:#1463ff;">
                                                    <a href="{{ $dashboardUrl }}" style="display:inline-block; padding:14px 24px; color:#ffffff; text-decoration:none; font-size:14px; line-height:1.2; font-weight:800;">Open Dashboard</a>
                                                </td>
                                            </tr>
                                        </table>

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; border:1px solid #dbe6f3; border-radius:22px; background-color:#ffffff; margin:0 0 18px 0;">
                                            <tr>
                                                <td style="padding:20px 22px;">
                                                    <div style="font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Shoot Overview</div>
                                                    <div style="margin-top:8px; font-size:22px; line-height:1.25; color:#071223; font-weight:800;">{{ $shoot->location }}</div>
                                                    <div style="margin-top:8px; font-size:15px; line-height:1.75; color:#4f6886;">Everything currently scheduled for this property is organized below, including the service lineup and assigned team.</div>
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; margin-top:14px;">
                                                        <tr>
                                                            <td style="padding:10px 0; border-top:1px solid #edf2f7; border-bottom:1px solid #edf2f7; width:34%; color:#6f86a4; font-size:14px; line-height:1.65; font-weight:700;">Schedule</td>
                                                            <td style="padding:10px 0; border-top:1px solid #edf2f7; border-bottom:1px solid #edf2f7; color:#10233b; font-size:14px; line-height:1.65; font-weight:600;">{{ $shoot->date }}</td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding:10px 0; border-bottom:1px solid #edf2f7; color:#6f86a4; font-size:14px; line-height:1.65; font-weight:700;">Client</td>
                                                            <td style="padding:10px 0; border-bottom:1px solid #edf2f7; color:#10233b; font-size:14px; line-height:1.65; font-weight:600;">{{ $shoot->client_name ?? 'N/A' }}</td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding:10px 0; color:#6f86a4; font-size:14px; line-height:1.65; font-weight:700;">Photographers</td>
                                                            <td style="padding:10px 0; color:#10233b; font-size:14px; line-height:1.65; font-weight:600;">{{ $shoot->photographers_label ?: 'TBD' }}</td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        @if(!empty($propertyHighlights))
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; border:1px solid #dbe6f3; border-radius:22px; background-color:#ffffff; margin:0 0 18px 0;">
                                                <tr>
                                                    <td style="padding:20px 22px;">
                                                        <div style="font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Property</div>
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; margin-top:12px;">
                                                            @foreach($propertyHighlights as $highlight)
                                                                <tr>
                                                                    <td style="padding:10px 0; border-bottom:1px solid #edf2f7; width:34%; color:#6f86a4; font-size:14px; line-height:1.65; font-weight:700;">{{ $highlight['label'] }}</td>
                                                                    <td style="padding:10px 0; border-bottom:1px solid #edf2f7; color:#10233b; font-size:14px; line-height:1.65; font-weight:600;">{{ $highlight['value'] }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </table>
                                                    </td>
                                                </tr>
                                            </table>
                                        @endif

                                        @if(!empty($services))
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; border:1px solid #dbe6f3; border-radius:22px; background-color:#ffffff; margin:0 0 18px 0;">
                                                <tr>
                                                    <td style="padding:20px 22px;">
                                                        <div style="font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Services</div>
                                                        <div style="margin-top:8px; font-size:22px; line-height:1.25; color:#071223; font-weight:800;">Booked deliverables</div>
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; margin-top:14px;">
                                                            <tr>
                                                                <td style="padding:0 0 12px 0; border-bottom:1px solid #edf2f7; color:#7b91ac; font-size:11px; line-height:1.4; letter-spacing:1.4px; text-transform:uppercase; font-weight:800;">Service</td>
                                                                <td align="right" style="padding:0 0 12px 0; border-bottom:1px solid #edf2f7; color:#7b91ac; font-size:11px; line-height:1.4; letter-spacing:1.4px; text-transform:uppercase; font-weight:800;">Total</td>
                                                            </tr>
                                                            @foreach($services as $service)
                                                                <tr>
                                                                    <td style="padding:12px 0; border-bottom:1px solid #edf2f7; vertical-align:top;">
                                                                        <div style="color:#071223; font-size:14px; line-height:1.55; font-weight:700;">{{ $service['display_name'] }}</div>
                                                                        @if(!empty($service['meta']))
                                                                            <div style="margin-top:4px; color:#7188a6; font-size:12px; line-height:1.55;">{{ $service['meta'] }}</div>
                                                                        @endif
                                                                        @if(!empty($service['photographer_name']))
                                                                            <div style="margin-top:4px; color:#7188a6; font-size:12px; line-height:1.55;">Assigned photographer: {{ $service['photographer_name'] }}</div>
                                                                        @endif
                                                                    </td>
                                                                    <td align="right" style="padding:12px 0; border-bottom:1px solid #edf2f7; white-space:nowrap; color:#071223; font-size:14px; line-height:1.55; font-weight:800;">{{ $service['formatted_total'] }}</td>
                                                                </tr>
                                                            @endforeach
                                                            <tr>
                                                                <td style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#071223; font-size:14px; line-height:1.55; font-weight:700;">Subtotal</td>
                                                                <td align="right" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#071223; font-size:14px; line-height:1.55; font-weight:800;">{{ $shoot->formatted_subtotal }}</td>
                                                            </tr>
                                                            @if(($shoot->tax ?? 0) > 0)
                                                                <tr>
                                                                    <td style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#071223; font-size:14px; line-height:1.55; font-weight:700;">Tax</td>
                                                                    <td align="right" style="padding:12px 0; border-bottom:1px solid #edf2f7; color:#071223; font-size:14px; line-height:1.55; font-weight:800;">{{ $shoot->formatted_tax }}</td>
                                                                </tr>
                                                            @endif
                                                            <tr>
                                                                <td style="padding:12px 0 0 0; color:#071223; font-size:14px; line-height:1.55; font-weight:700;">Total</td>
                                                                <td align="right" style="padding:12px 0 0 0; color:#071223; font-size:14px; line-height:1.55; font-weight:800;">{{ $shoot->formatted_grand_total }}</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            </table>
                                        @endif

                                        @if(!empty($accessDetails))
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; border:1px solid #dbe6f3; border-radius:22px; background-color:#ffffff; margin:0 0 18px 0;">
                                                <tr>
                                                    <td style="padding:20px 22px;">
                                                        <div style="font-size:11px; line-height:1.4; letter-spacing:1.8px; text-transform:uppercase; color:#6c84a2; font-weight:700;">Access</div>
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; margin-top:12px;">
                                                            @foreach($accessDetails as $detail)
                                                                <tr>
                                                                    <td style="padding:10px 0; border-bottom:1px solid #edf2f7; width:34%; color:#6f86a4; font-size:14px; line-height:1.65; font-weight:700;">{{ $detail['label'] }}</td>
                                                                    <td style="padding:10px 0; border-bottom:1px solid #edf2f7; color:#10233b; font-size:14px; line-height:1.65; font-weight:600;">{{ $detail['value'] }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </table>
                                                    </td>
                                                </tr>
                                            </table>
                                        @endif

                                        @if(!empty($notesLines))
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; border:1px solid #dbe7f8; border-radius:18px; background-color:#f8fbff; margin:0 0 18px 0;">
                                                <tr>
                                                    <td style="padding:18px;">
                                                        <div style="font-size:13px; line-height:1.5; letter-spacing:1.3px; text-transform:uppercase; color:#60799a; font-weight:800;">Client-facing notes</div>
                                                        <ul style="margin:12px 0 0 18px; padding:0; color:#35506f;">
                                                            @foreach($notesLines as $line)
                                                                <li style="margin:0 0 8px 0; font-size:14px; line-height:1.7; color:#35506f;">{{ $line }}</li>
                                                            @endforeach
                                                        </ul>
                                                    </td>
                                                </tr>
                                            </table>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:0 32px 32px 32px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; background-color:#102847; border-radius:26px;">
                                            <tr>
                                                <td style="padding:24px 26px;">
                                                    <div style="color:#ffffff; font-size:18px; line-height:1.5; font-weight:800;">Need help with a shoot, invoice, or account question?</div>
                                                    <div style="margin-top:8px; color:#dce8ff; font-size:14px; line-height:1.8;">Our team is here to help keep your marketing workflow moving. Reach us at <a href="mailto:{{ $supportEmail }}" style="color:#dce8ff; text-decoration:underline;">{{ $supportEmail }}</a> or call {{ $supportPhone }}.</div>
                                                    <div style="margin-top:14px; font-size:14px; line-height:1.8;">
                                                        <a href="{{ $dashboardUrl }}" style="color:#ffffff; font-weight:700; text-decoration:none; margin-right:14px;">Dashboard</a>
                                                        <a href="{{ $websiteUrl }}" style="color:#ffffff; font-weight:700; text-decoration:none; margin-right:14px;">Website</a>
                                                        <a href="{{ $reviewUrl }}" style="color:#ffffff; font-weight:700; text-decoration:none;">Leave a Review</a>
                                                    </div>
                                                    <div style="margin-top:16px; color:#9fb4d4; font-size:11px; line-height:1.5; text-transform:uppercase; letter-spacing:1.2px; font-weight:800;">Portal</div>
                                                    <div style="margin-top:4px; color:#ffffff; font-size:14px; line-height:1.6; font-weight:700;">Final images and downloads are available according to the current dashboard status for this shoot.</div>
                                                </td>
                                            </tr>
                                        </table>
                                        <div style="padding:14px 8px 0 8px; text-align:center; color:#7d90ab; font-size:11px; line-height:1.7;">This email was sent by R/E Pro Photos. Please keep this message for your records if it relates to a scheduled shoot, payment, or invoice.</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
