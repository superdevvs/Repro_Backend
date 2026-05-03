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
    $reviewUrl = data_get($brand, 'review_url', 'https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews');
    $emailLogoGreyUrl = data_get($brand, 'email_logo_grey_url', data_get($brand, 'logo_url', 'https://api.reprodashboard.com/images/repro-email-logo-grey.png'));
    $emailCanvasBackgroundLight = data_get($brand, 'email_canvas_background_light', data_get($brand, 'email_outer_background', '#ffffff'));
    $emailCanvasBackgroundDark = data_get($brand, 'email_canvas_background_dark', data_get($brand, 'email_outer_background_dark', data_get($brand, 'outer_background', '#00141d')));
    $heroSurfaceLight = data_get($brand, 'card_surface_light', data_get($brand, 'hero_surface', '#ffffff'));
    $heroSurfaceDark = data_get($brand, 'card_surface_dark', '#111c2e');
    $heroSurfaceDarkGradient = data_get($brand, 'card_surface_dark_gradient', "linear-gradient(180deg, {$heroSurfaceDark} 0%, {$heroSurfaceDark} 100%)");
    $contentSurfaceLight = data_get($brand, 'card_surface_light', data_get($brand, 'content_surface', '#ffffff'));
    $contentSurfaceDark = data_get($brand, 'card_surface_dark', '#111c2e');
    $contentSurfaceDarkGradient = data_get($brand, 'card_surface_dark_gradient', "linear-gradient(180deg, {$contentSurfaceDark} 0%, {$contentSurfaceDark} 100%)");
    $sectionSurfaceLight = data_get($brand, 'section_surface_light', data_get($brand, 'section_surface', '#f7fbff'));
    $sectionSurfaceDark = data_get($brand, 'section_surface_dark', '#16233a');
    $sectionSurfaceDarkGradient = data_get($brand, 'section_surface_dark_gradient', "linear-gradient(180deg, {$sectionSurfaceDark} 0%, {$sectionSurfaceDark} 100%)");
    $statSurfaceLight = data_get($brand, 'stat_surface_light', $sectionSurfaceLight);
    $statSurfaceDark = data_get($brand, 'stat_surface_dark', $sectionSurfaceDark);
    $statSurfaceDarkGradient = data_get($brand, 'stat_surface_dark_gradient', "linear-gradient(180deg, {$statSurfaceDark} 0%, {$statSurfaceDark} 100%)");
    $noteSurfaceLight = data_get($brand, 'note_surface_light', $sectionSurfaceLight);
    $noteSurfaceDark = data_get($brand, 'note_surface_dark', $sectionSurfaceDark);
    $noteSurfaceDarkGradient = data_get($brand, 'note_surface_dark_gradient', "linear-gradient(180deg, {$noteSurfaceDark} 0%, {$noteSurfaceDark} 100%)");
    $calloutSurfaceLight = data_get($brand, 'callout_surface_light', $sectionSurfaceLight);
    $calloutSurfaceDark = data_get($brand, 'callout_surface_dark', $sectionSurfaceDark);
    $calloutSurfaceDarkGradient = data_get($brand, 'callout_surface_dark_gradient', "linear-gradient(180deg, {$calloutSurfaceDark} 0%, {$calloutSurfaceDark} 100%)");
    $calloutSuccessSurfaceLight = data_get($brand, 'callout_success_surface_light', '#eff6ff');
    $calloutSuccessSurfaceDark = data_get($brand, 'callout_success_surface_dark', '#18304f');
    $calloutSuccessSurfaceDarkGradient = data_get($brand, 'callout_success_surface_dark_gradient', "linear-gradient(180deg, {$calloutSuccessSurfaceDark} 0%, {$calloutSuccessSurfaceDark} 100%)");
    $calloutWarningSurfaceLight = data_get($brand, 'callout_warning_surface_light', '#fff3e3');
    $calloutWarningSurfaceDark = data_get($brand, 'callout_warning_surface_dark', '#382714');
    $calloutWarningSurfaceDarkGradient = data_get($brand, 'callout_warning_surface_dark_gradient', "linear-gradient(180deg, {$calloutWarningSurfaceDark} 0%, {$calloutWarningSurfaceDark} 100%)");
    $calloutDangerSurfaceLight = data_get($brand, 'callout_danger_surface_light', '#fff0f1');
    $calloutDangerSurfaceDark = data_get($brand, 'callout_danger_surface_dark', '#351b22');
    $calloutDangerSurfaceDarkGradient = data_get($brand, 'callout_danger_surface_dark_gradient', "linear-gradient(180deg, {$calloutDangerSurfaceDark} 0%, {$calloutDangerSurfaceDark} 100%)");
    $footerSurfaceLight = data_get($brand, 'footer_surface_light', data_get($brand, 'footer_surface', '#f7fbff'));
    $footerSurfaceDark = data_get($brand, 'footer_surface_dark', '#00141d');
    $footerSurfaceDarkGradient = data_get($brand, 'footer_surface_dark_gradient', "linear-gradient(180deg, {$footerSurfaceDark} 0%, {$footerSurfaceDark} 100%)");
    $metaSurfaceLight = data_get($brand, 'meta_surface_light', data_get($brand, 'meta_surface', '#edf3fb'));
    $metaSurfaceDark = data_get($brand, 'meta_surface_dark', '#142237');
    $metaSurfaceDarkGradient = data_get($brand, 'meta_surface_dark_gradient', "linear-gradient(180deg, {$metaSurfaceDark} 0%, {$metaSurfaceDark} 100%)");
    $borderColorLight = data_get($brand, 'border_color_light', data_get($brand, 'border_color', 'transparent'));
    $borderColorDark = data_get($brand, 'border_color_dark', 'transparent');
    $metaBorderColorLight = data_get($brand, 'meta_border_color_light', data_get($brand, 'meta_border_color', 'transparent'));
    $metaBorderColorDark = data_get($brand, 'meta_border_color_dark', 'transparent');
    $headingColorLight = data_get($brand, 'heading_color_light', data_get($brand, 'heading_color', '#071223'));
    $headingColorDark = data_get($brand, 'heading_color_dark', '#e8edf5');
    $bodyColorLight = data_get($brand, 'body_color_light', data_get($brand, 'body_color', '#47627f'));
    $bodyColorDark = data_get($brand, 'body_color_dark', '#a9b8cb');
    $mutedColorLight = data_get($brand, 'muted_color_light', data_get($brand, 'muted_color', '#6c84a2'));
    $mutedColorDark = data_get($brand, 'muted_color_dark', '#8298b4');
    $linkColorLight = data_get($brand, 'link_color_light', data_get($brand, 'link_color', '#1463ff'));
    $linkColorDark = data_get($brand, 'link_color_dark', '#7eb3ff');
    $buttonSecondarySurfaceLight = data_get($brand, 'button_secondary_surface_light', '#edf4ff');
    $buttonSecondarySurfaceDark = data_get($brand, 'button_secondary_surface_dark', '#16233a');
    $buttonSecondarySurfaceDarkGradient = data_get($brand, 'button_secondary_surface_dark_gradient', "linear-gradient(180deg, {$buttonSecondarySurfaceDark} 0%, {$buttonSecondarySurfaceDark} 100%)");
    $buttonSecondaryTextLight = data_get($brand, 'button_secondary_text_light', '#173963');
    $buttonSecondaryTextDark = data_get($brand, 'button_secondary_text_dark', '#e8edf5');
    $legalCopyColorLight = data_get($brand, 'legal_copy_color_light', data_get($brand, 'legal_copy_color', '#5f6b7a'));
    $legalCopyColorDark = data_get($brand, 'legal_copy_color_dark', '#8da2be');
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
        a { text-decoration: none; color: {{ $linkColorLight }}; }
        .body-bg { background-color: {{ $emailCanvasBackgroundLight }} !important; }

        .hero-card-bg { background-color: {{ $heroSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .content-card-bg { background-color: {{ $contentSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .section-card-bg { background-color: {{ $sectionSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .stat-card-bg { background-color: {{ $statSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .note-card-bg { background-color: {{ $noteSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .callout-bg { background-color: {{ $calloutSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .callout-warning-bg { background-color: {{ $calloutWarningSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .callout-danger-bg { background-color: {{ $calloutDangerSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .callout-success-bg { background-color: {{ $calloutSuccessSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; }
        .dark-title { color: {{ $headingColorLight }} !important; }
        .dark-heading { color: {{ $headingColorLight }} !important; }
        .dark-body { color: {{ $bodyColorLight }} !important; }
        .dark-muted { color: {{ $mutedColorLight }} !important; }
        .dark-strong { color: {{ $headingColorLight }} !important; }
        .detail-border { border-color: transparent !important; }
        .pill-bg { border: 0 !important; border-color: transparent !important; }
        .btn-secondary-bg { background-color: {{ $buttonSecondarySurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $borderColorLight }} !important; color: {{ $buttonSecondaryTextLight }} !important; }
        .divider-bg { background-color: transparent !important; }
        .footer-card-bg { background-color: {{ $footerSurfaceLight }} !important; background-image: none !important; }
        .footer-meta-bg { background-color: {{ $metaSurfaceLight }} !important; background-image: none !important; border: 0 !important; border-color: {{ $metaBorderColorLight }} !important; }
        .footer-link-dark { color: {{ $headingColorLight }} !important; }
        .legal-copy-dark { color: {{ $legalCopyColorLight }} !important; }

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
            body, .body-bg { background-color: {{ $emailCanvasBackgroundDark }} !important; }
            a { color: {{ $linkColorDark }} !important; }
            .hero-card-bg { background: {{ $heroSurfaceDarkGradient }} !important; background-color: {{ $heroSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .content-card-bg { background: {{ $contentSurfaceDarkGradient }} !important; background-color: {{ $contentSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .section-card-bg { background: {{ $sectionSurfaceDarkGradient }} !important; background-color: {{ $sectionSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .stat-card-bg { background: {{ $statSurfaceDarkGradient }} !important; background-color: {{ $statSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .note-card-bg { background: {{ $noteSurfaceDarkGradient }} !important; background-color: {{ $noteSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .callout-bg { background: {{ $calloutSurfaceDarkGradient }} !important; background-color: {{ $calloutSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .callout-warning-bg { background: {{ $calloutWarningSurfaceDarkGradient }} !important; background-color: {{ $calloutWarningSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .callout-danger-bg { background: {{ $calloutDangerSurfaceDarkGradient }} !important; background-color: {{ $calloutDangerSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .callout-success-bg { background: {{ $calloutSuccessSurfaceDarkGradient }} !important; background-color: {{ $calloutSuccessSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
            .dark-title,
            .dark-heading,
            .dark-strong { color: {{ $headingColorDark }} !important; }
            .dark-body { color: {{ $bodyColorDark }} !important; }
            .dark-muted { color: {{ $mutedColorDark }} !important; }
            .btn-secondary-bg { background: {{ $buttonSecondarySurfaceDarkGradient }} !important; background-color: {{ $buttonSecondarySurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; color: {{ $buttonSecondaryTextDark }} !important; }
            .footer-card-bg { background: {{ $footerSurfaceDarkGradient }} !important; background-color: {{ $footerSurfaceDark }} !important; }
            .footer-meta-bg { background: {{ $metaSurfaceDarkGradient }} !important; background-color: {{ $metaSurfaceDark }} !important; border-color: {{ $metaBorderColorDark }} !important; }
            .footer-link-dark { color: {{ $headingColorDark }} !important; }
            .legal-copy-dark { color: {{ $legalCopyColorDark }} !important; }
        }

        [data-ogsc] body,
        [data-ogsc] .body-bg { background-color: {{ $emailCanvasBackgroundDark }} !important; }
        [data-ogsc] a { color: {{ $linkColorDark }} !important; }
        [data-ogsc] .hero-card-bg { background: {{ $heroSurfaceDarkGradient }} !important; background-color: {{ $heroSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .content-card-bg { background: {{ $contentSurfaceDarkGradient }} !important; background-color: {{ $contentSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .section-card-bg { background: {{ $sectionSurfaceDarkGradient }} !important; background-color: {{ $sectionSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .stat-card-bg { background: {{ $statSurfaceDarkGradient }} !important; background-color: {{ $statSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .note-card-bg { background: {{ $noteSurfaceDarkGradient }} !important; background-color: {{ $noteSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .callout-bg { background: {{ $calloutSurfaceDarkGradient }} !important; background-color: {{ $calloutSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .callout-warning-bg { background: {{ $calloutWarningSurfaceDarkGradient }} !important; background-color: {{ $calloutWarningSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .callout-danger-bg { background: {{ $calloutDangerSurfaceDarkGradient }} !important; background-color: {{ $calloutDangerSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .callout-success-bg { background: {{ $calloutSuccessSurfaceDarkGradient }} !important; background-color: {{ $calloutSuccessSurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; }
        [data-ogsc] .dark-title,
        [data-ogsc] .dark-heading,
        [data-ogsc] .dark-strong { color: {{ $headingColorDark }} !important; }
        [data-ogsc] .dark-body { color: {{ $bodyColorDark }} !important; }
        [data-ogsc] .dark-muted { color: {{ $mutedColorDark }} !important; }
        [data-ogsc] .btn-secondary-bg { background: {{ $buttonSecondarySurfaceDarkGradient }} !important; background-color: {{ $buttonSecondarySurfaceDark }} !important; border-color: {{ $borderColorDark }} !important; color: {{ $buttonSecondaryTextDark }} !important; }
        [data-ogsc] .footer-card-bg { background: {{ $footerSurfaceDarkGradient }} !important; background-color: {{ $footerSurfaceDark }} !important; }
        [data-ogsc] .footer-meta-bg { background: {{ $metaSurfaceDarkGradient }} !important; background-color: {{ $metaSurfaceDark }} !important; border-color: {{ $metaBorderColorDark }} !important; }
        [data-ogsc] .footer-link-dark { color: {{ $headingColorDark }} !important; }
        [data-ogsc] .legal-copy-dark { color: {{ $legalCopyColorDark }} !important; }
    </style>
    @yield('extra-styles')
</head>
<body bgcolor="{{ $emailCanvasBackgroundLight }}" style="margin:0; padding:0; background-color:{{ $emailCanvasBackgroundLight }}; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:{{ $bodyColorLight }}; -webkit-text-size-adjust:100%; word-break:break-word;" class="body-bg">
    <div style="display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden; mso-hide:all; font-size:0; line-height:0;">@yield('preheader', 'Updates from ' . $productName)&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $emailCanvasBackgroundLight }}" style="background-color:{{ $emailCanvasBackgroundLight }};" class="body-bg">
        <tr>
            <td align="center" bgcolor="{{ $emailCanvasBackgroundLight }}" style="padding:30px 12px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="720" style="max-width:720px; width:100%;" class="email-container">

                    @hasSection('hero')
                    <tr>
                        <td class="hero-card-bg hero-pad" style="background-color:{{ $heroSurfaceLight }}; border:0; border-radius:24px 24px 0 0; padding:34px 38px 28px 38px;">
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
                    @endif

                    <tr>
                        <td class="content-card-bg" style="background-color:{{ $contentSurfaceLight }}; border:0; @hasSection('hero') border-radius:0 0 24px 24px; @else border-radius:24px; @endif">
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
                                                <td class="footer-card-bg footer-inner" style="background-color:{{ $footerSurfaceLight }}; border-radius:20px; padding:24px 26px;">
                                                    <p style="margin:0 0 8px; font-size:18px; line-height:1.5; color:{{ $headingColorLight }}; font-weight:800;">Need help with a shoot, invoice, or account question?</p>
                                                    <p class="dark-body" style="margin:0; font-size:14px; line-height:1.8; color:{{ $bodyColorLight }};">
                                                        Our team is here to help keep your marketing workflow moving.
                                                        Reach us at <a href="mailto:{{ $supportEmail }}" class="footer-link-dark" style="color:{{ $headingColorLight }}; text-decoration:underline;">{{ $supportEmail }}</a> or call {{ $supportPhone }}.
                                                    </p>
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:18px;">
                                                        <tr>
                                                            <td class="footer-meta-td" width="33.33%" style="padding-right:6px; vertical-align:top;">
                                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td class="footer-meta-bg" style="background-color:{{ $metaSurfaceLight }}; border:0; border-radius:14px; padding:14px 16px;">
                                                                            <a href="{{ $dashboardUrl }}" class="footer-link-dark" style="display:block; margin-bottom:6px; color:{{ $headingColorLight }}; font-size:14px; line-height:1.4; font-weight:800; text-decoration:none;">Dashboard</a>
                                                                            <span class="dark-body" style="display:block; color:{{ $bodyColorLight }}; font-size:12px; line-height:1.6; font-weight:600;">Track your shoots, invoices, and delivery updates in one place.</span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                            <td class="footer-meta-td" width="33.33%" style="padding:0 6px; vertical-align:top;">
                                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td class="footer-meta-bg" style="background-color:{{ $metaSurfaceLight }}; border:0; border-radius:14px; padding:14px 16px;">
                                                                            <a href="{{ $websiteUrl }}" class="footer-link-dark" style="display:block; margin-bottom:6px; color:{{ $headingColorLight }}; font-size:14px; line-height:1.4; font-weight:800; text-decoration:none;">Website</a>
                                                                            <span class="dark-body" style="display:block; color:{{ $bodyColorLight }}; font-size:12px; line-height:1.6; font-weight:600;">View products and services to order.</span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                            <td class="footer-meta-td" width="33.33%" style="padding-left:6px; vertical-align:top;">
                                                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                                                    <tr>
                                                                        <td class="footer-meta-bg" style="background-color:{{ $metaSurfaceLight }}; border:0; border-radius:14px; padding:14px 16px;">
                                                                            <a href="{{ $reviewUrl }}" class="footer-link-dark" style="display:block; margin-bottom:6px; color:{{ $headingColorLight }}; font-size:14px; line-height:1.4; font-weight:800; text-decoration:none;">Leave a Review</a>
                                                                            <span class="dark-body" style="display:block; color:{{ $bodyColorLight }}; font-size:12px; line-height:1.6; font-weight:600;">We are looking for 5 stars and nothing less.</span>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                    </table>

                                                    @hasSection('footer_note')
                                                    <p style="margin:16px 0 0; color:{{ $mutedColorLight }}; font-size:12px; line-height:1.7;">@yield('footer_note')</p>
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
                        <td class="legal-copy-dark" style="padding:14px 8px 0; text-align:center; color:{{ $legalCopyColorLight }}; font-size:11px; line-height:1.7;">
                            This email was sent by {{ $productName }}. Please keep this message for your records if it relates to a scheduled shoot, payment, or invoice.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
