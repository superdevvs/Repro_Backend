<?php

namespace App\Services\SystemEmails;

use DOMDocument;
use DOMElement;

/** Presentation of existing semantic email blocks, preserving their live content. */
class EmailPresentation
{
    public static function palette(?string $theme = null): array
    {
        return $theme === 'dark'
            ? ['canvas' => '#080f17', 'paper' => '#121e2c', 'surface' => '#1b2a3e', 'ink' => '#f1f5fc', 'body' => '#bdccdf', 'muted' => '#8da5c4', 'accent' => '#9cbdff', 'border' => '#2c425e']
            : ['canvas' => '#edf2f7', 'paper' => '#ffffff', 'surface' => '#f5f7fa', 'ink' => '#14243a', 'body' => '#465971', 'muted' => '#5d6e84', 'accent' => '#195fe6', 'border' => '#dce3ed'];
    }

    public static function format(string $html, ?string $theme = null, bool $hero = false, bool $moveButtons = true): string
    {
        if (trim($html) === '') {
            return $html;
        }
        $colors = self::palette($theme);
        $document = new DOMDocument('1.0', 'UTF-8');
        $errors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="atelier-fragment">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $root = $document->getElementById('atelier-fragment');
        if (! $root) {
            return $html;
        }
        foreach ($root->getElementsByTagName('*') as $node) {
            $classes = preg_split('/\s+/', $node->getAttribute('class'));
            $has = static fn (array $names): bool => count(array_intersect($classes, $names)) > 0;
            $styles = [];
            if ($node->tagName === 'a' && $has(['atelier-link'])) {
                $styles['color'] = $colors['accent'];
            }
            if ($has(['dark-title', 'dark-heading', 'dark-strong', 'info-value', 'detail-value'])) {
                $styles['color'] = $colors['ink'];
                $styles['font-weight'] = '600';
            } elseif ($has(['dark-muted', 'info-label', 'detail-label'])) {
                $styles['color'] = $colors['muted'];
            } elseif ($has(['dark-body'])) {
                $styles['color'] = $colors['body'];
                $styles['font-size'] = $hero ? '16px' : '14px';
                $styles['line-height'] = $hero ? '26px' : '24px';
            }
            if ($has(['section-card-bg', 'stat-card-bg', 'note-card-bg', 'callout-bg', 'callout-success-bg', 'callout-warning-bg', 'callout-danger-bg', 'info-box', 'change-card'])) {
                $styles += ['background-color' => $colors['surface'], 'background-image' => 'none', 'border' => '0', 'border-radius' => '12px', 'padding' => '24px'];
                $node->setAttribute('bgcolor', $colors['surface']);
            }
            if ($has(['detail-border'])) {
                $styles['border-bottom'] = '0';
            }
            if ($has(['detail-label-td', 'detail-value-td'])) {
                $node->removeAttribute('width');
                $styles += ['display' => 'block', 'width' => '100%', 'box-sizing' => 'border-box', 'border' => '0'];
                if ($has(['detail-label-td'])) {
                    $styles = array_replace($styles, ['padding' => '16px 0 4px', 'font-size' => '11px', 'line-height' => '16px', 'letter-spacing' => '1.4px', 'text-transform' => 'uppercase', 'font-weight' => '600']);
                } else {
                    $styles = array_replace($styles, ['padding' => '0', 'font-size' => '14px', 'line-height' => '22px', 'font-weight' => '600']);
                }
            }
            if ($hero && $has(['hero-title-td', 'dark-title'])) {
                $styles = array_replace($styles, ['margin' => '0', 'font-size' => '36px', 'line-height' => '42px', 'font-weight' => '500', 'letter-spacing' => '-1.2px']);
            }
            if ($hero && $has(['dark-muted'])) {
                $styles = array_replace($styles, ['color' => $colors['accent'], 'margin' => '0 0 16px', 'font-size' => '11px', 'line-height' => '16px', 'font-weight' => '600', 'letter-spacing' => '1.4px']);
            }
            if ($node->tagName === 'a' && (preg_match('/background(?:-color)?\s*:/i', $node->getAttribute('style')) || $has(['button', 'cta-button', 'btn-primary', 'atelier-button']))) {
                $node->setAttribute('class', trim($node->getAttribute('class').' atelier-button'));
                $styles = array_replace($styles, ['display' => 'block', 'box-sizing' => 'border-box', 'width' => '100%', 'padding' => '16px', 'border-radius' => '8px', 'background-color' => '#155bdd', 'background-image' => 'none', 'color' => '#ffffff', 'font-size' => '14px', 'line-height' => '22px', 'font-weight' => '600', 'text-align' => 'center', 'text-decoration' => 'none']);
                if ($node->parentNode instanceof DOMElement && $node->parentNode->tagName === 'td') {
                    self::style($node->parentNode, ['background-color' => 'transparent', 'border-radius' => '8px']);
                    $node->parentNode->removeAttribute('bgcolor');
                }
            }
            self::style($node, $styles);
        }
        // Only standalone CTA tables move; onboarding step labels and content tables retain order.
        if (! $hero && $moveButtons) {
            $buttons = [];
            foreach (iterator_to_array($root->childNodes) as $child) {
                if (! $child instanceof DOMElement || $child->tagName !== 'table') {
                    continue;
                }
                $links = iterator_to_array($child->getElementsByTagName('a'));
                $text = preg_replace('/\s+/', '', $child->textContent);
                $linkText = preg_replace('/\s+/', '', implode('', array_map(fn ($link) => $link->textContent, $links)));
                if ($links && $text === $linkText && collect($links)->every(fn ($link) => str_contains($link->getAttribute('class'), 'atelier-button'))) {
                    $child->setAttribute('width', '100%');
                    self::style($child, ['width' => '100%', 'margin' => '24px 0 0']);
                    $buttons[] = $child;
                }
            }
            foreach ($buttons as $button) {
                $root->appendChild($button);
            }
        }

        return implode('', array_map(fn ($child) => $document->saveHTML($child), iterator_to_array($root->childNodes)));
    }

    private static function style(DOMElement $node, array $changes): void
    {
        if (! $changes) {
            return;
        }
        $style = $node->getAttribute('style');
        foreach ($changes as $property => $value) {
            $style = preg_replace('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:[^;]*(?:;|$)/i', ';', $style);
            $style = rtrim($style, '; ').';'.$property.':'.$value.';';
        }
        $node->setAttribute('style', ltrim($style, ';'));
    }
}
