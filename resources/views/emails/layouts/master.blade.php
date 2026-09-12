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
    $assetBase = rtrim((string) config('app.url'), '/').'/images/email-atelier/v6/';
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
                            @if($theme === 'dark')
                                <img src="{{ $assetBase }}logo-dark.png" alt="{{ $productName }}" width="126" height="38" style="display:block;width:126px;height:38px;border:0;">
                            @else
                                <img class="logo-light" src="{{ $assetBase }}logo-light.png" alt="{{ $productName }}" width="126" height="38" style="display:block;width:126px;height:38px;border:0;">
                                @if($theme === null)
                                    <!--[if !mso]><!--><img class="logo-dark" src="{{ $assetBase }}logo-dark.png" alt="{{ $productName }}" width="126" height="38" style="display:none;width:126px;height:38px;border:0;max-height:0;overflow:hidden;mso-hide:all;"><!--<![endif]-->
                                @endif
                            @endif
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
                <tr><td class="email-inset footer-inner" style="padding:32px 48px;border-top:1px solid {{ $colors['border'] }};">
                    <p class="dark-heading" style="margin:0 0 8px;color:{{ $colors['ink'] }};font-size:11px;line-height:16px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;">R/E PRO PHOTOS</p>
                    <p class="dark-muted footer-contact" style="margin:0;color:{{ $colors['muted'] }};font-size:12px;line-height:19px;">Need a hand? <a class="footer-contact-link" href="mailto:{{ $supportEmail }}" style="color:{{ $colors['accent'] }};text-decoration:underline;">{{ $supportEmail }}</a><br><a class="footer-contact-link" href="tel:{{ $supportPhoneHref }}" style="color:{{ $colors['muted'] }};text-decoration:none;">{{ $supportPhone }}</a></p>
                    <p class="dark-muted" style="margin:16px 0 0;color:{{ $colors['muted'] }};font-size:12px;line-height:19px;">{{ $artwork['footer_reason'] ?? 'You received this email about your R/E Pro Photos account.' }}</p>
                    @hasSection('footer_note')
                        <p class="dark-muted" style="margin:12px 0 0;color:{{ $colors['muted'] }};font-size:12px;line-height:19px;">@yield('footer_note')</p>
                    @endif
                    @if(!empty($brand['company_address']))
                        <p class="dark-muted" style="margin:12px 0 0;color:{{ $colors['muted'] }};font-size:12px;line-height:19px;">{{ $brand['company_address'] }}</p>
                    @endif
                    <p style="margin:16px 0 0;font-size:12px;line-height:19px;"><a class="atelier-link" href="{{ $websiteUrl }}" style="color:{{ $colors['accent'] }};text-decoration:none;">reprophotos.com</a></p>
                </td></tr>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->
        </td></tr>
    </table>
</body>
</html>
