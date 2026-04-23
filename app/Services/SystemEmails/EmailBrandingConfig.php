<?php

namespace App\Services\SystemEmails;

class EmailBrandingConfig
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function defaults(array $overrides = []): array
    {
        $dashboardUrl = rtrim((string) config('app.frontend_url', 'https://reprodashboard.com'), '/');
        $appUrl = rtrim((string) config('app.url', 'https://api.reprodashboard.com'), '/');
        $emailLogoGreyUrl = $appUrl . '/images/repro-email-logo-grey.png';
        $verificationLogoLightUrl = $appUrl . '/images/repro-email-logo-light.png';

        return array_replace_recursive([
            'product_name' => 'R/E Pro Photos',
            'support_email' => config('mail.contact_address', 'contact@reprophotos.com'),
            'support_phone' => config('mail.contact_phone', '202-868-1663'),
            'dashboard_url' => $dashboardUrl,
            'base_url' => $dashboardUrl,
            'website_url' => 'https://reprophotos.com',
            'email_logo_grey_url' => $emailLogoGreyUrl,
            'verification_logo_light_url' => $verificationLogoLightUrl,
            // Temporary aliases for older callers during the branding refactor.
            'logo_url' => $emailLogoGreyUrl,
            'verification_logo_url' => $verificationLogoLightUrl,
            'outer_background' => '#00141d',
            'shell_background' => '#00141d',
            'hero_surface' => '#111c2e',
            'content_surface' => '#111c2e',
            'section_surface' => '#16233a',
            'footer_surface' => '#00141d',
            'meta_surface' => '#142237',
            'border_color' => '#24344d',
            'meta_border_color' => '#2d4263',
            'heading_color' => '#e8edf5',
            'body_color' => '#a9b8cb',
            'muted_color' => '#8298b4',
            'link_color' => '#7eb3ff',
            'legal_copy_color' => '#8da2be',
            'locale' => app()->getLocale(),
            'region' => (string) config('app.region', 'us'),
            'timezone' => (string) config('app.timezone', 'UTC'),
        ], $overrides);
    }
}
