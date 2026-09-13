<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;
use App\Services\SystemEmails\EditableEmailContent;
use App\Services\SystemEmails\EmailArtwork;
use App\Services\SystemEmails\EmailBrandingConfig;
use App\Support\InvoiceReference;
use App\Support\SupportContact;
use Illuminate\Support\Arr;

class TemplateRenderer
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function render(MessageTemplate $template, array $variables, ?string $previewTheme = null): array
    {
        // Normalize static template copy before inserting any variables. This
        // corrects legacy brand numbers without ever rewriting a client,
        // photographer, access-contact, or SMS-recipient phone value.
        $templateSubject = $this->normalizeLegacyContactDetails((string) ($template->subject ?? ''));
        $templateHtml = $this->normalizeLegacyContactDetails((string) ($template->body_html ?? ''));
        $templateText = $this->normalizeLegacyContactDetails((string) ($template->body_text ?? ''));
        $variables['company_phone'] = SupportContact::PHONE_DISPLAY;

        $availableKeys = collect($template->variables_json ?? []);
        if (! $availableKeys->contains('shoot_changes') && array_key_exists('shoot_changes', $variables)) {
            $availableKeys->push('shoot_changes');
        }
        if (! $availableKeys->contains('shoot_changes_html') && array_key_exists('shoot_changes_html', $variables)) {
            $availableKeys->push('shoot_changes_html');
        }

        $placeholderKeys = $this->extractPlaceholderKeys([
            $templateSubject,
            $templateHtml,
            $templateText,
        ]);
        foreach ($placeholderKeys as $key) {
            if (! $availableKeys->contains($key)) {
                $availableKeys->push($key);
            }
        }

        $available = $availableKeys
            ->mapWithKeys(fn ($var) => [$var => Arr::get($variables, $var, '')]);

        $html = $this->replacePlaceholders($templateHtml, $available->all());
        $text = $this->replacePlaceholders($templateText, $available->all());
        $subject = $this->replacePlaceholders($templateSubject, $available->all());
        $html = $this->normalizeDuplicateInvoiceLabels($html);
        $text = $this->normalizeDuplicateInvoiceLabels($text);
        $subject = $this->normalizeDuplicateInvoiceLabels($subject);
        $html = $this->stripLegacyWrapper($html);
        $html = $this->stripLeadingGreetingArtifacts($html);
        $text = $this->stripLeadingTextGreetingArtifacts($text);

        if ($this->serviceDetailsAlreadyNamePhotographer($variables)) {
            $html = $this->removeDuplicatePhotographerSummaryRows($html);
            $text = $this->removeDuplicatePhotographerTextRows($text);
        }

        $html = $this->normalizeLegacyUrls($html);
        $text = $this->normalizeLegacyUrls($text);
        $subject = $this->normalizeLegacyUrls($subject);
        $html = $this->stripLeadingGreeting($html);
        $html = $this->stripLeadingGreetingArtifacts($html);
        $html = $this->normalizeLegacySuccessColors($html);
        $text = $this->stripLeadingGreetingFromText($text);
        $text = $this->stripLeadingTextGreetingArtifacts($text);

        if ($template->channel === 'EMAIL' && $html !== '') {
            $html = $this->wrapWithLayout($template, $html, $subject, $variables, $previewTheme);
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
        return collect($this->variableKeys($template))
            ->reject(fn ($key) => array_key_exists($key, $variables))
            ->merge(app(EditableEmailContent::class)->missingVariables($template))
            ->values()
            ->all();
    }

    public function variableKeys(MessageTemplate $template): array
    {
        return array_values(array_unique(array_merge($template->variables_json ?? [], $this->extractPlaceholderKeys([
            (string) $template->subject,
            (string) $template->body_html,
            (string) $template->body_text,
        ]))));
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
        // Legacy receipts append the recipient's name even though greeting
        // already contains it. Normalize tokens before inserting user values.
        if (preg_match('/^(?:hi|hello|dear)\s+\S/i', trim((string) ($values['greeting'] ?? '')))) {
            $greeting = '(?:\{\{\s*greeting\s*\}\}|\[greeting\])';
            $first = '(?:\{\{\s*(?:client_first_name|realtor_first)\s*\}\}|\[realtor_first\])';
            $last = '(?:\{\{\s*(?:client_last_name|realtor_last)\s*\}\}|\[realtor_last\])';
            $content = preg_replace('/('.$greeting.')\s*,?\s*'.$first.'(?:\s*'.$last.')?/', '$1', $content) ?? $content;
        }

        foreach (['payment_amount', 'shoot_total'] as $moneyKey) {
            $value = $values[$moneyKey] ?? null;
            if ($value !== null && is_numeric(str_replace(['$', ','], '', (string) $value))) {
                $amount = '$'.number_format((float) str_replace(['$', ','], '', (string) $value), 2);
                $token = '(?:\{\{\s*'.preg_quote($moneyKey, '/').'\s*\}\}|\['.preg_quote($moneyKey, '/').'\])';
                $content = preg_replace_callback('/\$?'.$token.'/', fn () => $amount, $content) ?? $content;
                unset($values[$moneyKey]);
            }
        }

        if (array_key_exists('invoice_number', $values)) {
            $content = $this->replaceInvoiceNumberPlaceholders($content, $values['invoice_number']);
            unset($values['invoice_number']);
        }

        return collect($values)->reduce(
            fn ($carry, $value, $key) => preg_replace_callback(
                '/\{\{\s*'.preg_quote($key, '/').'\s*\}\}|\['.preg_quote($key, '/').'\]/',
                fn () => (string) $value,
                $carry
            ) ?? $carry,
            $content
        );
    }

    protected function replaceInvoiceNumberPlaceholders(string $content, mixed $value): string
    {
        $label = InvoiceReference::label($value);
        $number = InvoiceReference::number($value);
        $placeholder = '(?:\{\{\s*invoice_number\s*\}\}|\[invoice_number\])';
        $spacingOrTag = '(?:\s|&nbsp;|&#160;|&#x0*a0;|<\/?[^>]+>)';
        $literalLabel = '\bInvoice(?:\s+(?:Number|No\.?))?\s*(?:#|:)?'.$spacingOrTag.'*';

        $content = preg_replace_callback(
            '/(?<prefix>'.$literalLabel.')'.$placeholder.'/i',
            fn (array $matches) => ($matches['prefix'] ?? '').$number,
            $content
        ) ?? $content;

        return preg_replace('/'.$placeholder.'/i', $label, $content) ?? $content;
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

    protected function normalizeDuplicateInvoiceLabels(string $content): string
    {
        return preg_replace(
            '/\bInvoice(?:\s|&nbsp;|&#160;|&#x0*a0;)+Invoice(?=\s|&nbsp;|&#160;|&#x0*a0;|<|[:#]|$)/i',
            'Invoice',
            $content
        ) ?? $content;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function wrapWithLayout(MessageTemplate $template, string $bodyHtml, string $subject, array $variables, ?string $previewTheme = null): string
    {
        $bodyHtml = $this->stabilizeEmailBodyHtml($this->stripLegacyWrapper($bodyHtml));

        return view('emails.messaging.atelier', [
            'template' => $template,
            'subject' => $subject,
            'bodyHtml' => $bodyHtml,
            'heroTitleHtml' => $this->buildHeroTitleHtml($template, $subject !== '' ? $subject : ($template->name ?? 'R/E Pro Photos Update')),
            'heroCopy' => $this->resolveHeroCopy($template),
            'preheaderText' => $this->buildPreheaderText($bodyHtml, $template),
            'branding' => $this->branding(),
            'emailAtelier' => app(EmailArtwork::class)->forTemplate($template, $variables),
            'emailFooterNote' => (string) ($variables['email_footer_note'] ?? ''),
            'emailPreviewTheme' => in_array($previewTheme, ['light', 'dark'], true) ? $previewTheme : null,
        ])->render();
    }

    protected function stripLeadingGreeting(string $bodyHtml): string
    {
        return preg_replace('/^\s*<p>\s*(hi|hello)\b.*?<\/p>\s*/is', '', $bodyHtml) ?? $bodyHtml;
    }

    protected function stripLeadingGreetingArtifacts(string $bodyHtml): string
    {
        $cleaned = preg_replace('/^\s*<p\b[^>]*>\s*(?:&nbsp;|\s|!|&#33;)*<\/p>\s*/i', '', $bodyHtml);

        return $cleaned ?? $bodyHtml;
    }

    protected function stabilizeEmailBodyHtml(string $bodyHtml): string
    {
        $branding = $this->branding();
        $headingColor = (string) ($branding['heading_color_light'] ?? $branding['heading_color'] ?? '#14243a');
        $bodyColor = (string) ($branding['body_color_light'] ?? $branding['body_color'] ?? '#33465d');
        $mutedColor = (string) ($branding['muted_color_light'] ?? $branding['muted_color'] ?? '#5d6e84');
        $linkColor = (string) ($branding['link_color_light'] ?? $branding['link_color'] ?? '#155bdd');
        $sectionSurface = (string) ($branding['section_surface_light'] ?? $branding['section_surface'] ?? '#f7fbff');
        $noteSurface = (string) ($branding['note_surface_light'] ?? '#f8fbff');
        $borderColor = (string) ($branding['border_color_light'] ?? $branding['border_color'] ?? 'transparent');

        $tagStyles = [
            'p' => "margin:0 0 16px; color:{$bodyColor}; font-size:14px; line-height:1.7;",
            'li' => "color:{$bodyColor}; font-size:14px; line-height:1.7;",
            'div' => "color:{$bodyColor}; font-size:14px; line-height:1.7;",
            'td' => "color:{$bodyColor}; font-size:14px; line-height:1.7;",
            'span' => "color:{$bodyColor}; font-size:14px; line-height:1.7;",
            'ul' => 'margin:0 0 16px; padding-left:20px;',
            'ol' => 'margin:0 0 16px; padding-left:20px;',
            'h1' => "margin:0 0 16px; color:{$headingColor}; line-height:1.2; font-size:32px; font-weight:500; letter-spacing:-0.8px;",
            'h2' => "margin:0 0 16px; color:{$headingColor}; line-height:1.3; font-size:24px; font-weight:500;",
            'h3' => "margin:0 0 14px; color:{$headingColor}; line-height:1.4; font-size:20px; font-weight:500;",
            'h4' => "margin:0 0 14px; color:{$headingColor}; line-height:1.4; font-size:16px; font-weight:500;",
            'strong' => "color:{$headingColor};",
            'b' => "color:{$headingColor};",
            'center' => 'display:block; text-align:left;',
            'a' => "color:{$linkColor}; text-decoration:none;",
            'hr' => "border:0; border-top:1px solid {$borderColor}; margin:24px 0;",
        ];

        foreach ($tagStyles as $tag => $style) {
            $bodyHtml = $this->injectInlineStyleForTag($bodyHtml, $tag, $style);
        }

        $classStyles = [
            'info-box' => "margin:24px 0; padding:24px; width:100%; box-sizing:border-box; border-radius:12px; border:1px solid {$borderColor}; background-color:{$sectionSurface}; color:{$bodyColor}; box-shadow:none;",
            'info-row' => "padding:12px 0; border-bottom:1px solid {$borderColor};",
            'info-label' => "display:block; min-width:0; margin-bottom:4px; color:{$mutedColor}; font-weight:500; font-size:11px; line-height:1.5; letter-spacing:1px; text-transform:uppercase;",
            'note' => "margin:24px 0; padding:20px 24px; border-radius:12px; border:1px solid {$borderColor}; background-color:{$noteSurface}; color:{$bodyColor} !important;",
            'change-card' => "margin:24px 0; padding:24px; border-radius:12px; border:1px solid {$borderColor}; background-color:{$sectionSurface};",
            'change-card-title' => "margin:0 0 12px; color:{$headingColor}; font-size:18px; line-height:1.4; font-weight:500;",
            'button' => 'display:block; box-sizing:border-box; min-height:54px; padding:16px 24px; border-radius:8px; background-color:#155bdd; background-image:none; color:#ffffff !important; font-weight:500; font-size:14px; line-height:22px; text-align:center; text-decoration:none; margin:16px 0;',
            'button-large' => 'padding:16px 24px; font-size:14px; line-height:22px; letter-spacing:0;',
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

    protected function stripLeadingTextGreetingArtifacts(string $text): string
    {
        return preg_replace('/^\s*!\s*(?:\r?\n)+/', '', $text) ?? $text;
    }

    protected function injectInlineStyleForTag(string $html, string $tag, string $style): string
    {
        return preg_replace_callback(
            sprintf('/<%s\b([^>]*)>/i', preg_quote($tag, '/')),
            function (array $matches) use ($tag, $style): string {
                $openTag = "<{$tag}{$matches[1]}>";
                $semantic = match ($tag) {
                    'h1', 'h2', 'h3', 'h4' => 'dark-heading',
                    'strong', 'b' => 'dark-strong',
                    'p', 'li', 'div', 'td', 'span' => 'dark-body',
                    'a' => 'atelier-link',
                    default => null,
                };
                if ($semantic !== null) {
                    preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $openTag, $classMatch);
                    $classes = preg_split('/\s+/', trim($classMatch[2] ?? '')) ?: [];
                    $hasSemantic = array_intersect($classes, [
                        'dark-title', 'dark-heading', 'dark-strong', 'dark-body', 'dark-muted',
                        'info-label', 'info-value', 'detail-label', 'detail-value', 'legal-copy-dark',
                    ]) !== [];
                    if (! $hasSemantic && ! array_intersect($classes, ['button', 'button-large', 'atelier-button', 'cta-button'])) {
                        $classes[] = in_array('change-card-title', $classes, true) ? 'dark-heading' : $semantic;
                        $attribute = 'class="'.implode(' ', array_filter(array_unique($classes))).'"';
                        $openTag = isset($classMatch[0])
                            ? str_replace($classMatch[0], $attribute, $openTag)
                            : preg_replace('/>$/', ' '.$attribute.'>', $openTag);
                    }
                }

                return $this->appendStyleToOpenTag($openTag, $style);
            },
            $html
        ) ?? $html;
    }

    protected function injectInlineStyleForClass(string $html, string $class, string $style): string
    {
        return preg_replace_callback(
            '/<([a-z0-9]+)\b([^>]*)>/i',
            function (array $matches) use ($class, $style): string {
                $openTag = $matches[0];

                if (! preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $openTag, $classMatch)) {
                    return $openTag;
                }

                $classes = preg_split('/\s+/', trim($classMatch[2])) ?: [];
                if (! in_array($class, $classes, true)) {
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
            $merged = $existing === '' ? $style : rtrim($existing, ';').'; '.$style;
            $fullMatch = $styleMatch[0][0];
            $quote = $styleMatch[1][0];

            return substr_replace(
                $openTag,
                ' style='.$quote.$merged.$quote,
                $styleMatch[0][1],
                strlen($fullMatch)
            );
        }

        $isSelfClosing = (bool) preg_match('/\/>\s*$/', $openTag);
        $trimmedTag = preg_replace('/\s*\/?>\s*$/', '', $openTag) ?? $openTag;

        return $trimmedTag.' style="'.$style.'"'.($isSelfClosing ? ' />' : '>');
    }

    protected function normalizeLegacySuccessColors(string $bodyHtml): string
    {
        return str_ireplace(
            ['#22c55e', '#16a34a', '#15803d', '#f0fdf4', '#dcfce7'],
            ['#1463ff', '#1463ff', '#295391', '#eff6ff', '#dbeafe'],
            $bodyHtml
        );
    }

    protected function buildPreheaderText(string $bodyHtml, MessageTemplate $template): string
    {
        $text = $this->htmlToPreviewText($bodyHtml);
        if ($text === '') {
            $text = $this->resolveHeroCopy($template);
        }

        return $this->limitPreviewText($text);
    }

    protected function htmlToPreviewText(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<\s*(br|hr)\b[^>]*\/?>/i', ' ', $html) ?? $html;
        $html = preg_replace('/<\s*\/\s*(p|div|h[1-6]|li|tr|td|th|table|center|section|article)\b[^>]*>/i', ' ', $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        $text = preg_replace('/^(?:!|&#33;|\.|,|:|;|\||-|–|—)+\s*/u', '', $text) ?? $text;

        return trim($text);
    }

    protected function limitPreviewText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return '';
        }

        $limit = 180;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $limit) {
                return $text;
            }

            return rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')).'...';
        }

        if (strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(substr($text, 0, $limit - 1)).'...';
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

        // The hero status badge (e.g. "Pending", "Updated") was removed from all
        // templates per the request to drop the confusing status bar. The parsed
        // status is intentionally ignored here; only the lead + location render.
        return sprintf(
            '<span class="hero-title-lead">%s</span><span class="hero-title-location">%s</span>',
            $this->escapeHtml($parts['lead']),
            $this->escapeHtml($parts['location'])
        );
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
            'payment-due-reminder' => $this->captureInvoiceReminderTitleParts($subject),
            default => null,
        };
    }

    /**
     * @return array{lead: string, location: string, status?: string}|null
     */
    protected function captureInvoiceReminderTitleParts(string $subject): ?array
    {
        if (preg_match('/^Payment Reminder\s*-\s*Invoice\s+(.+)$/i', $subject, $matches)) {
            $invoiceNumber = trim((string) ($matches[1] ?? ''));
            if ($invoiceNumber !== '') {
                return [
                    'lead' => 'Payment Reminder for',
                    'location' => $this->formatInvoiceTitleLocation($invoiceNumber),
                    'status' => 'Pending',
                ];
            }
        }

        if (preg_match('/^Invoice\s+(.+?)\s*-\s*Payment Reminder$/i', $subject, $matches)) {
            $invoiceNumber = trim((string) ($matches[1] ?? ''));
            if ($invoiceNumber !== '') {
                return [
                    'lead' => 'Payment Reminder for',
                    'location' => $this->formatInvoiceTitleLocation($invoiceNumber),
                    'status' => 'Pending',
                ];
            }
        }

        return null;
    }

    protected function formatInvoiceTitleLocation(string $invoiceNumber): string
    {
        $invoiceNumber = trim($invoiceNumber);

        if (preg_match('/^Invoice\b/i', $invoiceNumber)) {
            return $invoiceNumber;
        }

        return 'Invoice '.$invoiceNumber;
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
        if (! preg_match($pattern, $subject, $matches)) {
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
            if ($suffix === '' || ! str_ends_with($subject, $suffix)) {
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
            'payment-due-reminder' => ['Payment Reminder'],
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

    public function editableBodyHtml(string $html): string
    {
        return $this->stripLegacyWrapper($html);
    }

    protected function stripLegacyWrapper(string $html): string
    {
        $body = $this->extractDocumentBody($html);
        if ($body !== null) {
            return $body;
        }

        // Extract the inner template content from known wrapped email documents
        // so the renderer can apply the canonical shared layout.
        if (str_contains($html, 'email-header') || str_contains($html, 'email-footer')) {
            $stripped = preg_replace('/^\s*<div\b[^>]*class=(["\'])[^"\']*\bemail-header\b[^"\']*\1[^>]*>.*?<\/div>\s*/is', '', $html);
            $stripped = preg_replace('/\s*<div\b[^>]*class=(["\'])[^"\']*\bemail-footer\b[^"\']*\1[^>]*>.*?<\/div>\s*$/is', '', $stripped ?? $html);

            return trim($stripped ?? $html);
        }

        if (str_contains($html, 'email-container') && str_contains($html, 'class="content"') && str_contains($html, 'class="footer"')) {
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

    private function extractDocumentBody(string $html): ?string
    {
        if (! preg_match('/<!doctype\s+html\b|<html\b|<body\b|data-email-content\s*=/i', $html)) {
            return null;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET)) {
                return null;
            }

            $xpath = new \DOMXPath($document);
            $selectors = [
                '//*[@data-email-content]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " body-card ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " email-container ")]//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " ew ")]//*[contains(concat(" ", normalize-space(@class), " "), " eb ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " page ")]//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
                '//body',
            ];

            foreach ($selectors as $selector) {
                $node = $xpath->query($selector)?->item(0);
                if ($node === null) {
                    continue;
                }

                if ($node instanceof \DOMElement && in_array('body-card', preg_split('/\s+/', $node->getAttribute('class')) ?: [], true)) {
                    // Older layouts split the body into padded wrappers around
                    // full-width detail cards. Keep every authored sibling while
                    // dropping that layout-only padding from the editor body.
                    $wrappers = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " body-inner ")]', $node);
                    foreach (iterator_to_array($wrappers) as $wrapper) {
                        $parent = $wrapper->parentNode;
                        while ($wrapper->firstChild !== null) {
                            $parent->insertBefore($wrapper->firstChild, $wrapper);
                        }
                        $parent->removeChild($wrapper);
                    }
                }

                $body = '';
                foreach ($node->childNodes as $child) {
                    $body .= $document->saveHTML($child);
                }

                return trim($body);
            }

            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    protected function normalizeLegacyUrls(string $content): string
    {
        return str_replace(
            ['https://pro.reprohq.com', 'http://pro.reprohq.com'],
            'https://reprodashboard.com',
            $content
        );
    }

    protected function normalizeLegacyContactDetails(string $content): string
    {
        return SupportContact::normalizeReferences($content);
    }

    /**
     * @param  string[]  $contents
     * @return string[]
     */
    protected function extractPlaceholderKeys(array $contents): array
    {
        $keys = [];

        foreach ($contents as $content) {
            if (! is_string($content) || $content === '') {
                continue;
            }

            if (! preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}|\[([a-zA-Z0-9_]+)\]/', $content, $matches)) {
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
