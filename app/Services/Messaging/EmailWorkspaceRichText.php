<?php

namespace App\Services\Messaging;

/** Render the deliberately small editor vocabulary; never trust HTML or styles
 * supplied by the editor or an LLM. Unknown nodes are inert, never executable. */
class EmailWorkspaceRichText
{
    public function valid(array $node, int $depth = 0, int &$count = 0): bool
    {
        if ($depth > 12 || ++$count > 1200 || ! in_array($node['type'] ?? '', ['doc','text','paragraph','heading','hardBreak','bulletList','orderedList','listItem','blockquote'], true)) return false;
        if (isset($node['text']) && (! is_string($node['text']) || mb_strlen($node['text']) > 20000)) return false;
        foreach (['attrs','marks','content'] as $field) if (isset($node[$field]) && ! is_array($node[$field])) return false;
        foreach ($node['marks'] ?? [] as $mark) if (! is_array($mark) || ! in_array($mark['type'] ?? '', ['bold','italic','underline','strike','link','textStyle'], true) || (isset($mark['attrs']) && ! is_array($mark['attrs']))) return false;
        foreach ($node['marks'] ?? [] as $mark) foreach (['href','color'] as $field) if (isset($mark['attrs'][$field]) && ! is_string($mark['attrs'][$field])) return false;
        foreach ($node['content'] ?? [] as $child) if (! is_array($child) || ! $this->valid($child, $depth + 1, $count)) return false;
        return true;
    }

    public function render(array $node, int $depth = 0): string
    {
        if ($depth > 12) return '';
        $type = $node['type'] ?? '';
        if ($type === 'text') {
            $html = e(mb_substr((string) ($node['text'] ?? ''), 0, 20000));
            foreach ($node['marks'] ?? [] as $mark) {
                $html = match ($mark['type'] ?? '') {
                    'bold' => '<strong>'.$html.'</strong>',
                    'italic' => '<em>'.$html.'</em>',
                    'underline' => '<u>'.$html.'</u>',
                    'strike' => '<s>'.$html.'</s>',
                    'link' => $this->link($html, $mark['attrs']['href'] ?? ''),
                    'textStyle' => preg_match('/^#[a-f0-9]{6}$/i', $mark['attrs']['color'] ?? '') ? '<span style="color:'.$mark['attrs']['color'].';">'.$html.'</span>' : $html,
                    default => $html,
                };
            }
            return $html;
        }
        if ($type === 'hardBreak') return '<br>';
        $body = implode('', array_map(fn ($child) => is_array($child) ? $this->render($child, $depth + 1) : '', array_slice($node['content'] ?? [], 0, 300)));
        $tag = match ($type) {
            'paragraph' => 'p', 'heading' => 'h'.max(2, min(4, (int) ($node['attrs']['level'] ?? 2))),
            'bulletList' => 'ul', 'orderedList' => 'ol', 'listItem' => 'li', 'blockquote' => 'blockquote', default => null,
        };
        $align = in_array($node['attrs']['textAlign'] ?? '', ['left', 'center', 'right'], true) ? $node['attrs']['textAlign'] : 'left';
        $heading = $type === 'heading';
        $typography = $heading ? 'font-size:'.match ($tag) { 'h2' => 28, 'h3' => 24, default => 20 }.'px;line-height:1.3;font-weight:600;' : 'line-height:1.7;';
        return $tag ? '<'.$tag.' class="'.($heading ? 'dark-heading' : 'dark-body').'" style="margin:0 0 14px;'.$typography.'text-align:'.$align.';">'.$body.'</'.$tag.'>' : $body;
    }

    private function link(string $html, string $url): string
    {
        try { $url = app(EmailTemplateDocument::class)->url($url); }
        catch (\Throwable) { return $html; }
        return '<a href="'.e($url).'" class="atelier-link">'.$html.'</a>';
    }
}
