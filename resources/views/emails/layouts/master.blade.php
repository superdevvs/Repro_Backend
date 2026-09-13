@php
    $brandSource = is_object($branding ?? null) ? get_object_vars($branding) : ($branding ?? []);
    $brand = app(\App\Services\SystemEmails\EmailBrandingConfig::class)->defaults(is_array($brandSource) ? $brandSource : []);
    $theme = in_array($emailPreviewTheme ?? null, ['light', 'dark'], true) ? $emailPreviewTheme : null;
    $colors = \App\Services\SystemEmails\EmailPresentation::palette($theme);
    $artwork = $emailAtelier ?? ['kind' => 'none', 'src' => null, 'photos' => []];
    $productName = $brand['product_name'];
    $supportEmail = $brand['support_email'];
    $supportPhone = \App\Support\SupportContact::PHONE_DISPLAY;
    $supportPhoneHref = \App\Support\SupportContact::PHONE_E164;
    $websiteUrl = $brand['website_url'];
    $emailLogoUrl = $brand['email_logo_grey_url'];
    $heroHtml = \App\Services\SystemEmails\EmailPresentation::format($__env->yieldContent('hero'), $theme, true);
    $contentHtml = \App\Services\SystemEmails\EmailPresentation::format($__env->yieldContent('content'), $theme, false, !str_starts_with($artwork['design'] ?? '', 'account_created'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="{{ $theme ?? 'light dark' }}">
    <meta name="supported-color-schemes" content="{{ $theme ?? 'light dark' }}">
    <meta name="format-detection" content="telephone=no,date=no,address=no,email=no">
    <title>@yield('title', $productName)</title>
    <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
    @include('emails.partials.atelier-styles')
</head>
<body class="body-bg" data-email-design="atelier-v6" style="margin:0;padding:0;width:100%;background-color:{{ $colors['canvas'] }};font-family:Inter,Arial,Helvetica,sans-serif;color:{{ $colors['body'] }};">
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">@yield('preheader')</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="body-bg" style="background-color:{{ $colors['canvas'] }};">
        <tr><td align="center" class="email-outer" style="padding:40px;">
            <!--[if mso]><table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" class="email-container content-card-bg" style="width:100%;max-width:640px;table-layout:fixed;border-collapse:separate;border-spacing:0;background-color:{{ $colors['paper'] }};border:1px solid {{ $colors['border'] }};border-radius:20px;overflow:hidden;">
                <tr><td class="email-inset" style="padding:32px 48px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                        <td style="vertical-align:middle;">
                            <img class="email-logo-universal" src="{{ $emailLogoUrl }}" alt="{{ $productName }}" width="126" height="38" style="display:block;width:126px;height:38px;border:0;">
                        </td>
                        <td align="right" class="brand-tagline dark-muted" style="vertical-align:middle;color:{{ $colors['muted'] }};font-size:11px;line-height:16px;font-weight:600;letter-spacing:1.4px;">PROPERTY<br>MEDIA, REFINED.</td>
                    </tr></table>
                </td></tr>
                @if(($artwork['kind'] ?? '') === 'illustration' && !empty($artwork['src']))
                    <tr><td style="padding:0 0 32px;"><img class="email-illustration" src="{{ $artwork['src'] }}" alt="" role="presentation" width="640" style="display:block;width:100%;max-width:640px;height:auto;border:0;" data-artwork="{{ $artwork['asset'] }}"></td></tr>
                @elseif(($artwork['kind'] ?? '') === 'shoot_photos' && !empty($artwork['photos']))
                    <tr><td style="padding:0 0 32px;"><img src="{{ $artwork['photos'][0]['src'] }}" alt="{{ $artwork['photos'][0]['alt'] }}" width="640" style="display:block;width:100%;max-width:640px;height:auto;border:0;" data-shoot-photo="{{ $artwork['photos'][0]['file_id'] }}"></td></tr>
                @endif
                @if(trim($heroHtml) !== '')
                    <tr><td class="email-inset email-intro" style="padding:0 48px 32px;">{!! $heroHtml !!}</td></tr>
                @endif
                <tr><td class="email-inset content-pad body-inner" data-email-content="true" style="padding:0 48px 32px;color:{{ $colors['body'] }};font-size:14px;line-height:24px;overflow-wrap:anywhere;word-wrap:break-word;">{!! $contentHtml !!}</td></tr>
                @include('emails.partials.atelier-footer')
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td></tr>
    </table>
</body>
</html>
