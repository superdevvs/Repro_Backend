@php
    $brandDefaults = app(\App\Services\SystemEmails\EmailBrandingConfig::class)->defaults();
    $brandSource = $branding ?? [];

    if (is_object($brandSource)) {
        $brandSource = get_object_vars($brandSource);
    }

    $brand = array_replace_recursive($brandDefaults, is_array($brandSource) ? $brandSource : []);

    $productName = data_get($brand, 'product_name', 'R/E Pro Photos');
    $supportEmail = data_get($brand, 'support_email', 'contact@reprophotos.com');
    $supportPhone = data_get($brand, 'support_phone', '202-868-1663');
    $dashboardUrl = data_get($brand, 'dashboard_url', 'https://reprodashboard.com');
    $websiteUrl = data_get($brand, 'website_url', 'https://reprophotos.com');
    $emailLogoGreyUrl = data_get($brand, 'email_logo_grey_url', data_get($brand, 'logo_url', 'https://api.reprodashboard.com/images/repro-email-logo-grey.png'));
    $outerBackground = data_get($brand, 'outer_background', '#00141d');
    $shellBackground = data_get($brand, 'shell_background', '#00141d');
    $heroSurface = data_get($brand, 'hero_surface', '#111c2e');
    $contentSurface = data_get($brand, 'content_surface', '#111c2e');
    $sectionSurface = data_get($brand, 'section_surface', '#16233a');
    $footerSurface = data_get($brand, 'footer_surface', '#00141d');
    $metaSurface = data_get($brand, 'meta_surface', '#142237');
    $borderColor = data_get($brand, 'border_color', '#24344d');
    $metaBorderColor = data_get($brand, 'meta_border_color', '#2d4263');
    $headingColor = data_get($brand, 'heading_color', '#e8edf5');
    $bodyColor = data_get($brand, 'body_color', '#a9b8cb');
    $mutedColor = data_get($brand, 'muted_color', '#8298b4');
    $linkColor = data_get($brand, 'link_color', '#7eb3ff');
    $legalCopyColor = data_get($brand, 'legal_copy_color', '#8da2be');
@endphp
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>@yield('title', $productName)</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        * { -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; }
        html, body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        #outlook a { padding: 0; }
        table { border-collapse: collapse !important; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a { text-decoration: none; color: {{ $linkColor }}; }

        .hero-card-bg { background-color: {{ $heroSurface }} !important; border-color: {{ $borderColor }} !important; }
        .content-card-bg { background-color: {{ $contentSurface }} !important; border-color: {{ $borderColor }} !important; }
        .section-card-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; }
        .stat-card-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; }
        .note-card-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; }
        .callout-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; }
        .callout-warning-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; }
        .callout-danger-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; }
        .callout-success-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; }
        .dark-title { color: {{ $headingColor }} !important; }
        .dark-heading { color: {{ $headingColor }} !important; }
        .dark-body { color: {{ $bodyColor }} !important; }
        .dark-muted { color: {{ $mutedColor }} !important; }
        .dark-strong { color: {{ $headingColor }} !important; }
        .detail-border { border-color: {{ $borderColor }} !important; }
        .pill-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; color: {{ $linkColor }} !important; }
        .btn-secondary-bg { background-color: {{ $sectionSurface }} !important; border-color: {{ $borderColor }} !important; color: {{ $headingColor }} !important; }
        .divider-bg { background-color: {{ $borderColor }} !important; }
        .footer-card-bg { background-color: {{ $footerSurface }} !important; }
        .footer-meta-bg { background-color: {{ $metaSurface }} !important; border-color: {{ $metaBorderColor }} !important; }
        .footer-link-dark { color: {{ $headingColor }} !important; }
        .legal-copy-dark { color: {{ $legalCopyColor }} !important; }

        @media only screen and (max-width: 640px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .hero-pad { padding: 24px 20px 22px 20px !important; }
            .content-pad { padding: 24px 20px !important; }
            .section-inner { padding: 18px 16px !important; }
            .footer-inner { padding: 22px 18px !important; }
            .hero-title-td { font-size: 30px !important; line-height: 1.05 !important; letter-spacing: -1.4px !important; }
            .stat-td { display: block !important; width: 100% !important; padding: 0 0 10px 0 !important; }
            .detail-label-td { display: block !important; width: 100% !important; padding-bottom: 2px !important; }
            .detail-value-td { display: block !important; width: 100% !important; padding-top: 0 !important; padding-bottom: 14px !important; }
            .line-th, .line-td { display: block !important; width: 100% !important; text-align: left !important; }
            .amount-td { text-align: left !important; padding-top: 4px !important; }
            .footer-meta-td { display: block !important; width: 100% !important; padding: 0 0 10px 0 !important; }
            .mob-full { width: 100% !important; }
        }

        @media (prefers-color-scheme: dark) {
            body, .body-bg { background-color: {{ $outerBackground }} !important; }
        }

        [data-ogsc] .body-bg,
        [data-ogsc] body { background-color: {{ $outerBackground }} !important; }
    </style>
    @yield('extra-styles')
</head>
<body style="margin:0; padding:0; background-color:{{ $outerBackground }}; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:{{ $bodyColor }}; -webkit-text-size-adjust:100%; word-break:break-word;" class="body-bg">
    <div style="display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden; mso-hide:all; font-size:0; line-height:0;">@yield('preheader', 'Updates from ' . $productName)&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $outerBackground }};" class="body-bg">
        <tr>
            <td align="center" style="padding:30px 12px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="720" style="max-width:720px; width:100%;" class="email-container">

                    @hasSection('hero')
                    <tr>
                        <td class="hero-card-bg hero-pad" style="background-color:{{ $heroSurface }}; border:1px solid {{ $borderColor }}; border-radius:24px 24px 0 0; padding:34px 38px 28px 38px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td style="padding-bottom:24px;">
                                        <img src="{{ $emailLogoGreyUrl }}" alt="{{ $productName }}" width="180" style="display:block; width:180px; max-width:180px; height:auto; border:0;">
                                    </td>
                                </tr>
                            </table>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td>
                                        @yield('hero')
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr><td style="height:2px; font-size:0; line-height:0;" class="body-bg">&nbsp;</td></tr>
                    @endif

                    <tr>
                        <td class="content-card-bg" style="background-color:{{ $contentSurface }}; border:1px solid {{ $borderColor }}; @hasSection('hero') border-radius:0 0 24px 24px; @else border-radius:24px; @endif">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td class="content-pad" style="padding:30px 32px;">
                                        @yield('content')
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td style="padding:0 16px 16px 16px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                            <tr>
                                                <td class="footer-card-bg footer-inner" style="background-color:{{ $footerSurface }}; border-radius:20px; padding:24px 26px;">
                                                    <p style="margin:0 0 8px; font-size:18px; line-height:1.5; color:{{ $headingColor }}; font-weight:800;">Need help with a shoot, invoice, or account question?</p>
                                                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.8; color:{{ $bodyColor }};">
                                                        Our team is here to help keep your marketing workflow moving.
                                                        Reach us at <a href="mailto:{{ $supportEmail }}" class="footer-link-dark" style="color:{{ $headingColor }}; text-decoration:underline;">{{ $supportEmail }}</a> or call {{ $supportPhone }}.
                                                    </p>
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                                                        <tr>
                                                            <td style="padding-right:14px;"><a href="{{ $dashboardUrl }}" class="footer-link-dark" style="color:{{ $headingColor }}; font-weight:700; font-size:14px; text-decoration:none;">Dashboard</a></td>
                                                            <td style="padding-right:14px;"><a href="{{ $websiteUrl }}" class="footer-link-dark" style="color:{{ $headingColor }}; font-weight:700; font-size:14px; text-decoration:none;">Website</a></td>
                                                            <td><a href="https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews" class="footer-link-dark" style="color:{{ $headingColor }}; font-weight:700; font-size:14px; text-decoration:none;">Leave a Review</a></td>
                                                        </tr>
                                                    </table>

                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;">
                                                        <tr>
                                                            <td class="footer-meta-td" width="50%" style="padding-right:6px; vertical-align:top;">
                                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td class="footer-meta-bg" style="background-color:{{ $metaSurface }}; border:1px solid {{ $metaBorderColor }}; border-radius:14px; padding:14px 16px;">
                                                                            <span style="display:block; margin-bottom:4px; color:{{ $mutedColor }}; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; font-weight:800;">Support</span>
                                                                            <span style="color:{{ $headingColor }}; font-size:13px; line-height:1.6; font-weight:700;">{{ $supportEmail }}<br>{{ $supportPhone }}</span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                            <td class="footer-meta-td" width="50%" style="padding-left:6px; vertical-align:top;">
                                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td class="footer-meta-bg" style="background-color:{{ $metaSurface }}; border:1px solid {{ $metaBorderColor }}; border-radius:14px; padding:14px 16px;">
                                                                            <span style="display:block; margin-bottom:4px; color:{{ $mutedColor }}; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; font-weight:800;">Portal</span>
                                                                            <span style="color:{{ $headingColor }}; font-size:13px; line-height:1.6; font-weight:700;">Track your shoots, invoices, and delivery updates in one place.</span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </table>

                                                    @hasSection('footer_note')
                                                    <p style="margin:16px 0 0; color:{{ $mutedColor }}; font-size:12px; line-height:1.7;">@yield('footer_note')</p>
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="legal-copy-dark" style="padding:14px 8px 0; text-align:center; color:{{ $legalCopyColor }}; font-size:11px; line-height:1.7;">
                            This email was sent by {{ $productName }}. Please keep this message for your records if it relates to a scheduled shoot, payment, or invoice.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
