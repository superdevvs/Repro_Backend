<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;
use App\Services\SystemEmails\EditableEmailContent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Editable sections are data, never executable Blade or client-supplied layout code. */
class EmailTemplateDocument
{
    public static function rules(string $prefix = ''): array
    {
        $key = $prefix.'editor_document_json';

        return [
            $key => ['nullable', 'array:version,format,preheader,sections'],
            $key.'.version' => ['required_with:'.$key, 'integer', 'in:1'],
            $key.'.format' => ['required_with:'.$key, Rule::in(['sections', 'html', 'legacy'])],
            $key.'.preheader' => ['nullable', 'string', 'max:255'],
            $key.'.sections' => ['sometimes', 'array', 'max:40'],
            $key.'.sections.*' => ['array:id,type,heading,body,image_url,alt,url,alignment,spacing'],
            $key.'.sections.*.id' => ['required', 'string', 'max:150', 'distinct'],
            $key.'.sections.*.type' => ['required', Rule::in(['text', 'tips', 'notice', 'image', 'button', 'divider'])],
            $key.'.sections.*.heading' => ['nullable', 'string', 'max:500'],
            $key.'.sections.*.body' => ['nullable', 'string', 'max:20000'],
            $key.'.sections.*.image_url' => ['nullable', 'string', 'max:2000'],
            $key.'.sections.*.alt' => ['nullable', 'string', 'max:500'],
            $key.'.sections.*.url' => ['nullable', 'string', 'max:2000'],
            $key.'.sections.*.alignment' => ['sometimes', Rule::in(['left', 'center', 'right'])],
            $key.'.sections.*.spacing' => ['sometimes', 'integer', 'min:8', 'max:48'],
        ];
    }

    public function prepare(array $data, ?MessageTemplate $original = null): array
    {
        $document = $data['editor_document_json'] ?? null;
        if ($document !== null) {
            Validator::make(['editor_document_json' => $document], self::rules())->validate();
        }
        if (($document['format'] ?? null) === 'sections') {
            if ($original && app(EditableEmailContent::class)->blocks($original) !== []) {
                throw ValidationException::withMessages(['editor_document_json' => 'Edit the connected message sections; the automated layout must stay connected.']);
            }
            $data['body_html'] = $this->html($document['sections'] ?? []);
            $data['body_text'] = EditableEmailContent::plainText($data['body_html']);
        }
        if ($original && app(EditableEmailContent::class)->blocks($original) !== [] && isset($data['body_html'])) {
            preg_match_all('/\{\{\s*([a-z_]+_html)\s*\}\}/', (string) $original->body_html, $matches);
            foreach ($matches[1] as $key) {
                if (! preg_match('/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/', $data['body_html'])) {
                    throw ValidationException::withMessages(['body_html' => 'Keep {{'.$key.'}} so recipient-specific content remains connected.']);
                }
            }
        }

        return $data;
    }

    public function html(array $sections): string
    {
        $html = '';
        foreach ($sections as $section) {
            $align = in_array($section['alignment'] ?? '', ['left', 'center', 'right'], true) ? $section['alignment'] : 'left';
            $spacing = max(8, min(48, (int) ($section['spacing'] ?? 24)));
            $body = nl2br(e($section['body'] ?? ''), false);
            $heading = filled($section['heading'] ?? '') ? '<h2 style="margin:0 0 12px;font-size:22px;line-height:1.3;">'.e($section['heading']).'</h2>' : '';
            $content = match ($section['type']) {
                'image' => '<img src="'.e($this->url($section['image_url'] ?? '', true)).'" alt="'.e($section['alt'] ?? '').'" width="520" style="display:block;width:100%;max-width:520px;height:auto;margin:0 auto;">',
                'button' => '<p style="margin:8px 0;"><a class="atelier-button" href="'.e($this->url($section['url'] ?? '')).'" style="display:inline-block;background:#195fe6;color:#fff;padding:14px 24px;border-radius:7px;text-decoration:none;font-weight:600;">'.e(($section['heading'] ?? '') ?: 'Learn more').'</a></p>',
                'divider' => '<hr style="border:0;border-top:1px solid #dce3ed;margin:0;">',
                'tips' => $heading.'<ul style="padding-left:20px;margin:0;">'.implode('', array_map(fn ($line) => '<li style="padding:0 0 12px;">'.e($line).'</li>', array_filter(explode("\n", $section['body'] ?? '')))).'</ul>',
                'notice' => '<div class="info-box" style="padding:20px;border:1px solid #dce3ed;border-radius:8px;">'.$heading.'<p style="margin:0;">'.$body.'</p></div>',
                default => $heading.'<p style="margin:0;line-height:1.75;">'.$body.'</p>',
            };
            $html .= '<div data-email-section="'.e($section['id']).'" style="text-align:'.$align.';padding-bottom:'.$spacing.'px;">'.$content.'</div>';
        }

        return $html;
    }

    public function url(string $url, bool $image = false): string
    {
        $url = trim($url);
        if (preg_match('/^\{\{\s*[a-z_]+\s*\}\}$/i', $url)) {
            return $url;
        }
        $schemes = $image ? ['https', 'http'] : ['https', 'http', 'mailto', 'tel'];
        if ($url === '' || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), $schemes, true)) {
            throw ValidationException::withMessages(['editor_document_json' => $image ? 'Use a complete HTTPS image URL.' : 'Add a valid link to the button.']);
        }

        return $url;
    }
}
