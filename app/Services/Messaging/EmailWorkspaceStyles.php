<?php

namespace App\Services\Messaging;

use DOMDocument;
use DOMElement;

/** Explicit workspace choices take precedence over the default email palette.
 * Inline rich-text colors and explicitly styled cards retain their own choices. */
class EmailWorkspaceStyles
{
    private const ADAPTIVE_TEXT = ['dark-title', 'dark-heading', 'dark-strong', 'dark-body', 'dark-muted', 'info-value', 'detail-value', 'info-label', 'detail-label'];

    public function bounded(array $styles): array
    {
        $result = [];
        foreach (['color', 'background'] as $key) if (is_string($styles[$key] ?? null) && preg_match('/^#[a-f0-9]{6}$/iD', $styles[$key])) $result[$key] = $styles[$key];
        foreach (['font_size' => [12,48], 'padding' => [0,48], 'radius' => [0,24], 'focal_x' => [0,100], 'focal_y' => [0,100]] as $key => [$min, $max]) {
            if (isset($styles[$key]) && filter_var($styles[$key], FILTER_VALIDATE_INT) !== false && $styles[$key] >= $min && $styles[$key] <= $max) $result[$key] = (int) $styles[$key];
        }
        foreach (['fit' => ['contain','cover'], 'aspect' => ['auto','1:1','3:2','16:9','12:5']] as $key => $values) if (in_array($styles[$key] ?? null, $values, true)) $result[$key] = $styles[$key];
        return $result;
    }

    public function wrapper(array $styles): string
    {
        $css = '';
        foreach (['color'=>'color', 'background'=>'background-color', 'font_size'=>'font-size', 'padding'=>'padding', 'radius'=>'border-radius'] as $key => $property) {
            if (isset($styles[$key])) $css .= $property.':'.$styles[$key].(is_int($styles[$key]) ? 'px' : '').';';
        }
        return $css;
    }

    public function typography(string $html, array $styles): string
    {
        $styles = $this->bounded($styles);
        if (! isset($styles['color']) && ! isset($styles['font_size'])) return $html;
        $document = new DOMDocument('1.0', 'UTF-8');
        $errors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="email-workspace-style-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $root = $document->getElementById('email-workspace-style-root');
        if (! $root) return $html;
        $visit = function (DOMElement $node, bool $ownColor = false, bool $ownSize = false) use (&$visit, $styles): void {
            $classes = array_values(array_filter(preg_split('/\s+/', trim($node->getAttribute('class'))) ?: []));
            $headingTag = preg_match('/^h[1-6]$/D', $node->tagName);
            $heading = $headingTag || array_intersect($classes, ['dark-title','dark-heading']);
            $adaptiveBody = in_array('dark-body', $classes, true);
            $label = (bool) array_intersect($classes, ['ws-eyebrow','dark-muted','info-label','detail-label']);
            // A rich text span's color is an intentional inline selection. A
            // previously styled child section must also win over its row.
            $ownColor = $ownColor || $node->hasAttribute('data-email-style-color') || ($node->tagName === 'span' && preg_match('/(?:^|;)\s*color\s*:/i', $node->getAttribute('style')));
            $ownSize = $ownSize || $node->hasAttribute('data-email-style-size');
            $changes = [];
            if ($headingTag && $adaptiveBody) {
                $classes = array_values(array_diff($classes, ['dark-body']));
                $classes[] = 'dark-heading';
                if (! preg_match('/(?:^|;)\s*font-size\s*:/i', $node->getAttribute('style'))) $changes['font-size'] = ['h1'=>36,'h2'=>24,'h3'=>20,'h4'=>16,'h5'=>14,'h6'=>12][$node->tagName].'px';
            }
            if (isset($styles['color']) && ! $ownColor && in_array($node->tagName, ['h1','h2','h3','h4','h5','h6','p','li','dd','dt','div','td','th','blockquote'], true)) {
                $changes['color'] = $styles['color'].' !important';
                $node->setAttribute('data-email-style-color', $styles['color']);
                // EmailPresentation and the dark palette intentionally adapt
                // these classes. Explicit colors are no longer adaptive.
                $classes = array_values(array_diff($classes, self::ADAPTIVE_TEXT));
                if ($heading && ! preg_match('/(?:^|;)\s*font-weight\s*:/i', $node->getAttribute('style'))) $changes['font-weight'] = '600';
                if ($adaptiveBody && ! $heading && ! isset($styles['font_size']) && ! preg_match('/(?:^|;)\s*font-size\s*:/i', $node->getAttribute('style'))) $changes['font-size'] = '16px';
            }
            if (isset($styles['font_size']) && ! $ownSize && ! $heading && ! $label && in_array($node->tagName, ['p','li','dd','td','div'], true)) {
                $changes['font-size'] = $styles['font_size'].'px !important';
                $changes['line-height'] = '1.7';
                $node->setAttribute('data-email-style-size', (string) $styles['font_size']);
                // The presentation pass otherwise replaces dark-body size.
                $classes = array_values(array_diff($classes, ['dark-body']));
            }
            if ($changes) {
                if ($classes) $node->setAttribute('class', implode(' ', $classes)); else $node->removeAttribute('class');
                $style = $node->getAttribute('style');
                foreach ($changes as $property => $value) {
                    $style = preg_replace('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:[^;]*(?:;|$)/i', ';', $style);
                    $style = rtrim($style, '; ').';'.$property.':'.$value.';';
                }
                $node->setAttribute('style', ltrim($style, ';'));
            }
            foreach (iterator_to_array($node->childNodes) as $child) if ($child instanceof DOMElement) $visit($child, $ownColor, $ownSize);
        };
        foreach (iterator_to_array($root->childNodes) as $child) if ($child instanceof DOMElement) $visit($child);
        return implode('', array_map(fn ($child) => $document->saveHTML($child), iterator_to_array($root->childNodes)));
    }

    public function connectedFields(string $html, string $source): string
    {
        preg_match_all('/\{\{\s*[a-zA-Z0-9_.]+\s*\}\}/', $source, $fields);
        foreach (array_unique($fields[0]) as $field) $html = str_ireplace(rawurlencode($field), $field, $html);
        return $html;
    }
}
