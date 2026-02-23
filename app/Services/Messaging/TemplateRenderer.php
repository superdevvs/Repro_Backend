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

        $logoUrl = 'https://api.reprodashboard.com/images/repro-logo.png';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
:root { color-scheme: light dark; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; -webkit-text-size-adjust: 100%; }
.ew { max-width: 600px; margin: 0 auto; padding: 20px; }
.eh { text-align: center; padding: 24px 20px; background-color: #1a1a2e !important; border-radius: 12px 12px 0 0; border-bottom: 2px solid #e5e7eb; }
.eh img { max-width: 200px; height: auto; }
.eb { background-color: #ffffff; padding: 30px 24px; border-radius: 0 0 12px 12px; }
.eb p { margin-bottom: 14px; color: #333; }
.eb a { color: #2563eb; }
.eb h3, .eb h4 { color: #1a1a1a; }
.eb table { width: 100%; }
@media (prefers-color-scheme: dark) {
  body { background-color: #1a1a2e !important; color: #e0e0e0 !important; }
  .eh { background-color: #1a1a2e !important; }
  .eb { background-color: #1e293b !important; }
  .eb p, .eb li, .eb td, .eb span { color: #cbd5e1 !important; }
  .eb h3, .eb h4, .eb strong { color: #f1f5f9 !important; }
  .eb a { color: #60a5fa !important; }
}
[data-ogsc] .eh { background-color: #1a1a2e !important; }
[data-ogsc] .eb { background-color: #1e293b !important; }
[data-ogsc] .eb p { color: #cbd5e1 !important; }
</style>
</head>
<body>
<div class="ew">
<!--[if mso]>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="background-color:#1a1a2e;padding:24px 20px;">
<img src="{$logoUrl}" alt="REPRO Photos" width="200" style="max-width:200px;height:auto;">
</td></tr></table>
<![endif]-->
<!--[if !mso]><!-->
<div class="eh" style="background-color:#1a1a2e !important;">
<img src="{$logoUrl}" alt="REPRO Photos" style="max-width:200px;height:auto;">
</div>
<!--<![endif]-->
<div class="eb">
{$bodyHtml}
</div>
</div>
</body>
</html>
HTML;
    }

    protected function stripLegacyWrapper(string $html): string
    {
        // If the HTML contains the old email-container wrapper with logo-text header,
        // extract just the inner content + footer to avoid double-wrapping.
        if (str_contains($html, 'email-container') && str_contains($html, 'logo-text')) {
            // Extract content between <div class="content"> and <div class="footer">
            // Use greedy match since content div contains nested divs
            if (preg_match('/<div\s+class=["\']content["\']>\s*(.+)\s*<\/div>\s*<div\s+class=["\']footer["\']/s', $html, $contentMatch)) {
                $innerContent = trim($contentMatch[1]);
                // Also grab the footer inner HTML
                if (preg_match('/<div\s+class=["\']footer["\']>(.*)<\/div>\s*<\/div>\s*<\/body>/s', $html, $footerMatch)) {
                    return $innerContent . "\n" . '<div style="background-color:#f8f8f8;padding:30px 24px;text-align:center;color:#666;font-size:13px;line-height:1.8;border-top:1px solid #e5e7eb;margin-top:20px;">' . trim($footerMatch[1]) . '</div>';
                }
                return $innerContent;
            }
        }

        // Strip full HTML document wrapper (user pasted a full <!DOCTYPE> template)
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





