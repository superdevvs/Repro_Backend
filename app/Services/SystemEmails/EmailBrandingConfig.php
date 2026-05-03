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
            'review_url' => 'https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews',
            'email_logo_grey_url' => $emailLogoGreyUrl,
            'verification_logo_light_url' => $verificationLogoLightUrl,
            // Temporary aliases for older callers during the branding refactor.
            'logo_url' => $emailLogoGreyUrl,
            'verification_logo_url' => $verificationLogoLightUrl,
            'email_canvas_background_light' => '#ffffff',
            'email_canvas_background_dark' => '#00141d',
            'card_surface_light' => '#ffffff',
            'card_surface_dark' => '#111c2e',
            'card_surface_dark_gradient' => 'linear-gradient(180deg, #17365c 0%, #111c2e 100%)',
            'section_surface_light' => '#f7fbff',
            'section_surface_dark' => '#16233a',
            'section_surface_dark_gradient' => 'linear-gradient(180deg, #1a3d67 0%, #16233a 100%)',
            'stat_surface_light' => '#f5f9ff',
            'stat_surface_dark' => '#16233a',
            'stat_surface_dark_gradient' => 'linear-gradient(180deg, #1a3d67 0%, #16233a 100%)',
            'note_surface_light' => '#f8fbff',
            'note_surface_dark' => '#16233a',
            'note_surface_dark_gradient' => 'linear-gradient(180deg, #1a3d67 0%, #16233a 100%)',
            'footer_surface_light' => '#f7fbff',
            'footer_surface_dark' => '#00141d',
            'footer_surface_dark_gradient' => 'linear-gradient(180deg, #08223b 0%, #00141d 100%)',
            'meta_surface_light' => '#edf3fb',
            'meta_surface_dark' => '#142237',
            'meta_surface_dark_gradient' => 'linear-gradient(180deg, #14345a 0%, #142237 100%)',
            'heading_color_light' => '#071223',
            'heading_color_dark' => '#e8edf5',
            'body_color_light' => '#47627f',
            'body_color_dark' => '#a9b8cb',
            'muted_color_light' => '#6c84a2',
            'muted_color_dark' => '#8298b4',
            'link_color_light' => '#1463ff',
            'link_color_dark' => '#7eb3ff',
            'legal_copy_color_light' => '#5f6b7a',
            'legal_copy_color_dark' => '#8da2be',
            'button_secondary_surface_light' => '#edf4ff',
            'button_secondary_surface_dark' => '#16233a',
            'button_secondary_surface_dark_gradient' => 'linear-gradient(180deg, #1b3e69 0%, #16233a 100%)',
            'button_secondary_text_light' => '#173963',
            'button_secondary_text_dark' => '#e8edf5',
            'callout_surface_light' => '#f7fbff',
            'callout_surface_dark' => '#16233a',
            'callout_surface_dark_gradient' => 'linear-gradient(180deg, #1a3d67 0%, #16233a 100%)',
            'callout_success_surface_light' => '#eff6ff',
            'callout_success_surface_dark' => '#18304f',
            'callout_success_surface_dark_gradient' => 'linear-gradient(180deg, #23466f 0%, #18304f 100%)',
            'callout_warning_surface_light' => '#fff3e3',
            'callout_warning_surface_dark' => '#382714',
            'callout_warning_surface_dark_gradient' => 'linear-gradient(180deg, #4a341c 0%, #382714 100%)',
            'callout_danger_surface_light' => '#fff0f1',
            'callout_danger_surface_dark' => '#351b22',
            'callout_danger_surface_dark_gradient' => 'linear-gradient(180deg, #4a2430 0%, #351b22 100%)',
            'border_color_light' => 'transparent',
            'border_color_dark' => 'transparent',
            'meta_border_color_light' => 'transparent',
            'meta_border_color_dark' => 'transparent',
            // Compatibility aliases used by older templates/renderers.
            'email_outer_background' => '#ffffff',
            'email_outer_background_dark' => '#00141d',
            'outer_background' => '#00141d',
            'shell_background' => '#00141d',
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
