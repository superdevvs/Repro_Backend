<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;
use App\Services\SystemEmails\EmailBrandingConfig;
use Illuminate\Support\Arr;

class TemplateRenderer
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function render(MessageTemplate $template, array $variables): array
    {
        $availableKeys = collect($template->variables_json ?? []);
        if (!$availableKeys->contains('shoot_changes') && array_key_exists('shoot_changes', $variables)) {
            $availableKeys->push('shoot_changes');
        }
        if (!$availableKeys->contains('shoot_changes_html') && array_key_exists('shoot_changes_html', $variables)) {
            $availableKeys->push('shoot_changes_html');
        }

        $placeholderKeys = $this->extractPlaceholderKeys([
            $template->subject ?? '',
            $template->body_html ?? '',
            $template->body_text ?? '',
        ]);
        foreach ($placeholderKeys as $key) {
            if (!$availableKeys->contains($key)) {
                $availableKeys->push($key);
            }
        }

        $available = $availableKeys
            ->mapWithKeys(fn ($var) => [$var => Arr::get($variables, $var, '')]);

        $html = $this->replacePlaceholders($template->body_html ?? '', $available->all());
        $text = $this->replacePlaceholders($template->body_text ?? '', $available->all());
        $subject = $this->replacePlaceholders($template->subject ?? '', $available->all());

        if ($this->serviceDetailsAlreadyNamePhotographer($variables)) {
            $html = $this->removeDuplicatePhotographerSummaryRows($html);
            $text = $this->removeDuplicatePhotographerTextRows($text);
        }

        $html = $this->normalizeLegacyUrls($html);
        $text = $this->normalizeLegacyUrls($text);
        $subject = $this->normalizeLegacyUrls($subject);
        $html = $this->stripLeadingGreeting($html);
        $html = $this->normalizeLegacySuccessColors($html);
        $text = $this->stripLeadingGreetingFromText($text);

        if ($template->channel === 'EMAIL' && $html !== '') {
            $html = $this->wrapWithLayout($template, $html, $subject);
        }

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'body_html' => $html,
            'body_text' => $text,
            'missing' => $this->missingVariables($template, $variables),
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return string[]
     */
    public function missingVariables(MessageTemplate $template, array $variables): array
    {
        $required = $template->variables_json ?? [];

        return collect($required)
            ->reject(fn ($key) => array_key_exists($key, $variables))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function branding(): array
    {
        return app(EmailBrandingConfig::class)->defaults();
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function replacePlaceholders(string $content, array $values): string
    {
        return collect($values)->reduce(
            fn ($carry, $value, $key) => str_replace(
                ['{{' . $key . '}}', '[' . $key . ']'],
                (string) $value,
                $carry
            ),
            $content
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function serviceDetailsAlreadyNamePhotographer(array $variables): bool
    {
        $serviceHtml = strtolower(strip_tags((string) Arr::get($variables, 'services_provided_html', '')));
        $serviceText = strtolower((string) Arr::get($variables, 'services_provided', ''));

        return str_contains($serviceHtml, 'assigned photographer:')
            || str_contains($serviceText, '(photographer:');
    }

    protected function removeDuplicatePhotographerSummaryRows(string $html): string
    {
        $patterns = [
            '/\s*<div\b[^>]*class=(["\'])[^"\']*\binfo-row\b[^"\']*\1[^>]*>\s*<(?:span|div)\b[^>]*class=(["\'])[^"\']*\binfo-label\b[^"\']*\2[^>]*>\s*Photographers?:\s*<\/(?:span|div)>.*?<\/div>\s*/is',
            '/\s*<div\b[^>]*class=(["\'])[^"\']*\bdetail-row\b[^"\']*\1[^>]*>\s*<(?:span|div)\b[^>]*class=(["\'])[^"\']*\bdetail-label\b[^"\']*\2[^>]*>\s*Photographers?:\s*<\/(?:span|div)>.*?<\/div>\s*/is',
        ];

        return preg_replace($patterns, '', $html) ?? $html;
    }

    protected function removeDuplicatePhotographerTextRows(string $text): string
    {
        return preg_replace('/^\s*Photographers?:\s*[^\r\n]*(?:\r?\n)?/mi', '', $text) ?? $text;
    }

    protected function wrapWithLayout(MessageTemplate $template, string $bodyHtml, string $subject): string
    {
        $bodyHtml = $this->stripLegacyWrapper($bodyHtml);
        $bodyHtml = $this->stabilizeEmailBodyHtml($bodyHtml);

        $branding = $this->branding();
        $logoUrl = $branding['email_logo_grey_url'];
        $productName = $branding['product_name'];
        $supportEmail = $branding['support_email'];
        $supportPhone = $branding['support_phone'];
        $dashboardUrl = $branding['dashboard_url'];
        $websiteUrl = $branding['website_url'];
        $canvasBackgroundLight = $branding['email_canvas_background_light'] ?? ($branding['email_outer_background'] ?? '#ffffff');
        $canvasBackgroundDark = $branding['email_canvas_background_dark'] ?? ($branding['email_outer_background_dark'] ?? ($branding['outer_background'] ?? '#00141d'));
        $contentSurfaceLight = $branding['card_surface_light'] ?? ($branding['content_surface'] ?? '#ffffff');
        $contentSurfaceDark = $branding['card_surface_dark'] ?? '#111c2e';
        $contentSurfaceDarkGradient = $branding['card_surface_dark_gradient'] ?? "linear-gradient(180deg, {$contentSurfaceDark} 0%, {$contentSurfaceDark} 100%)";
        $sectionSurfaceLight = $branding['section_surface_light'] ?? ($branding['section_surface'] ?? '#f7fbff');
        $sectionSurfaceDark = $branding['section_surface_dark'] ?? '#16233a';
        $sectionSurfaceDarkGradient = $branding['section_surface_dark_gradient'] ?? "linear-gradient(180deg, {$sectionSurfaceDark} 0%, {$sectionSurfaceDark} 100%)";
        $statSurfaceLight = $branding['stat_surface_light'] ?? '#f5f9ff';
        $statSurfaceDark = $branding['stat_surface_dark'] ?? $sectionSurfaceDark;
        $statSurfaceDarkGradient = $branding['stat_surface_dark_gradient'] ?? "linear-gradient(180deg, {$statSurfaceDark} 0%, {$statSurfaceDark} 100%)";
        $noteSurfaceLight = $branding['note_surface_light'] ?? '#f8fbff';
        $noteSurfaceDark = $branding['note_surface_dark'] ?? $sectionSurfaceDark;
        $noteSurfaceDarkGradient = $branding['note_surface_dark_gradient'] ?? "linear-gradient(180deg, {$noteSurfaceDark} 0%, {$noteSurfaceDark} 100%)";
        $footerSurfaceLight = $branding['footer_surface_light'] ?? ($branding['footer_surface'] ?? '#f7fbff');
        $footerSurfaceDark = $branding['footer_surface_dark'] ?? '#00141d';
        $footerSurfaceDarkGradient = $branding['footer_surface_dark_gradient'] ?? "linear-gradient(180deg, {$footerSurfaceDark} 0%, {$footerSurfaceDark} 100%)";
        $metaSurfaceLight = $branding['meta_surface_light'] ?? ($branding['meta_surface'] ?? '#edf3fb');
        $metaSurfaceDark = $branding['meta_surface_dark'] ?? '#142237';
        $metaSurfaceDarkGradient = $branding['meta_surface_dark_gradient'] ?? "linear-gradient(180deg, {$metaSurfaceDark} 0%, {$metaSurfaceDark} 100%)";
        $borderColorLight = $branding['border_color_light'] ?? ($branding['border_color'] ?? 'transparent');
        $borderColorDark = $branding['border_color_dark'] ?? 'transparent';
        $metaBorderColorLight = $branding['meta_border_color_light'] ?? ($branding['meta_border_color'] ?? 'transparent');
        $metaBorderColorDark = $branding['meta_border_color_dark'] ?? 'transparent';
        $headingColorLight = $branding['heading_color_light'] ?? ($branding['heading_color'] ?? '#071223');
        $headingColorDark = $branding['heading_color_dark'] ?? '#e8edf5';
        $bodyColorLight = $branding['body_color_light'] ?? ($branding['body_color'] ?? '#47627f');
        $bodyColorDark = $branding['body_color_dark'] ?? '#a9b8cb';
        $mutedColorLight = $branding['muted_color_light'] ?? ($branding['muted_color'] ?? '#6c84a2');
        $mutedColorDark = $branding['muted_color_dark'] ?? '#8298b4';
        $linkColorLight = $branding['link_color_light'] ?? ($branding['link_color'] ?? '#1463ff');
        $linkColorDark = $branding['link_color_dark'] ?? '#7eb3ff';
        $reviewUrl = $branding['review_url'] ?? 'https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews';
        $buttonSecondarySurfaceLight = $branding['button_secondary_surface_light'] ?? '#edf4ff';
        $buttonSecondarySurfaceDark = $branding['button_secondary_surface_dark'] ?? '#16233a';
        $buttonSecondarySurfaceDarkGradient = $branding['button_secondary_surface_dark_gradient'] ?? "linear-gradient(180deg, {$buttonSecondarySurfaceDark} 0%, {$buttonSecondarySurfaceDark} 100%)";
        $buttonSecondaryTextLight = $branding['button_secondary_text_light'] ?? '#173963';
        $buttonSecondaryTextDark = $branding['button_secondary_text_dark'] ?? '#e8edf5';
        $legalCopyColorLight = $branding['legal_copy_color_light'] ?? ($branding['legal_copy_color'] ?? '#5f6b7a');
        $legalCopyColorDark = $branding['legal_copy_color_dark'] ?? '#8da2be';
        $heroCopy = $this->escapeHtml($this->resolveHeroCopy($template));
        $heroTitle = $this->buildHeroTitleHtml($template, $subject !== '' ? $subject : ($template->name ?? "{$productName} Update"));
        $journeyHtml = '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
:root {
  color-scheme: light dark;
  supported-color-schemes: light dark;
}
body {
  margin: 0;
  padding: 0;
  background: {$canvasBackgroundLight};
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
  color: {$bodyColorLight};
  -webkit-text-size-adjust: 100%;
  word-break: break-word;
}
table { border-collapse: collapse; width: 100%; }
img { border: 0; display: block; max-width: 100%; }
a { color: {$linkColorLight}; text-decoration: none; }
.page { padding: 30px 12px; }
.shell { max-width: 720px; margin: 0 auto; }
.body-surface {
  background-color: {$contentSurfaceLight};
  background-image: none;
  color: {$bodyColorLight};
}
.body-heading {
  color: {$headingColorLight};
}
.body-link {
  color: {$linkColorLight};
}
.body-divider {
  border-color: transparent;
}
.dark-panel-surface {
  background-color: {$footerSurfaceLight};
  background-image: none;
  color: {$bodyColorLight};
}
.dark-panel-copy {
  color: {$bodyColorLight};
}
.dark-panel-link {
  color: {$headingColorLight};
}
.dark-meta-surface {
  background-color: {$metaSurfaceLight};
  background-image: none;
  border-color: transparent;
}
.dark-meta-label {
  color: {$mutedColorLight};
}
.dark-meta-value {
  color: {$headingColorLight};
}
.dark-legal-copy {
  color: {$legalCopyColorLight};
}
.hero-card,
.body-card {
  background-color: {$contentSurfaceLight};
  background-image: none;
  border-radius: 34px;
  overflow: hidden;
  box-shadow: 0 24px 70px rgba(22, 34, 60, 0.09);
  border: 0;
}
.hero-card {
  position: relative;
  padding: 34px 38px 28px;
}
.brand-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  position: relative;
  z-index: 2;
  margin-bottom: 28px;
}
.brand-logo {
  display: inline-block;
  flex-shrink: 0;
}
.brand-logo img {
  width: 154px;
  height: auto;
}
.brand-copy {
  text-align: right;
  color: {$headingColorLight};
  font-size: 16px;
  line-height: 1.4;
  font-weight: 800;
}
.brand-copy span {
  display: block;
  color: {$mutedColorLight};
  font-size: 11px;
  letter-spacing: 1.4px;
  text-transform: uppercase;
  font-weight: 700;
}
.hero-overline {
  display: block;
  margin-bottom: 14px;
  color: {$mutedColorLight};
  font-size: 16px;
  line-height: 1.5;
  letter-spacing: 0.2px;
  font-weight: 500;
}
.hero-title {
  position: relative;
  z-index: 2;
  margin: 0;
  max-width: 520px;
  font-size: 48px;
  line-height: 0.96;
  font-weight: 300;
  letter-spacing: -2.4px;
  color: {$headingColorLight};
}
.hero-title-lead {
  display: block;
  margin-bottom: 14px;
  color: {$mutedColorLight};
  font-size: 16px;
  line-height: 1.5;
  letter-spacing: 0.2px;
  font-weight: 500;
}
.hero-title-location {
  display: block;
  color: {$headingColorLight};
  font-weight: 200;
}
.hero-title-status {
  display: block;
  margin-top: 12px;
  color: {$mutedColorLight};
  font-size: 22px;
  line-height: 1.25;
  letter-spacing: -0.6px;
  font-weight: 500;
}
.hero-title-primary {
  display: inline;
  color: {$headingColorLight};
}
.hero-title-accent {
  display: inline;
  color: {$linkColorLight};
  font-weight: 400;
}
.hero-copy {
  position: relative;
  z-index: 2;
  max-width: 540px;
  margin: 20px 0 0;
  color: {$bodyColorLight};
  font-size: 15px;
  line-height: 1.8;
}
.journey {
  position: relative;
  z-index: 2;
  margin-top: 34px;
  max-width: 640px;
}
.journey-bars {
  font-size: 0;
  white-space: nowrap;
}
.journey-bar {
  display: inline-block;
  width: calc(25% - 9px);
  height: 7px;
  margin-right: 12px;
  border-radius: 999px;
  background: {$borderColorLight};
}
.journey-bar:last-child {
  margin-right: 0;
}
.journey-bar-complete {
  background: #1463ff;
}
.journey-bar-next {
  background: {$linkColorLight};
}
.journey-labels {
  width: 100%;
  margin-top: 12px;
}
.journey-label {
  width: 25%;
  padding-right: 10px;
  font-size: 11px;
  line-height: 1.4;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  color: {$mutedColorLight};
  font-weight: 800;
}
.journey-label-complete {
  color: #1463ff;
}
.journey-label-next {
  color: {$bodyColorLight};
}
.body-card {
  margin-top: 18px;
}
.body-inner {
  padding: 30px 32px;
}
.body-inner p,
.body-inner li,
.body-inner div,
.body-inner td,
.body-inner span {
  color: {$bodyColorLight};
  font-size: 15px;
  line-height: 1.8;
}
.body-inner p { margin: 0 0 14px; }
.body-inner ul,
.body-inner ol {
  margin: 0 0 16px;
  padding-left: 20px;
}
.body-inner h1,
.body-inner h2,
.body-inner h3,
.body-inner h4 {
  margin: 0 0 14px;
  color: {$headingColorLight};
  line-height: 1.15;
}
.body-inner h1 { font-size: 42px; font-weight: 300; letter-spacing: -1.6px; }
.body-inner h2 { font-size: 28px; font-weight: 800; }
.body-inner h3 { font-size: 22px; font-weight: 800; }
.body-inner h4 { font-size: 16px; font-weight: 800; }
.body-inner strong { color: {$headingColorLight}; }
.body-inner center { display: block; text-align: left; }
.body-inner hr {
  border: 0;
  border-top: 0;
  margin: 20px 0;
}
.button {
  display: inline-block;
  padding: 14px 22px;
  border-radius: 999px;
  background: linear-gradient(135deg, #1463ff 0%, #0b83ff 100%);
  color: #ffffff !important;
  font-weight: 800;
  font-size: 14px;
  line-height: 1.2;
  text-decoration: none;
  margin: 6px 10px 10px 0;
  box-shadow: 0 12px 24px rgba(20, 99, 255, 0.18);
}
.button-large {
  padding: 18px 30px;
  font-size: 16px;
  letter-spacing: 0.2px;
  box-shadow: 0 16px 30px rgba(20, 99, 255, 0.22);
}
.info-box {
  margin: 20px 0;
  padding: 18px 20px;
  border-radius: 22px;
  border: 0;
  background: {$sectionSurfaceLight};
  box-shadow: none;
}
.info-row {
  padding: 10px 0;
  border-bottom: 0;
}
.info-row:last-child { border-bottom: 0; }
.info-label {
  display: inline-block;
  min-width: 150px;
  color: {$mutedColorLight};
  font-weight: 800;
  font-size: 12px;
  line-height: 1.5;
  letter-spacing: 1.2px;
  text-transform: uppercase;
}
.note {
  margin: 20px 0;
  padding: 16px 18px;
  border-radius: 18px;
  border: 0;
  background: {$noteSurfaceLight};
  color: {$bodyColorLight} !important;
}
.change-card {
  margin: 22px 0;
  padding: 20px 22px;
  border-radius: 24px;
  border: 0;
  background: {$sectionSurfaceLight};
}
.change-card-title {
  margin: 0 0 12px;
  color: {$headingColorLight};
  font-size: 18px;
  line-height: 1.4;
  font-weight: 800;
}
.change-card p,
.change-card li,
.change-card div,
.change-card span {
  color: {$bodyColorLight} !important;
}
.change-card ul,
.change-card ol {
  margin: 0;
  padding-left: 20px;
}
.change-card li {
  margin-bottom: 10px;
}
.footer-wrap { padding: 18px 0 0; }
.footer-card {
  border-radius: 26px;
  background: {$footerSurfaceLight};
  padding: 24px 26px;
  color: {$bodyColorLight};
  box-shadow: 0 20px 40px rgba(16, 40, 71, 0.18);
}
.footer-title {
  margin: 0 0 8px;
  font-size: 18px;
  line-height: 1.5;
  color: {$headingColorLight};
  font-weight: 800;
}
.footer-copy {
  margin: 0;
  color: {$bodyColorLight};
  font-size: 14px;
  line-height: 1.8;
}
.footer-copy a { color: {$headingColorLight} !important; text-decoration: underline; }
.footer-links a { margin-right: 14px; white-space: nowrap; }
.footer-meta {
  width: 100%;
  margin-top: 18px;
}
.footer-meta-cell {
  width: 50%;
  padding-right: 12px;
  vertical-align: top;
}
.footer-meta-card {
  border-radius: 18px;
  padding: 14px 16px;
  background: {$metaSurfaceLight};
  border: 0;
}
.footer-meta-label {
  display: block;
  margin-bottom: 4px;
  color: {$mutedColorLight};
  font-size: 11px;
  line-height: 1.4;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  font-weight: 800;
}
.footer-meta-value {
  color: {$headingColorLight};
  font-size: 14px;
  line-height: 1.6;
  font-weight: 700;
}
.footer-note {
  padding: 14px 8px 0;
  text-align: center;
  color: {$legalCopyColorLight};
  font-size: 11px;
  line-height: 1.7;
}
@media only screen and (max-width: 640px) {
  .page { padding: 14px 8px !important; }
  .hero-card { padding: 24px 22px !important; }
  .body-inner, .footer-card { padding-left: 20px !important; padding-right: 20px !important; }
  .hero-title {
    font-size: 32px !important;
    letter-spacing: -1.6px !important;
    max-width: none !important;
  }
  .hero-title-lead {
    margin-bottom: 10px !important;
    font-size: 13px !important;
  }
  .hero-title-status {
    margin-top: 10px !important;
    font-size: 18px !important;
  }
  .hero-overline {
    font-size: 14px !important;
    margin-bottom: 12px !important;
  }
  .hero-copy { max-width: none !important; }
  .journey-bar {
    width: calc(25% - 7px) !important;
    margin-right: 9px !important;
  }
  .journey-label {
    display: block !important;
    width: 100% !important;
    padding: 0 0 8px !important;
  }
  .info-label {
    display: block !important;
    min-width: 0 !important;
    margin-bottom: 4px;
  }
  .footer-meta,
  .footer-meta tbody,
  .footer-meta tr {
    display: block !important;
    width: 100% !important;
  }
  .footer-meta-cell {
    display: block !important;
    width: 100% !important;
    padding: 0 0 10px !important;
  }
  .footer-meta-cell-last {
    padding-bottom: 0 !important;
  }
  .footer-meta-card {
    display: block !important;
  }
}
@media (prefers-color-scheme: dark) {
  body {
    background: {$canvasBackgroundDark} !important;
    color: {$bodyColorDark} !important;
  }
  a { color: {$linkColorDark} !important; }
  .body-surface,
  .hero-card,
  .body-card {
    background: {$contentSurfaceDarkGradient} !important;
    background-color: {$contentSurfaceDark} !important;
    color: {$bodyColorDark} !important;
  }
  .dark-panel-surface,
  .footer-card {
    background: {$footerSurfaceDarkGradient} !important;
    background-color: {$footerSurfaceDark} !important;
    color: {$bodyColorDark} !important;
  }
  .dark-meta-surface,
  .footer-meta-card {
    background: {$metaSurfaceDarkGradient} !important;
    background-color: {$metaSurfaceDark} !important;
    border-color: {$metaBorderColorDark} !important;
  }
  .info-box { background: {$sectionSurfaceDarkGradient} !important; background-color: {$sectionSurfaceDark} !important; border-color: {$borderColorDark} !important; }
  .note { background: {$noteSurfaceDarkGradient} !important; background-color: {$noteSurfaceDark} !important; color: {$bodyColorDark} !important; border-color: {$borderColorDark} !important; }
  .change-card { background: {$sectionSurfaceDarkGradient} !important; background-color: {$sectionSurfaceDark} !important; border-color: {$borderColorDark} !important; }
  .body-heading,
  .dark-panel-link,
  .dark-meta-value,
  .hero-title,
  .hero-title-location,
  .body-inner h1,
  .body-inner h2,
  .body-inner h3,
  .body-inner h4,
  .body-inner strong,
  .change-card-title {
    color: {$headingColorDark} !important;
  }
  .body-link,
  .hero-title-accent { color: {$linkColorDark} !important; }
  .hero-copy,
  .body-inner p,
  .body-inner li,
  .body-inner div,
  .body-inner td,
  .body-inner span,
  .dark-panel-copy {
    color: {$bodyColorDark} !important;
  }
  .dark-meta-label,
  .hero-overline,
  .hero-title-lead,
  .hero-title-status,
  .info-label,
  .journey-label {
    color: {$mutedColorDark} !important;
  }
  .journey-bar { background: {$borderColorDark} !important; }
  .journey-bar-next { background: {$linkColorDark} !important; }
  .journey-label-next { color: {$bodyColorDark} !important; }
  .button,
  .button-large {
    background-image: linear-gradient(135deg, #1463ff 0%, #0b83ff 100%) !important;
    color: #ffffff !important;
  }
  .footer-note,
  .dark-legal-copy { color: {$legalCopyColorDark} !important; }
}
[data-ogsc] body,
[data-ogsc] .page {
  background: {$canvasBackgroundDark} !important;
  color: {$bodyColorDark} !important;
}
[data-ogsc] a { color: {$linkColorDark} !important; }
[data-ogsc] .body-surface,
[data-ogsc] .hero-card,
[data-ogsc] .body-card {
  background: {$contentSurfaceDarkGradient} !important;
  background-color: {$contentSurfaceDark} !important;
  color: {$bodyColorDark} !important;
}
[data-ogsc] .dark-panel-surface,
[data-ogsc] .footer-card {
  background: {$footerSurfaceDarkGradient} !important;
  background-color: {$footerSurfaceDark} !important;
  color: {$bodyColorDark} !important;
}
[data-ogsc] .dark-meta-surface,
[data-ogsc] .footer-meta-card {
  background: {$metaSurfaceDarkGradient} !important;
  background-color: {$metaSurfaceDark} !important;
  border-color: {$metaBorderColorDark} !important;
}
[data-ogsc] .info-box { background: {$sectionSurfaceDarkGradient} !important; background-color: {$sectionSurfaceDark} !important; border-color: {$borderColorDark} !important; }
[data-ogsc] .note { background: {$noteSurfaceDarkGradient} !important; background-color: {$noteSurfaceDark} !important; color: {$bodyColorDark} !important; border-color: {$borderColorDark} !important; }
[data-ogsc] .change-card { background: {$sectionSurfaceDarkGradient} !important; background-color: {$sectionSurfaceDark} !important; border-color: {$borderColorDark} !important; }
[data-ogsc] .body-heading,
[data-ogsc] .dark-panel-link,
[data-ogsc] .dark-meta-value,
[data-ogsc] .hero-title,
[data-ogsc] .hero-title-location,
[data-ogsc] .body-inner h1,
[data-ogsc] .body-inner h2,
[data-ogsc] .body-inner h3,
[data-ogsc] .body-inner h4,
[data-ogsc] .body-inner strong,
[data-ogsc] .change-card-title {
  color: {$headingColorDark} !important;
}
[data-ogsc] .body-link,
[data-ogsc] .hero-title-accent { color: {$linkColorDark} !important; }
[data-ogsc] .hero-copy,
[data-ogsc] .body-inner p,
[data-ogsc] .body-inner li,
[data-ogsc] .body-inner div,
[data-ogsc] .body-inner td,
[data-ogsc] .body-inner span,
[data-ogsc] .dark-panel-copy {
  color: {$bodyColorDark} !important;
}
[data-ogsc] .dark-meta-label,
[data-ogsc] .hero-overline,
[data-ogsc] .hero-title-lead,
[data-ogsc] .hero-title-status,
[data-ogsc] .info-label,
[data-ogsc] .journey-label {
  color: {$mutedColorDark} !important;
}
[data-ogsc] .journey-bar { background: {$borderColorDark} !important; }
[data-ogsc] .journey-bar-next { background: {$linkColorDark} !important; }
[data-ogsc] .journey-label-next { color: {$bodyColorDark} !important; }
[data-ogsc] .footer-note,
[data-ogsc] .dark-legal-copy { color: {$legalCopyColorDark} !important; }
</style>
</head>
<body bgcolor="{$canvasBackgroundLight}">
<div style="display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden; mso-hide:all;">&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>
<div class="page">
  <div class="shell">
    <div class="hero-card" style="background-color:{$contentSurfaceLight}; border:0;">
      <div class="brand-row">
        <div class="brand-logo">
          <img src="{$logoUrl}" alt="{$productName}" role="presentation">
        </div>
      </div>
      <h1 class="hero-title">{$heroTitle}</h1>
      <p class="hero-copy">{$heroCopy}</p>
{$journeyHtml}
    </div>
    <div class="body-card body-surface" style="background-color:{$contentSurfaceLight}; border:0; color:{$bodyColorLight};">
      <div class="body-inner body-surface" style="background-color:{$contentSurfaceLight}; color:{$bodyColorLight};">
{$bodyHtml}
      </div>
      <div class="footer-wrap">
        <div class="footer-card dark-panel-surface" style="background-color:{$footerSurfaceLight}; color:{$bodyColorLight};">
          <div class="footer-title dark-panel-copy" style="color:{$headingColorLight};">Need help with a shoot, invoice, or account question?</div>
          <p class="footer-copy dark-panel-copy" style="color:{$bodyColorLight};">
            Our team is here to help keep your marketing workflow moving.
            Reach us at <a href="mailto:{$supportEmail}" class="dark-panel-link" style="color:{$headingColorLight}; text-decoration:underline;">{$supportEmail}</a> or call {$supportPhone}.
          </p>
          <table class="footer-meta" role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px; width:100%;">
            <tr>
              <td class="footer-meta-cell" width="33.33%" style="width:33.33%; padding-right:8px; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                  <tr>
                    <td class="footer-meta-card dark-meta-surface" style="background-color:{$metaSurfaceLight}; border:0; border-radius:18px; padding:14px 16px;">
                      <a href="{$dashboardUrl}" class="dark-panel-link" style="display:block; margin-bottom:6px; color:{$headingColorLight}; font-size:14px; line-height:1.4; font-weight:800; text-decoration:none;">Dashboard</a>
                      <span class="footer-meta-value dark-meta-value" style="color:{$bodyColorLight}; font-size:12px; line-height:1.6; font-weight:600;">Track your shoots, invoices, and delivery updates in one place.</span>
                    </td>
                  </tr>
                </table>
              </td>
              <td class="footer-meta-cell" width="33.33%" style="width:33.33%; padding:0 4px; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                  <tr>
                    <td class="footer-meta-card dark-meta-surface" style="background-color:{$metaSurfaceLight}; border:0; border-radius:18px; padding:14px 16px;">
                      <a href="{$websiteUrl}" class="dark-panel-link" style="display:block; margin-bottom:6px; color:{$headingColorLight}; font-size:14px; line-height:1.4; font-weight:800; text-decoration:none;">Website</a>
                      <span class="footer-meta-value dark-meta-value" style="color:{$bodyColorLight}; font-size:12px; line-height:1.6; font-weight:600;">View products and services to order.</span>
                    </td>
                  </tr>
                </table>
              </td>
              <td class="footer-meta-cell footer-meta-cell-last" width="33.33%" style="width:33.33%; padding-left:8px; padding-right:0; vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                  <tr>
                    <td class="footer-meta-card dark-meta-surface" style="background-color:{$metaSurfaceLight}; border:0; border-radius:18px; padding:14px 16px;">
                      <a href="{$reviewUrl}" class="dark-panel-link" style="display:block; margin-bottom:6px; color:{$headingColorLight}; font-size:14px; line-height:1.4; font-weight:800; text-decoration:none;">Leave a Review</a>
                      <span class="footer-meta-value dark-meta-value" style="color:{$bodyColorLight}; font-size:12px; line-height:1.6; font-weight:600;">We are looking for 5 stars and nothing less.</span>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </div>
        <div class="footer-note dark-legal-copy" style="color:{$legalCopyColorLight};">
          This email was sent by {$productName}. Please keep this message for your records if it relates to a scheduled shoot, payment, or invoice.
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
HTML;
    }

    protected function stripLeadingGreeting(string $bodyHtml): string
    {
        return preg_replace('/^\s*<p>\s*(hi|hello)\b.*?<\/p>\s*/is', '', $bodyHtml) ?? $bodyHtml;
    }

    protected function stabilizeEmailBodyHtml(string $bodyHtml): string
    {
        $branding = $this->branding();
        $headingColor = (string) ($branding['heading_color_light'] ?? $branding['heading_color'] ?? '#071223');
        $bodyColor = (string) ($branding['body_color_light'] ?? $branding['body_color'] ?? '#47627f');
        $mutedColor = (string) ($branding['muted_color_light'] ?? $branding['muted_color'] ?? '#6c84a2');
        $linkColor = (string) ($branding['link_color_light'] ?? $branding['link_color'] ?? '#1463ff');
        $sectionSurface = (string) ($branding['section_surface_light'] ?? $branding['section_surface'] ?? '#f7fbff');
        $noteSurface = (string) ($branding['note_surface_light'] ?? '#f8fbff');
        $borderColor = (string) ($branding['border_color_light'] ?? $branding['border_color'] ?? 'transparent');

        $tagStyles = [
            'p' => "margin:0 0 14px; color:{$bodyColor}; font-size:15px; line-height:1.8;",
            'li' => "color:{$bodyColor}; font-size:15px; line-height:1.8;",
            'div' => "color:{$bodyColor}; font-size:15px; line-height:1.8;",
            'td' => "color:{$bodyColor}; font-size:15px; line-height:1.8;",
            'span' => "color:{$bodyColor}; font-size:15px; line-height:1.8;",
            'ul' => 'margin:0 0 16px; padding-left:20px;',
            'ol' => 'margin:0 0 16px; padding-left:20px;',
            'h1' => "margin:0 0 14px; color:{$headingColor}; line-height:1.15; font-size:42px; font-weight:300; letter-spacing:-1.6px;",
            'h2' => "margin:0 0 14px; color:{$headingColor}; line-height:1.15; font-size:28px; font-weight:800;",
            'h3' => "margin:0 0 14px; color:{$headingColor}; line-height:1.15; font-size:22px; font-weight:800;",
            'h4' => "margin:0 0 14px; color:{$headingColor}; line-height:1.15; font-size:16px; font-weight:800;",
            'strong' => "color:{$headingColor};",
            'center' => 'display:block; text-align:left;',
            'a' => "color:{$linkColor}; text-decoration:none;",
            'hr' => "border:0; border-top:0; margin:20px 0;",
        ];

        foreach ($tagStyles as $tag => $style) {
            $bodyHtml = $this->injectInlineStyleForTag($bodyHtml, $tag, $style);
        }

        $classStyles = [
            'info-box' => "margin:20px 0; padding:18px 20px; border-radius:22px; border:0; background-color:{$sectionSurface}; color:{$bodyColor}; box-shadow:none;",
            'info-row' => "padding:10px 0; border-bottom:0;",
            'info-label' => "display:inline-block; min-width:150px; color:{$mutedColor}; font-weight:800; font-size:12px; line-height:1.5; letter-spacing:1.2px; text-transform:uppercase;",
            'note' => "margin:20px 0; padding:16px 18px; border-radius:18px; border:0; background-color:{$noteSurface}; color:{$bodyColor} !important;",
            'change-card' => "margin:22px 0; padding:20px 22px; border-radius:24px; border:0; background-color:{$sectionSurface};",
            'change-card-title' => "margin:0 0 12px; color:{$headingColor}; font-size:18px; line-height:1.4; font-weight:800;",
            'button' => 'display:inline-block; padding:14px 22px; border-radius:999px; background-color:#1463ff; background-image:linear-gradient(135deg, #1463ff 0%, #0b83ff 100%); color:#ffffff !important; font-weight:800; font-size:14px; line-height:1.2; text-decoration:none; margin:6px 10px 10px 0;',
            'button-large' => 'padding:18px 30px; font-size:16px; letter-spacing:0.2px;',
        ];

        foreach ($classStyles as $class => $style) {
            $bodyHtml = $this->injectInlineStyleForClass($bodyHtml, $class, $style);
        }

        return $bodyHtml;
    }

    protected function stripLeadingGreetingFromText(string $text): string
    {
        return preg_replace('/^\s*(hi|hello)\b[^\r\n]*[\r\n]+/i', '', $text) ?? $text;
    }

    protected function injectInlineStyleForTag(string $html, string $tag, string $style): string
    {
        return preg_replace_callback(
            sprintf('/<%s\b([^>]*)>/i', preg_quote($tag, '/')),
            fn (array $matches): string => $this->appendStyleToOpenTag("<{$tag}{$matches[1]}>", $style),
            $html
        ) ?? $html;
    }

    protected function injectInlineStyleForClass(string $html, string $class, string $style): string
    {
        return preg_replace_callback(
            '/<([a-z0-9]+)\b([^>]*)>/i',
            function (array $matches) use ($class, $style): string {
                $openTag = $matches[0];

                if (!preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $openTag, $classMatch)) {
                    return $openTag;
                }

                $classes = preg_split('/\s+/', trim($classMatch[2])) ?: [];
                if (!in_array($class, $classes, true)) {
                    return $openTag;
                }

                return $this->appendStyleToOpenTag($openTag, $style);
            },
            $html
        ) ?? $html;
    }

    protected function appendStyleToOpenTag(string $openTag, string $style): string
    {
        if (preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/i', $openTag, $styleMatch, PREG_OFFSET_CAPTURE)) {
            $existing = rtrim($styleMatch[2][0]);
            $merged = $existing === '' ? $style : rtrim($existing, ';') . '; ' . $style;
            $fullMatch = $styleMatch[0][0];
            $quote = $styleMatch[1][0];

            return substr_replace(
                $openTag,
                ' style=' . $quote . $merged . $quote,
                $styleMatch[0][1],
                strlen($fullMatch)
            );
        }

        $isSelfClosing = (bool) preg_match('/\/>\s*$/', $openTag);
        $trimmedTag = preg_replace('/\s*\/?>\s*$/', '', $openTag) ?? $openTag;

        return $trimmedTag . ' style="' . $style . '"' . ($isSelfClosing ? ' />' : '>');
    }

    protected function normalizeLegacySuccessColors(string $bodyHtml): string
    {
        return str_ireplace(
            ['#22c55e', '#16a34a', '#15803d', '#f0fdf4', '#dcfce7'],
            ['#1463ff', '#1463ff', '#295391', '#eff6ff', '#dbeafe'],
            $bodyHtml
        );
    }

    protected function resolveHeroCopy(MessageTemplate $template): string
    {
        return match ($template->category) {
            'ACCOUNT' => 'Everything you need is organized below, including the latest account details and access links.',
            'BOOKING' => 'Your latest schedule details, property notes, and next actions are organized below in one place.',
            'REMINDER' => 'A timely reminder with the key details you need before the next step in the workflow.',
            'PAYMENT' => 'Your transaction status and the next milestones in the workflow are summarized below.',
            'INVOICE' => 'Invoice details, due dates, and follow-up actions are collected below for quick review.',
            default => 'The latest update from your R/E Pro Photos workflow is ready below.',
        };
    }

    protected function buildHeroTitleHtml(MessageTemplate $template, string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return '<span class="hero-title-primary">R/E Pro Photos update.</span>';
        }

        if ($locationTitle = $this->buildLocationFocusedHeroTitleHtml($template, $subject)) {
            return $locationTitle;
        }

        if ($titleParts = $this->extractTitleOverline($template, $subject)) {
            return sprintf(
                '<span class="hero-overline">%s</span><span class="hero-title-primary">%s</span>',
                $this->escapeHtml($titleParts['overline']),
                $this->escapeHtml($titleParts['primary'])
            );
        }

        $parts = preg_split('/\s+-\s+|\s+\|\s+|\s*:\s*/', $subject, 2);
        if (is_array($parts) && count($parts) === 2) {
            [$first, $second] = array_map(fn (string $part) => trim($part), $parts);

            if ($this->isDynamicContextSegment($first) xor $this->isDynamicContextSegment($second)) {
                $overline = $this->isDynamicContextSegment($first) ? $first : $second;
                $primary = $overline === $first ? $second : $first;

                return sprintf(
                    '<span class="hero-overline">%s</span><span class="hero-title-primary">%s</span>',
                    $this->escapeHtml($overline),
                    $this->escapeHtml(rtrim($primary, '.'))
                );
            }

            return sprintf(
                '<span class="hero-title-primary">%s.</span> <span class="hero-title-accent">%s</span>',
                $this->escapeHtml(rtrim($first, '.')),
                $this->escapeHtml($second)
            );
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $subject, 2);
        if (is_array($sentences) && count($sentences) === 2) {
            return sprintf(
                '<span class="hero-title-primary">%s</span><br><span class="hero-title-accent">%s</span>',
                $this->escapeHtml($sentences[0]),
                $this->escapeHtml($sentences[1])
            );
        }

        return sprintf(
            '<span class="hero-title-primary">%s</span>',
            $this->escapeHtml($subject)
        );
    }

    protected function buildLocationFocusedHeroTitleHtml(MessageTemplate $template, string $subject): ?string
    {
        $parts = $this->matchLocationHeroTitleParts($template, $subject);
        if ($parts === null) {
            return null;
        }

        $html = sprintf(
            '<span class="hero-title-lead">%s</span><span class="hero-title-location">%s</span>',
            $this->escapeHtml($parts['lead']),
            $this->escapeHtml($parts['location'])
        );

        if (($parts['status'] ?? '') !== '') {
            $html .= sprintf(
                '<span class="hero-title-status">%s</span>',
                $this->escapeHtml($parts['status'])
            );
        }

        return $html;
    }

    /**
     * @return array{lead: string, location: string, status?: string}|null
     */
    protected function matchLocationHeroTitleParts(MessageTemplate $template, string $subject): ?array
    {
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? $subject);

        return match ($template->slug) {
            'shoot-scheduled' => $this->captureLocationTitleParts(
                $subject,
                '/^New Shoot Scheduled for\s+(.+)$/i',
                'New Shoot Scheduled for'
            ),
            'shoot-requested' => $this->captureLocationTitleParts(
                $subject,
                '/^New Photo Shoot Requested\s*\((.+?)\)\s*-\s*(.+)$/i',
                'New Photo Shoot Requested',
                locationIndex: 2,
                statusIndex: 1
            ),
            'shoot-request-approved', 'shoot-request-modified' => $this->captureLocationTitleParts(
                $subject,
                '/^New Shoot Scheduled\s*\((.+?)\)\s*-\s*(.+)$/i',
                'New Shoot Scheduled',
                locationIndex: 2,
                statusIndex: 1
            ),
            'shoot-request-declined' => $this->captureLocationTitleParts(
                $subject,
                '/^New Shoot Request\s*\((.+?)\)\s*-\s*(.+)$/i',
                'New Shoot Request',
                locationIndex: 2,
                statusIndex: 1
            ),
            'shoot-reminder' => $this->captureLocationTitleParts(
                $subject,
                '/^Shoot Reminder\s*-\s*(.+)$/i',
                'Shoot Reminder for'
            ),
            'shoot-updated' => $this->captureLocationTitleParts(
                $subject,
                '/^Scheduled Photo Shoot for\s+(.+?)\s+Updated$/i',
                'Scheduled Photo Shoot for',
                status: 'Updated'
            ),
            'shoot-ready' => $this->captureLocationTitleParts(
                $subject,
                '/^(.+?)\s*-\s*Photos Ready!?$/i',
                'Photos Ready for',
                locationIndex: 1
            ),
            'shoot-delivered' => $this->captureLocationTitleParts(
                $subject,
                '/^(.+?)\s*-\s*Shoot Delivered$/i',
                'Shoot Delivered for',
                locationIndex: 1
            ),
            'shoot-summary' => $this->captureLocationTitleParts(
                $subject,
                '/^(.+?)\s*-\s*Summary$/i',
                'Shoot Summary for',
                locationIndex: 1
            ),
            'payment-due-reminder' => $this->captureLocationTitleParts(
                $subject,
                '/^(.+?)\s*-\s*Payment Due Reminder$/i',
                'Payment Due for',
                locationIndex: 1,
                status: 'Reminder'
            ),
            default => null,
        };
    }

    /**
     * @return array{lead: string, location: string, status?: string}|null
     */
    protected function captureLocationTitleParts(
        string $subject,
        string $pattern,
        string $lead,
        int $locationIndex = 1,
        ?int $statusIndex = null,
        ?string $status = null
    ): ?array {
        if (!preg_match($pattern, $subject, $matches)) {
            return null;
        }

        $location = trim((string) ($matches[$locationIndex] ?? ''));
        if ($location === '') {
            return null;
        }

        $resolvedStatus = $status;
        if ($resolvedStatus === null && $statusIndex !== null) {
            $resolvedStatus = trim((string) ($matches[$statusIndex] ?? ''));
        }

        $parts = [
            'lead' => $lead,
            'location' => $location,
        ];

        if ($resolvedStatus !== null && $resolvedStatus !== '') {
            $parts['status'] = $resolvedStatus;
        }

        return $parts;
    }

    protected function buildJourneyRail(MessageTemplate $template): string
    {
        return '';
    }

    protected function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array{overline: string, primary: string}|null
     */
    protected function extractTitleOverline(MessageTemplate $template, string $subject): ?array
    {
        $subject = trim($subject);

        foreach ($this->preferredTitleSuffixes($template) as $suffix) {
            if ($suffix === '' || !str_ends_with($subject, $suffix)) {
                continue;
            }

            $leading = trim(substr($subject, 0, -strlen($suffix)));
            if ($leading === '') {
                continue;
            }

            return [
                'overline' => trim($leading, "-:| \t\n\r\0\x0B"),
                'primary' => $suffix,
            ];
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function preferredTitleSuffixes(MessageTemplate $template): array
    {
        return match ($template->slug) {
            'account-created' => ['New Account Information'],
            'payment-due-reminder' => ['Payment Due Reminder'],
            'shoot-summary' => ['Summary'],
            'shoot-delivered' => ['Shoot Delivered'],
            default => [],
        };
    }

    protected function isDynamicContextSegment(string $segment): bool
    {
        $segment = trim($segment);

        if ($segment === '') {
            return false;
        }

        if (str_contains($segment, '{{') || str_contains($segment, '[')) {
            return true;
        }

        if (preg_match('/\d/', $segment) || str_contains($segment, ',')) {
            return true;
        }

        return str_word_count($segment) >= 4 && strlen($segment) >= 20;
    }

    protected function stripLegacyWrapper(string $html): string
    {
        // Extract the inner template content from known wrapped email documents
        // so the renderer can apply the canonical shared layout.
        if (str_contains($html, 'email-container') && str_contains($html, 'logo-text')) {
            if (preg_match('/<div\s+class=["\']content["\']>\s*(.+)\s*<\/div>\s*<div\s+class=["\']footer["\']/s', $html, $contentMatch)) {
                return trim($contentMatch[1]);
            }
        }

        if (str_contains($html, 'class="ew"') && str_contains($html, 'class="eb"')) {
            if (preg_match('/<div\s+class=["\']eb["\']>\s*(.*)\s*<\/div>\s*<\/div>\s*<\/body>/si', $html, $contentMatch)) {
                return trim($contentMatch[1]);
            }
        }

        if (str_contains($html, 'class="page"') && str_contains($html, 'class="brand-band"')) {
            if (preg_match('/<div\s+class=["\']content["\']>\s*(.*)\s*<\/div>\s*<div\s+class=["\']footer-wrap["\']/si', $html, $contentMatch)) {
                return trim($contentMatch[1]);
            }
        }

        if (str_contains($html, 'class="hero-card"') && str_contains($html, 'class="body-inner"')) {
            if (preg_match('/<div\s+class=["\']body-inner["\']>\s*(.*)\s*<\/div>\s*<div\s+class=["\']footer-wrap["\']/si', $html, $contentMatch)) {
                return trim($contentMatch[1]);
            }
        }

        $trimmed = trim($html);
        if (str_starts_with($trimmed, '<!DOCTYPE') || str_starts_with($trimmed, '<html')) {
            if (preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $bodyMatch)) {
                return trim($bodyMatch[1]);
            }
        }

        return $html;
    }

    protected function normalizeLegacyUrls(string $content): string
    {
        return str_replace(
            ['https://pro.reprohq.com', 'http://pro.reprohq.com'],
            'https://reprodashboard.com',
            $content
        );
    }

    /**
     * @param  string[]  $contents
     * @return string[]
     */
    protected function extractPlaceholderKeys(array $contents): array
    {
        $keys = [];

        foreach ($contents as $content) {
            if (!is_string($content) || $content === '') {
                continue;
            }

            if (!preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}|\[([a-zA-Z0-9_]+)\]/', $content, $matches)) {
                continue;
            }

            $found = array_filter(array_merge($matches[1] ?? [], $matches[2] ?? []));
            foreach ($found as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }
}





