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
        $socialIconBase = $appUrl . '/images/social';

        return array_replace_recursive([
            'product_name' => 'R/E Pro Photos',
            'support_email' => config('mail.contact_address', 'contact@reprophotos.com'),
            'support_phone' => config('mail.contact_phone', '202-868-1113'),
            'dashboard_url' => $dashboardUrl,
            'base_url' => $dashboardUrl,
            'website_url' => 'https://reprophotos.com',
            'review_url' => 'https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews',
            'email_logo_grey_url' => $emailLogoGreyUrl,
            'verification_logo_light_url' => $verificationLogoLightUrl,
            'social_facebook_url' => 'https://www.facebook.com/reprophotos',
            'social_instagram_url' => 'https://www.instagram.com/reprophotos',
            'social_linkedin_url' => 'https://www.linkedin.com/company/reprophotos',
            'social_facebook_icon_url' => $socialIconBase . '/facebook.png',
            'social_instagram_icon_url' => $socialIconBase . '/instagram.png',
            'social_linkedin_icon_url' => $socialIconBase . '/linkedin.png',
            // Temporary aliases for older callers during the branding refactor.
            'logo_url' => $emailLogoGreyUrl,
            'verification_logo_url' => $verificationLogoLightUrl,
            'email_canvas_background_light' => '#ffffff',
            'email_canvas_background_dark' => '#0b1220',
            'card_surface_light' => '#ffffff',
            'card_surface_dark' => '#131c2e',
            'card_surface_dark_gradient' => 'linear-gradient(180deg, #18233a 0%, #121a2b 100%)',
            'section_surface_light' => '#f7fbff',
            'section_surface_dark' => '#1a2740',
            'section_surface_dark_gradient' => 'linear-gradient(180deg, #1d2c48 0%, #18243b 100%)',
            'stat_surface_light' => '#f5f9ff',
            'stat_surface_dark' => '#1a2740',
            'stat_surface_dark_gradient' => 'linear-gradient(180deg, #1d2c48 0%, #18243b 100%)',
            'note_surface_light' => '#f8fbff',
            'note_surface_dark' => '#1a2740',
            'note_surface_dark_gradient' => 'linear-gradient(180deg, #1d2c48 0%, #18243b 100%)',
            'footer_surface_light' => '#f7fbff',
            'footer_surface_dark' => '#0b1220',
            'footer_surface_dark_gradient' => 'linear-gradient(180deg, #0e1626 0%, #0b1220 100%)',
            'meta_surface_light' => '#edf3fb',
            'meta_surface_dark' => '#1a2740',
            'meta_surface_dark_gradient' => 'linear-gradient(180deg, #1d2c48 0%, #18243b 100%)',
            'heading_color_light' => '#071223',
            'heading_color_dark' => '#eef2f9',
            'body_color_light' => '#47627f',
            'body_color_dark' => '#c4cfde',
            'muted_color_light' => '#6c84a2',
            'muted_color_dark' => '#8b9cb4',
            'link_color_light' => '#1463ff',
            'link_color_dark' => '#6ba6ff',
            'legal_copy_color_light' => '#5f6b7a',
            'legal_copy_color_dark' => '#7f8da4',
            'button_secondary_surface_light' => '#edf4ff',
            'button_secondary_surface_dark' => '#1f2d49',
            'button_secondary_surface_dark_gradient' => 'linear-gradient(180deg, #233560 0%, #1d2b49 100%)',
            'button_secondary_text_light' => '#173963',
            'button_secondary_text_dark' => '#eef2f9',
            'callout_surface_light' => '#f7fbff',
            'callout_surface_dark' => '#1a2740',
            'callout_surface_dark_gradient' => 'linear-gradient(180deg, #1d2c48 0%, #18243b 100%)',
            'callout_success_surface_light' => '#eff6ff',
            'callout_success_surface_dark' => '#163050',
            'callout_success_surface_dark_gradient' => 'linear-gradient(180deg, #1b3c63 0%, #163050 100%)',
            'callout_warning_surface_light' => '#fff3e3',
            'callout_warning_surface_dark' => '#3a2c15',
            'callout_warning_surface_dark_gradient' => 'linear-gradient(180deg, #4a371b 0%, #3a2c15 100%)',
            'callout_danger_surface_light' => '#fff0f1',
            'callout_danger_surface_dark' => '#3a1f27',
            'callout_danger_surface_dark_gradient' => 'linear-gradient(180deg, #4a2833 0%, #3a1f27 100%)',
            'border_color_light' => 'transparent',
            'border_color_dark' => 'rgba(255, 255, 255, 0.08)',
            'meta_border_color_light' => 'transparent',
            'meta_border_color_dark' => 'rgba(255, 255, 255, 0.08)',
            // Compatibility aliases used by older templates/renderers.
            'email_outer_background' => '#ffffff',
            'email_outer_background_dark' => '#0b1220',
            'outer_background' => '#0b1220',
            'shell_background' => '#0b1220',
            'hero_surface' => '#ffffff',
            'content_surface' => '#ffffff',
            'section_surface' => '#f7fbff',
            'footer_surface' => '#f7fbff',
            'meta_surface' => '#edf3fb',
            'border_color' => 'transparent',
            'meta_border_color' => 'transparent',
            'heading_color' => '#071223',
            'body_color' => '#47627f',
            'muted_color' => '#6c84a2',
            'link_color' => '#1463ff',
            'legal_copy_color' => '#5f6b7a',
            'locale' => app()->getLocale(),
            'region' => (string) config('app.region', 'us'),
            'timezone' => (string) config('app.timezone', 'UTC'),
        ], $overrides);
    }
}
