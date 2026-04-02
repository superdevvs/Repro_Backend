<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>@yield('title', 'R/E Pro Photos')</title>
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
        a { text-decoration: none; }

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
            body, .body-bg { background-color: #0b1220 !important; }
            .hero-card-bg { background-color: #111c2e !important; border-color: #24344d !important; }
            .content-card-bg { background-color: #111c2e !important; border-color: #24344d !important; }
            .section-card-bg { background-color: #16233a !important; border-color: #24344d !important; }
            .stat-card-bg { background-color: #16233a !important; border-color: #24344d !important; }
            .note-card-bg { background-color: #16233a !important; border-color: #24344d !important; }
            .callout-bg { background-color: #16233a !important; border-color: #24344d !important; }
            .callout-warning-bg { background-color: #1e1a10 !important; border-color: #4a3a14 !important; }
            .callout-danger-bg { background-color: #1e1014 !important; border-color: #4a1a24 !important; }
            .callout-success-bg { background-color: #101e2e !important; border-color: #1a3a5a !important; }
            .dark-title { color: #f0f4fa !important; }
            .dark-heading { color: #e8edf5 !important; }
            .dark-body { color: #a9b8cb !important; }
            .dark-muted { color: #8298b4 !important; }
            .dark-strong { color: #f0f4fa !important; }
            .detail-border { border-color: #24344d !important; }
            .pill-bg { background-color: #172843 !important; border-color: #2b4b78 !important; color: #9fc2ff !important; }
            .btn-secondary-bg { background-color: #142237 !important; border-color: #314662 !important; color: #dfe8f5 !important; }
            .divider-bg { background-color: #24344d !important; }
            .footer-card-bg { background-color: #0a1525 !important; }
            .footer-meta-bg { background-color: #142237 !important; border-color: #2d4263 !important; }
            .footer-link-dark { color: #ffffff !important; }
            .legal-copy-dark { color: #8da2be !important; }
        }

        [data-ogsc] .body-bg,
        [data-ogsc] body { background-color: #0b1220 !important; }
        [data-ogsc] .hero-card-bg { background-color: #111c2e !important; border-color: #24344d !important; }
        [data-ogsc] .content-card-bg { background-color: #111c2e !important; border-color: #24344d !important; }
        [data-ogsc] .section-card-bg { background-color: #16233a !important; border-color: #24344d !important; }
        [data-ogsc] .stat-card-bg { background-color: #16233a !important; border-color: #24344d !important; }
        [data-ogsc] .note-card-bg { background-color: #16233a !important; border-color: #24344d !important; }
        [data-ogsc] .callout-bg { background-color: #16233a !important; border-color: #24344d !important; }
        [data-ogsc] .callout-warning-bg { background-color: #1e1a10 !important; border-color: #4a3a14 !important; }
        [data-ogsc] .callout-danger-bg { background-color: #1e1014 !important; border-color: #4a1a24 !important; }
        [data-ogsc] .callout-success-bg { background-color: #101e2e !important; border-color: #1a3a5a !important; }
        [data-ogsc] .dark-title { color: #f0f4fa !important; }
        [data-ogsc] .dark-heading { color: #e8edf5 !important; }
        [data-ogsc] .dark-body { color: #a9b8cb !important; }
        [data-ogsc] .dark-muted { color: #8298b4 !important; }
        [data-ogsc] .dark-strong { color: #f0f4fa !important; }
        [data-ogsc] .detail-border { border-color: #24344d !important; }
        [data-ogsc] .pill-bg { background-color: #172843 !important; border-color: #2b4b78 !important; color: #9fc2ff !important; }
        [data-ogsc] .btn-secondary-bg { background-color: #142237 !important; border-color: #314662 !important; color: #dfe8f5 !important; }
        [data-ogsc] .divider-bg { background-color: #24344d !important; }
        [data-ogsc] .footer-card-bg { background-color: #0a1525 !important; }
        [data-ogsc] .footer-meta-bg { background-color: #142237 !important; border-color: #2d4263 !important; }
        [data-ogsc] .footer-link-dark { color: #ffffff !important; }
        [data-ogsc] .legal-copy-dark { color: #8da2be !important; }
    </style>
    @yield('extra-styles')
</head>
<body style="margin:0; padding:0; background-color:#eef3f8; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:#10233b; -webkit-text-size-adjust:100%; word-break:break-word;" class="body-bg">
    {{-- Preheader text --}}
    <div style="display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden; mso-hide:all; font-size:0; line-height:0;">@yield('preheader', 'Updates from R/E Pro Photos')&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

    {{-- Outer wrapper table --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef3f8;" class="body-bg">
        <tr>
            <td align="center" style="padding:30px 12px;">

                {{-- Main container --}}
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="720" style="max-width:720px; width:100%;" class="email-container">

                    @hasSection('hero')
                    {{-- HERO CARD --}}
                    <tr>
                        <td class="hero-card-bg hero-pad" style="background-color:#ffffff; border:1px solid #dee6f1; border-radius:24px 24px 0 0; padding:34px 38px 28px 38px;">
                            {{-- Logo --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td style="padding-bottom:24px;">
                                        <img src="https://api.reprodashboard.com/images/Repro%20HQ%20dark.png" alt="" role="presentation" width="140" style="display:block; width:140px; max-width:140px; height:auto; border:0;">
                                    </td>
                                </tr>
                            </table>
                            {{-- Hero content --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td>
                                        @yield('hero')
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    {{-- Spacer between hero and content --}}
                    <tr><td style="height:2px; font-size:0; line-height:0;" class="body-bg">&nbsp;</td></tr>
                    @endif

                    {{-- CONTENT CARD --}}
                    <tr>
                        <td class="content-card-bg" style="background-color:#ffffff; border:1px solid #dee6f1; @hasSection('hero') border-radius:0 0 24px 24px; @else border-radius:24px; @endif">
                            {{-- Content area --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td class="content-pad" style="padding:30px 32px;">
                                        @yield('content')
                                    </td>
                                </tr>
                            </table>

                            {{-- FOOTER CARD --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td style="padding:0 16px 16px 16px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                            <tr>
                                                <td class="footer-card-bg footer-inner" style="background-color:#0b1b30; border-radius:20px; padding:24px 26px;">
                                                    <p style="margin:0 0 8px; font-size:18px; line-height:1.5; color:#ffffff; font-weight:800;">Need help with a shoot, invoice, or account question?</p>
                                                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.8; color:#c8d8f0;">
                                                        Our team is here to help keep your marketing workflow moving.
                                                        Reach us at <a href="mailto:contact@reprophotos.com" class="footer-link-dark" style="color:#ffffff; text-decoration:underline;">contact@reprophotos.com</a> or call 202-868-1663.
                                                    </p>
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                                                        <tr>
                                                            <td style="padding-right:14px;"><a href="https://reprodashboard.com" class="footer-link-dark" style="color:#ffffff; font-weight:700; font-size:14px; text-decoration:none;">Dashboard</a></td>
                                                            <td style="padding-right:14px;"><a href="https://reprophotos.com" class="footer-link-dark" style="color:#ffffff; font-weight:700; font-size:14px; text-decoration:none;">Website</a></td>
                                                            <td><a href="https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews" class="footer-link-dark" style="color:#ffffff; font-weight:700; font-size:14px; text-decoration:none;">Leave a Review</a></td>
                                                        </tr>
                                                    </table>

                                                    {{-- Footer meta cards --}}
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;">
                                                        <tr>
                                                            <td class="footer-meta-td" width="50%" style="padding-right:6px; vertical-align:top;">
                                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td class="footer-meta-bg" style="background-color:#142237; border:1px solid #2d4263; border-radius:14px; padding:14px 16px;">
                                                                            <span style="display:block; margin-bottom:4px; color:#a9bfdc; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; font-weight:800;">Support</span>
                                                                            <span style="color:#ffffff; font-size:13px; line-height:1.6; font-weight:700;">contact@reprophotos.com<br>202-868-1663</span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                            <td class="footer-meta-td" width="50%" style="padding-left:6px; vertical-align:top;">
                                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td class="footer-meta-bg" style="background-color:#142237; border:1px solid #2d4263; border-radius:14px; padding:14px 16px;">
                                                                            <span style="display:block; margin-bottom:4px; color:#a9bfdc; font-size:11px; line-height:1.4; letter-spacing:1.2px; text-transform:uppercase; font-weight:800;">Portal</span>
                                                                            <span style="color:#ffffff; font-size:13px; line-height:1.6; font-weight:700;">Track your shoots, invoices, and delivery updates in one place.</span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </table>

                                                    @hasSection('footer_note')
                                                    <p style="margin:16px 0 0; color:#8298b4; font-size:12px; line-height:1.7;">@yield('footer_note')</p>
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Bottom note --}}
                    <tr>
                        <td class="legal-copy-dark" style="padding:14px 8px 0; text-align:center; color:#7d90ab; font-size:11px; line-height:1.7;">
                            This email was sent by R/E Pro Photos. Please keep this message for your records if it relates to a scheduled shoot, payment, or invoice.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
