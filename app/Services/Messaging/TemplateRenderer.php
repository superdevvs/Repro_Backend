<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;
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

        $html = $this->normalizeLegacyUrls($html);
        $text = $this->normalizeLegacyUrls($text);
        $subject = $this->normalizeLegacyUrls($subject);

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

    protected function wrapWithLayout(MessageTemplate $template, string $bodyHtml, string $subject): string
    {
        $bodyHtml = $this->stripLegacyWrapper($bodyHtml);

        $logoUrl = 'https://api.reprodashboard.com/images/Repro%20HQ%20dark.png';
        $heroEyebrow = $this->escapeHtml($this->resolveHeroEyebrow($template));
        $heroCopy = $this->escapeHtml($this->resolveHeroCopy($template));
        $heroTitle = $this->buildHeroTitleHtml($subject !== '' ? $subject : ($template->name ?? 'R/E Pro Photos Update'));
        $journeyHtml = $this->buildJourneyRail($template);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
body {
  margin: 0;
  padding: 0;
  background: linear-gradient(180deg, #f7f9fc 0%, #eef3f8 100%);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
  color: #10233b;
  -webkit-text-size-adjust: 100%;
  word-break: break-word;
}
table { border-collapse: collapse; width: 100%; }
img { border: 0; display: block; max-width: 100%; }
a { color: #1463ff; text-decoration: none; }
.page { padding: 30px 12px; }
.shell { max-width: 720px; margin: 0 auto; }
.hero-card,
.body-card {
  background-color: #ffffff;
  border-radius: 34px;
  overflow: hidden;
  box-shadow: 0 24px 70px rgba(22, 34, 60, 0.09);
  border: 1px solid rgba(222, 230, 241, 0.7);
}
.hero-card {
  position: relative;
  padding: 34px 38px 28px;
}
.brand-row {
  display: flex;
  align-items: center;
  gap: 14px;
  position: relative;
  z-index: 2;
  margin-bottom: 28px;
}
.brand-logo {
  display: inline-block;
}
.brand-logo img {
  width: 154px;
  height: auto;
}
.brand-copy {
  color: #1d2940;
  font-size: 16px;
  line-height: 1.4;
  font-weight: 800;
}
.brand-copy span {
  display: block;
  color: #7f90a7;
  font-size: 11px;
  letter-spacing: 1.4px;
  text-transform: uppercase;
  font-weight: 700;
}
.hero-title {
  position: relative;
  z-index: 2;
  margin: 0;
  max-width: 520px;
  font-size: 56px;
  line-height: 0.96;
  font-weight: 300;
  letter-spacing: -2.4px;
  color: #10192f;
}
.hero-title-primary {
  display: inline;
  color: #10192f;
}
.hero-title-accent {
  display: inline;
  color: #3164ea;
  font-weight: 400;
}
.hero-copy {
  position: relative;
  z-index: 2;
  max-width: 540px;
  margin: 20px 0 0;
  color: #667a96;
  font-size: 15px;
  line-height: 1.8;
}
.hero-orbit {
  position: absolute;
  top: 40px;
  right: 20px;
  width: 224px;
  height: 224px;
  border-radius: 999px;
  border: 1px solid #ebeff5;
}
.hero-orbit:before,
.hero-orbit:after {
  content: "";
  position: absolute;
  inset: 28px;
  border-radius: 999px;
  border: 1px solid #ebeff5;
}
.hero-orbit:after {
  inset: 56px;
}
.hero-gridline-h,
.hero-gridline-v {
  position: absolute;
  background: #eef2f7;
}
.hero-gridline-h {
  left: 0;
  right: 0;
  top: 50%;
  height: 1px;
  margin-top: -1px;
}
.hero-gridline-v {
  top: 0;
  bottom: 0;
  left: 50%;
  width: 1px;
  margin-left: -1px;
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
  background: #dce5f3;
}
.journey-bar:last-child {
  margin-right: 0;
}
.journey-bar-complete {
  background: #3164ea;
}
.journey-bar-next {
  background: #7e9ff1;
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
  color: #94a4bc;
  font-weight: 800;
}
.journey-label-complete {
  color: #3164ea;
}
.journey-label-next {
  color: #4d5f7c;
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
  color: #405875;
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
  color: #0f1930;
  line-height: 1.15;
}
.body-inner h1 { font-size: 42px; font-weight: 300; letter-spacing: -1.6px; }
.body-inner h2 { font-size: 28px; font-weight: 800; }
.body-inner h3 { font-size: 22px; font-weight: 800; }
.body-inner h4 { font-size: 16px; font-weight: 800; }
.body-inner strong { color: #0f1930; }
.body-inner center { display: block; text-align: left; }
.body-inner hr {
  border: 0;
  border-top: 1px solid #edf2f7;
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
.info-box {
  margin: 20px 0;
  padding: 18px 20px;
  border-radius: 22px;
  border: 1px solid #dfe7f2;
  background: linear-gradient(180deg, #fbfcfe 0%, #f4f7fb 100%);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
}
.info-row {
  padding: 10px 0;
  border-bottom: 1px solid #e4edf8;
}
.info-row:last-child { border-bottom: 0; }
.info-label {
  display: inline-block;
  min-width: 150px;
  color: #93a4bd;
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
  border: 1px solid #f0d7a8;
  background: linear-gradient(180deg, #fff9ee 0%, #fff3df 100%);
  color: #8b5b14 !important;
}
.footer-wrap { padding: 18px 0 0; }
.footer-card {
  border-radius: 26px;
  background: linear-gradient(135deg, #0b1b30 0%, #102847 100%);
  padding: 24px 26px;
  color: #dce8ff;
  box-shadow: 0 20px 40px rgba(16, 40, 71, 0.18);
}
.footer-title {
  margin: 0 0 8px;
  font-size: 18px;
  line-height: 1.5;
  color: #ffffff;
  font-weight: 800;
}
.footer-copy {
  margin: 0;
  color: #dce8ff;
  font-size: 14px;
  line-height: 1.8;
}
.footer-copy a { color: #ffffff !important; text-decoration: underline; }
.footer-links a { margin-right: 14px; white-space: nowrap; }
.footer-meta {
  width: 100%;
  margin-top: 18px;
}
.footer-meta-cell {
  width: 50%;
  padding-right: 12px;
}
.footer-meta-card {
  border-radius: 18px;
  padding: 14px 16px;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(221, 232, 255, 0.14);
}
.footer-meta-label {
  display: block;
  margin-bottom: 4px;
  color: #9fb4d4;
  font-size: 11px;
  line-height: 1.4;
  letter-spacing: 1.2px;
  text-transform: uppercase;
  font-weight: 800;
}
.footer-meta-value {
  color: #ffffff;
  font-size: 14px;
  line-height: 1.6;
  font-weight: 700;
}
.footer-note {
  padding: 14px 8px 0;
  text-align: center;
  color: #7d90ab;
  font-size: 11px;
  line-height: 1.7;
}
@media only screen and (max-width: 640px) {
  .page { padding: 14px 8px !important; }
  .hero-card { padding: 24px 22px !important; }
  .body-inner, .footer-card { padding-left: 20px !important; padding-right: 20px !important; }
  .hero-title {
    font-size: 38px !important;
    letter-spacing: -1.6px !important;
    max-width: none !important;
  }
  .hero-copy { max-width: none !important; }
  .hero-orbit {
    position: absolute !important;
    width: 160px !important;
    height: 160px !important;
    right: -26px !important;
    top: 24px !important;
  }
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
}
</style>
</head>
<body>
<div class="page">
  <div class="shell">
    <div class="hero-card">
      <div class="hero-orbit">
        <div class="hero-gridline-h"></div>
        <div class="hero-gridline-v"></div>
      </div>
      <div class="brand-row">
        <div class="brand-logo">
          <img src="{$logoUrl}" alt="R/E Pro Photos">
        </div>
        <div class="brand-copy">
          <span>{$heroEyebrow}</span>
          R/E Pro Photos
        </div>
      </div>
      <h1 class="hero-title">{$heroTitle}</h1>
      <p class="hero-copy">{$heroCopy}</p>
{$journeyHtml}
    </div>
    <div class="body-card">
      <div class="body-inner">
{$bodyHtml}
      </div>
      <div class="footer-wrap">
        <div class="footer-card">
          <div class="footer-title">Need help with a shoot, invoice, or account question?</div>
          <p class="footer-copy">
            Our team is here to help keep your marketing workflow moving.
            Reach us at <a href="mailto:contact@reprophotos.com">contact@reprophotos.com</a> or call 202-868-1663.
          </p>
          <p class="footer-copy footer-links" style="margin-top:14px;">
            <a href="https://reprodashboard.com">Dashboard</a>
            <a href="https://reprophotos.com">Website</a>
            <a href="https://www.google.com/maps/place/R%2FE+Pro+Photos/reviews">Leave a Review</a>
          </p>
          <table class="footer-meta" role="presentation">
            <tr>
              <td class="footer-meta-cell">
                <div class="footer-meta-card">
                  <span class="footer-meta-label">Support</span>
                  <span class="footer-meta-value">contact@reprophotos.com<br>202-868-1663</span>
                </div>
              </td>
              <td class="footer-meta-cell" style="padding-right:0;">
                <div class="footer-meta-card">
                  <span class="footer-meta-label">Portal</span>
                  <span class="footer-meta-value">Track your shoots, invoices, and delivery updates in one place.</span>
                </div>
              </td>
            </tr>
          </table>
        </div>
        <div class="footer-note">
          This email was sent by R/E Pro Photos. Please keep this message for your records if it relates to a scheduled shoot, payment, or invoice.
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
HTML;
    }

    protected function resolveHeroEyebrow(MessageTemplate $template): string
    {
        return match ($template->category) {
            'ACCOUNT' => 'Account update',
            'BOOKING' => 'Booking update',
            'REMINDER' => 'Reminder',
            'PAYMENT' => 'Payment update',
            'INVOICE' => 'Invoice update',
            default => 'Workflow update',
        };
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

    protected function buildHeroTitleHtml(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return '<span class="hero-title-primary">R/E Pro Photos update.</span>';
        }

        $parts = preg_split('/\s+-\s+|\s+\|\s+|\s*:\s*/', $subject, 2);
        if (is_array($parts) && count($parts) === 2) {
            return sprintf(
                '<span class="hero-title-primary">%s.</span> <span class="hero-title-accent">%s</span>',
                $this->escapeHtml(rtrim($parts[0], '.')),
                $this->escapeHtml($parts[1])
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

    protected function buildJourneyRail(MessageTemplate $template): string
    {
        $definition = match ($template->slug) {
            'payment-thank-you', 'payment-due-reminder' => [
                'labels' => ['Payment', 'Editing', 'Quality Check', 'Delivery'],
                'active' => 0,
            ],
            'shoot-ready', 'shoot-summary' => [
                'labels' => ['Payment', 'Editing', 'Quality Check', 'Delivery'],
                'active' => 3,
            ],
            'shoot-scheduled', 'shoot-requested', 'shoot-request-approved', 'shoot-request-modified', 'shoot-reminder', 'shoot-updated' => [
                'labels' => ['Booking', 'Scheduled', 'Editing', 'Delivery'],
                'active' => 1,
            ],
            default => null,
        };

        if (!$definition) {
            return '';
        }

        $bars = [];
        $labels = [];

        foreach ($definition['labels'] as $index => $label) {
            $barClass = 'journey-bar';
            $labelClass = 'journey-label';

            if ($index <= $definition['active']) {
                $barClass .= ' journey-bar-complete';
                $labelClass .= ' journey-label-complete';
            } elseif ($index === $definition['active'] + 1) {
                $barClass .= ' journey-bar-next';
                $labelClass .= ' journey-label-next';
            }

            $bars[] = sprintf('<span class="%s"></span>', $barClass);
            $labels[] = sprintf('<td class="%s">%s</td>', $labelClass, $this->escapeHtml($label));
        }

        return sprintf(
            '<div class="journey"><div class="journey-bars">%s</div><table class="journey-labels" role="presentation"><tr>%s</tr></table></div>',
            implode('', $bars),
            implode('', $labels)
        );
    }

    protected function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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





