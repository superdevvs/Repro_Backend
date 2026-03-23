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
            $html = $this->wrapWithLayout($html);
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

    protected function wrapWithLayout(string $bodyHtml): string
    {
        $bodyHtml = $this->stripLegacyWrapper($bodyHtml);

        $logoUrl = 'https://api.reprodashboard.com/images/Repro%20HQ%20dark.png';

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
  background-color: #eef3f8;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
  color: #10233b;
  -webkit-text-size-adjust: 100%;
  word-break: break-word;
}
table { border-collapse: collapse; width: 100%; }
img { border: 0; display: block; max-width: 100%; }
a { color: #1463ff; text-decoration: none; }
.page { padding: 28px 12px; }
.shell { max-width: 680px; margin: 0 auto; }
.card {
  background-color: #ffffff;
  border-radius: 28px;
  overflow: hidden;
  box-shadow: 0 22px 60px rgba(13, 35, 67, 0.12);
}
.brand-band {
  background: linear-gradient(135deg, #071223 0%, #0b2242 55%, #123e83 100%);
  padding: 22px 28px 18px;
}
.brand-meta {
  font-size: 11px;
  letter-spacing: 1.6px;
  text-transform: uppercase;
  color: #d6e4ff;
}
.brand-chip {
  display: inline-block;
  margin-top: 10px;
  background-color: #ffffff;
  border-radius: 18px;
  padding: 16px 22px;
  box-shadow: 0 14px 32px rgba(2, 13, 30, 0.2);
}
.brand-chip img { width: 176px; height: auto; }
.content { padding: 28px 28px 18px; }
.content p,
.content li,
.content div,
.content td,
.content span {
  color: #35506f;
  font-size: 15px;
  line-height: 1.75;
}
.content p { margin: 0 0 14px; }
.content ul,
.content ol {
  margin: 0 0 16px;
  padding-left: 20px;
}
.content h1,
.content h2,
.content h3,
.content h4 {
  margin: 0 0 14px;
  color: #071223;
  line-height: 1.25;
}
.content h1 { font-size: 31px; font-weight: 800; }
.content h2 { font-size: 24px; font-weight: 800; }
.content h3 { font-size: 19px; font-weight: 800; }
.content h4 { font-size: 16px; font-weight: 800; }
.content strong { color: #071223; }
.content center { display: block; text-align: left; }
.content hr {
  border: 0;
  border-top: 1px solid #edf2f7;
  margin: 18px 0;
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
  margin: 18px 0;
  padding: 18px 20px;
  border-radius: 20px;
  border: 1px solid #dbe7f8;
  background: linear-gradient(180deg, #f8fbff 0%, #f2f8ff 100%);
}
.info-row {
  padding: 10px 0;
  border-bottom: 1px solid #e4edf8;
}
.info-row:last-child { border-bottom: 0; }
.info-label {
  display: inline-block;
  min-width: 150px;
  color: #60799a;
  font-weight: 800;
  font-size: 12px;
  line-height: 1.5;
  letter-spacing: 1.2px;
  text-transform: uppercase;
}
.note {
  margin: 18px 0;
  padding: 16px 18px;
  border-radius: 18px;
  border: 1px solid #ffdcae;
  background: linear-gradient(180deg, #fff8ef 0%, #fff3e3 100%);
  color: #8b5b14 !important;
}
.footer-wrap { padding: 0 28px 28px; }
.footer-card {
  border-radius: 22px;
  background: linear-gradient(135deg, #0b1b30 0%, #102847 100%);
  padding: 22px;
  color: #dce8ff;
}
.footer-title {
  margin: 0 0 8px;
  font-size: 15px;
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
.footer-note {
  padding: 14px 8px 0;
  text-align: center;
  color: #7d90ab;
  font-size: 11px;
  line-height: 1.7;
}
@media only screen and (max-width: 640px) {
  .page { padding: 14px 8px !important; }
  .brand-band, .content, .footer-wrap, .footer-card { padding-left: 18px !important; padding-right: 18px !important; }
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
    <div class="card">
      <div class="brand-band">
        <div class="brand-meta">R/E Pro Photos | Premium real estate media</div>
        <div class="brand-chip">
          <img src="{$logoUrl}" alt="R/E Pro Photos">
        </div>
      </div>
      <div class="content">
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





