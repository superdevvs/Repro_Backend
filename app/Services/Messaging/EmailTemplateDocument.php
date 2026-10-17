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
            $key.'.sections.*' => ['array:id,type,heading,body,image_url,alt,url,alignment,spacing,eyebrow,layout,tone,image_width,image_prompt,artwork_id,artwork_key'],
            $key.'.sections.*.id' => ['required', 'string', 'max:150', 'distinct'],
            $key.'.sections.*.type' => ['required', Rule::in(['text', 'tips', 'notice', 'image', 'button', 'divider', 'hero', 'feature', 'steps', 'details'])],
            $key.'.sections.*.heading' => ['nullable', 'string', 'max:500'],
            $key.'.sections.*.body' => ['nullable', 'string', 'max:20000'],
            $key.'.sections.*.image_url' => ['nullable', 'string', 'max:2000'],
            $key.'.sections.*.alt' => ['nullable', 'string', 'max:500'],
            $key.'.sections.*.url' => ['nullable', 'string', 'max:2000'],
            $key.'.sections.*.alignment' => ['sometimes', Rule::in(['left', 'center', 'right'])],
            $key.'.sections.*.spacing' => ['sometimes', 'integer', 'min:8', 'max:48'],
            $key.'.sections.*.eyebrow' => ['nullable', 'string', 'max:150'],
            $key.'.sections.*.layout' => ['sometimes', Rule::in(['stacked', 'image-left', 'image-right'])],
            $key.'.sections.*.tone' => ['sometimes', Rule::in(['plain', 'soft', 'accent'])],
            $key.'.sections.*.image_width' => ['sometimes', 'integer', 'min:80', 'max:280'],
            $key.'.sections.*.image_prompt' => ['nullable', 'string', 'max:2000'],
            $key.'.sections.*.artwork_id' => ['nullable', 'uuid'],
            $key.'.sections.*.artwork_key' => ['nullable', Rule::in(EmailTemplateSectionRenderer::ARTWORK)],
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
        return app(EmailTemplateSectionRenderer::class)->render($sections, $this);
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
