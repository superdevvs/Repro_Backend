<?php

namespace App\Services\Messaging;

class EmailWorkspaceV2Renderer
{
    public const TYPES = ['columns', 'video', 'quote', 'spacer', 'signature', 'faq', 'offer'];

    public function render(array $section, EmailTemplateDocument $document): string
    {
        $type = $section['type'];
        $heading = filled($section['heading'] ?? '') ? '<h2 class="dark-heading" style="margin:0 0 14px;font-size:24px;line-height:1.3;">'.e($section['heading']).'</h2>' : '';
        $design = app(EmailWorkspaceStyles::class);
        $body = isset($section['rich_text']) ? app(EmailWorkspaceRichText::class)->render($section['rich_text']) : $design->connectedFields((string) \Illuminate\Support\Str::markdown($section['body'] ?? '', ['html_input' => 'escape', 'allow_unsafe_links' => false]), $section['body'] ?? '');
        $content = $heading.'<div class="dark-body" style="line-height:1.7;">'.$body.'</div>';
        if ($type === 'columns') {
            $columns = $section['columns'] ?? [];
            $width = (int) floor(100 / max(1, count($columns)));
            $columnPixels = max(1, (int) floor((520 - 2 * ($section['styles']['padding'] ?? 0)) / max(1, count($columns))) - 20);
            $content = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="table-layout:fixed;"><tr>';
            foreach ($columns as $column) {
                $columnHtml = '';
                foreach ($column as $child) {
                    if ($child['type'] === 'feature') { $child['layout'] = 'stacked'; $child['image_width'] = min(120, $child['image_width'] ?? 120); }
                    $part = $document->html([$child]);
                    // Keep fallback pixel dimensions inside the cell too;
                    // responsive CSS alone cannot constrain every mail client.
                    $imagePixels = max(1, $columnPixels - 2 * ($child['styles']['padding'] ?? 0));
                    $part = preg_replace_callback('/(<img\b[^>]*\bwidth=")(\d+)(")/i', fn ($match) => $match[1].min((int) $match[2], $imagePixels).$match[3], $part);
                    if (!isset($child['styles']['font_size'])) $part = str_replace(['font-size:24px;', 'font-size:16px;'], [count($columns) === 3 ? 'font-size:18px;' : 'font-size:20px;', count($columns) === 3 ? 'font-size:14px;' : 'font-size:15px;'], $part);
                    $columnHtml .= $part;
                }
                $content .= '<td class="ews-email-column" width="'.$width.'%" valign="top" style="padding:0 10px;vertical-align:top;">'.$columnHtml.'</td>';
            }
            $content .= '</tr></table>';
        } elseif ($type === 'video') {
            $url = $document->url($section['video_url'] ?? '');
            $poster = filled($section['image_url'] ?? '') ? '<img src="'.e($document->url($section['image_url'], true)).'" alt="'.e($section['alt'] ?? 'Watch video').'" width="520" style="width:100%;max-width:520px;height:auto;border:0;border-radius:12px;display:block;">' : '';
            $content = $heading.'<a href="'.e($url).'">'.$poster.'</a><p style="margin:12px 0;"><a href="'.e($url).'" class="atelier-link">Watch video'.(! empty($section['duration']) ? ' · '.e($section['duration']) : '').'</a></p>'.$body;
        } elseif ($type === 'quote') {
            $content = '<blockquote class="section-card-bg" style="margin:0;padding:24px;border-left:3px solid #195fe6;border-radius:8px;">'.$body.'<p class="dark-heading" style="margin:16px 0 0;font-weight:600;">'.e($section['heading'] ?? '').'</p></blockquote>';
        } elseif ($type === 'spacer') {
            $content = '<div style="height:'.max(8, min(64, $section['spacing'] ?? 24)).'px;line-height:1px;">&nbsp;</div>';
        } elseif ($type === 'offer') {
            $content = '<div class="ws-accent" style="background:#09223c;color:#fff;padding:28px;border-radius:12px;">'.$content.'</div>';
        }
        $styles = $design->bounded($section['styles'] ?? []);
        $content = $design->typography($content, $styles);
        $css = 'text-align:'.($section['alignment'] ?? 'left').';'.$design->wrapper($styles);
        if ($css !== '') $content = '<div style="'.e($css).'">'.$content.'</div>';
        return '<div data-email-section="'.e($section['id']).'" style="padding-bottom:'.max(8, min(48, $section['spacing'] ?? 24)).'px;">'.$content.'</div>';
    }
}
