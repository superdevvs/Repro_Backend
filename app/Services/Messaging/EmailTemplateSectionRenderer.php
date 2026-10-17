<?php

namespace App\Services\Messaging;

use Illuminate\Support\Str;

/** Email-safe editorial layouts. All content is escaped; only inline emphasis and safe links are rendered. */
class EmailTemplateSectionRenderer
{
    public const ARTWORK = ['', 'editing', 'invoice', 'invoice_pending_approval', 'invoice_approved', 'payment', 'schedule', 'delivery_access', 'welcome'];

    public function render(array $sections, EmailTemplateDocument $document): string
    {
        $html = '';
        foreach ($sections as $section) {
            $type = $section['type'];
            $align = in_array($section['alignment'] ?? '', ['left', 'center', 'right'], true) ? $section['alignment'] : 'left';
            $spacing = max(8, min(48, (int) ($section['spacing'] ?? 24)));
            $title = e($section['heading'] ?? '');
            $heading = $title !== '' ? '<'.($type === 'hero' ? 'h1' : 'h2').' class="'.($type === 'hero' ? 'dark-title ws-headline' : 'dark-heading').'" style="margin:0 0 16px;font-size:'.($type === 'hero' ? 36 : 24).'px;line-height:1.25;letter-spacing:-.6px;font-weight:600;">'.$title.'</'.($type === 'hero' ? 'h1' : 'h2').'>' : '';
            $eyebrow = filled($section['eyebrow'] ?? '') ? '<p class="dark-muted ws-eyebrow" style="margin:0 0 14px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:1.6px;text-transform:uppercase;">'.e($section['eyebrow']).'</p>' : '';
            $body = $this->paragraphs((string) ($section['body'] ?? ''));
            $width = max(80, min(280, (int) ($section['image_width'] ?? 160)));
            $hasImage = filled($section['image_url'] ?? '');
            $image = $hasImage ? '<img src="'.e($document->url($section['image_url'], true)).'" alt="'.e($section['alt'] ?? '').'" width="'.($type === 'feature' ? $width : 520).'" style="display:block;width:100%;max-width:'.($type === 'feature' ? $width : 520).'px;height:auto;margin:0 auto;border:0;">' : '';
            $copy = $eyebrow.$heading.$body;
            if ($type === 'image' && ! $hasImage) $document->url('', true);
            $content = match ($type) {
                'hero' => $copy.($hasImage ? '<div style="padding:12px 0 8px;">'.$image.'</div>' : ''),
                'feature' => $this->feature($copy, $image, $section['layout'] ?? 'image-left', $width),
                'image' => $image,
                'button' => '<p style="margin:8px 0;"><a class="atelier-button" href="'.e($document->url($section['url'] ?? '')).'" style="display:inline-block;background:#195fe6;color:#fff;padding:16px 24px;border-radius:8px;text-decoration:none;font-weight:600;">'.($title ?: 'Learn more').'</a></p>',
                'divider' => '<hr style="border:0;border-top:1px solid #dce3ed;margin:0;">',
                'tips' => $eyebrow.$heading.'<ul style="padding-left:20px;margin:0;">'.implode('', array_map(fn ($line) => '<li class="dark-body" style="padding:0 0 12px;line-height:1.7;">'.$this->inline($line).'</li>', $this->lines($section))).'</ul>',
                'steps' => $eyebrow.$heading.$this->rows($section, true),
                'details' => $eyebrow.$heading.$this->rows($section, false),
                default => $copy,
            };
            $tone = $section['tone'] ?? (in_array($type, ['notice', 'steps', 'details'], true) ? 'soft' : 'plain');
            if ($tone !== 'plain') {
                $content = '<div class="'.($tone === 'accent' ? 'ws-accent' : 'section-card-bg').'" style="padding:24px;border-radius:16px;background:'.($tone === 'accent' ? '#09223c' : '#f5f7fa').';">'.$content.'</div>';
            }
            $html .= '<div data-email-section="'.e($section['id']).'" data-email-layout="'.e($section['layout'] ?? 'stacked').'" style="text-align:'.$align.';padding-bottom:'.$spacing.'px;">'.$content.'</div>';
        }

        return $html;
    }

    private function feature(string $copy, string $image, string $layout, int $width): string
    {
        if ($image === '' || $layout === 'stacked') {
            return ($image !== '' ? '<div style="padding-bottom:24px;">'.$image.'</div>' : '').$copy;
        }
        $left = $layout !== 'image-right';
        $imageCell = '<td class="ws-feature-image" width="'.$width.'" valign="top" style="width:'.$width.'px;vertical-align:top;padding-'.($left ? 'right' : 'left').':24px;">'.$image.'</td>';
        $textCell = '<td class="ws-feature-copy" valign="middle" style="vertical-align:middle;">'.$copy.'</td>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;"><tr>'.($left ? $imageCell.$textCell : $textCell.$imageCell).'</tr></table>';
    }

    private function lines(array $section): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) ($section['body'] ?? ''))), fn ($line) => $line !== ''));
    }

    private function rows(array $section, bool $numbered): string
    {
        $html = '';
        foreach ($this->lines($section) as $index => $line) {
            if ($numbered) {
                $html .= '<tr><td width="36" valign="top" style="width:36px;padding:0 12px 16px 0;color:#195fe6;font-size:16px;font-weight:600;">'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'</td><td class="dark-body" style="padding:0 0 16px;line-height:1.7;">'.$this->inline($line).'</td></tr>';
            } else {
                [$label, $value] = array_pad(explode('|', $line, 2), 2, '');
                $html .= '<tr><td style="padding:0 0 20px;"><p class="dark-muted" style="margin:0 0 6px;font-size:11px;letter-spacing:1px;text-transform:uppercase;">'.e(trim($label)).'</p><p class="dark-strong" style="margin:0;font-size:16px;line-height:1.6;font-weight:600;">'.$this->inline(trim($value)).'</p></td></tr>';
            }
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'.$html.'</table>';
    }

    private function paragraphs(string $text): string
    {
        return implode('', array_map(fn ($paragraph) => '<p class="dark-body" style="margin:0 0 16px;font-size:16px;line-height:1.7;">'.$this->inline($paragraph).'</p>', preg_split('/\n\s*\n/', trim($text)) ?: []));
    }

    private function inline(string $text): string
    {
        return preg_replace('/<img\b[^>]*>/i', '', nl2br((string) Str::inlineMarkdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]), false)) ?? '';
    }
}
