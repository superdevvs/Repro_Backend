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
    private bool $preview = false;

    public function previewing(): bool { return $this->preview; }
    public static function rules(string $prefix = ''): array
    {
        $key = $prefix.'editor_document_json';

        $rules = [
            $key => ['nullable', 'array:version,format,preheader,sections'],
            $key.'.version' => ['required_with:'.$key, 'integer', 'in:1,2'],
            $key.'.format' => ['required_with:'.$key, Rule::in(['sections', 'html', 'legacy'])],
            $key.'.preheader' => ['nullable', 'string', 'max:255'],
            $key.'.sections' => ['sometimes', 'array', 'max:40'],
            $key.'.sections.*' => ['array:id,type,heading,body,image_url,alt,url,alignment,spacing,eyebrow,layout,tone,image_width,image_prompt,artwork_id,artwork_key,rich_text,columns,media_id,video_url,duration,caption,image_link,styles'],
            $key.'.sections.*.id' => ['required', 'string', 'max:150', 'distinct'],
            $key.'.sections.*.type' => ['required', Rule::in(['text', 'tips', 'notice', 'image', 'button', 'divider', 'hero', 'feature', 'steps', 'details', ...EmailWorkspaceV2Renderer::TYPES])],
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
        $section = $key.'.sections.*';
        $extra = [
            'rich_text' => ['nullable', 'array', function ($attribute, $value, $fail) { if (! is_array($value) || ! app(EmailWorkspaceRichText::class)->valid($value)) $fail('This rich text contains unsupported formatting.'); }],
            'media_id' => ['nullable', 'uuid'],
            'video_url' => ['nullable', 'string', 'max:2000'],
            'duration' => ['nullable', 'string', 'max:30'],
            'caption' => ['nullable', 'string', 'max:500'],
            'image_link' => ['nullable', 'string', 'max:2000'],
            'styles' => ['nullable', 'array:color,background,font_size,padding,radius,fit,aspect,focal_x,focal_y'],
            'styles.color' => ['nullable', 'regex:/^#[a-f0-9]{6}$/i'],
            'styles.background' => ['nullable', 'regex:/^#[a-f0-9]{6}$/i'],
            'styles.font_size' => ['nullable', 'integer', 'min:12', 'max:48'],
            'styles.padding' => ['nullable', 'integer', 'min:0', 'max:48'],
            'styles.radius' => ['nullable', 'integer', 'min:0', 'max:24'],
            'styles.fit' => ['nullable', 'in:contain,cover'],
            'styles.aspect' => ['nullable', 'in:auto,1:1,3:2,16:9,12:5'],
            'styles.focal_x' => ['nullable', 'integer', 'min:0', 'max:100'],
            'styles.focal_y' => ['nullable', 'integer', 'min:0', 'max:100'],
            'columns' => ['required_if:'.$section.'.type,columns', 'array', 'min:2', 'max:3'],
            'columns.*' => ['array', 'min:1', 'max:8'],
        ];
        foreach ($extra as $field => $rule) $rules[$section.'.'.$field] = $rule;
        foreach (array_keys($rules) as $field) {
            if (str_starts_with($field, $section.'.') && ! str_contains(substr($field, strlen($section)), '.columns')) {
                $rules[str_replace($section, $section.'.columns.*.*', $field)] = $rules[$field];
            }
        }
        $rules[$section.'.columns.*.*'] = $rules[$section];
        $rules[$section.'.columns.*.*.type'] = ['required', Rule::in(['text','hero','feature','tips','steps','details','notice','image','button','divider','video','quote','spacer','signature','faq','offer'])];
        $rules[$section.'.columns.*.*.columns'] = ['prohibited'];
        return $rules;
    }

    public function prepare(array $data, ?MessageTemplate $original = null, bool $preview = false): array
    {
        $document = $data['editor_document_json'] ?? null;
        if ($document !== null) {
            Validator::make(['editor_document_json' => $document], self::rules())->validate();
        }
        if (isset($document['sections']) && strlen(json_encode($document)) > 250000) throw ValidationException::withMessages(['editor_document_json' => 'Keep the email document below 250 KB.']);
        $all = $document['sections'] ?? [];
        foreach ($document['sections'] ?? [] as $section) foreach ($section['columns'] ?? [] as $column) $all = array_merge($all, $column);
        if (count($all) > 120 || count(array_unique(array_column($all, 'id'))) !== count($all)) throw ValidationException::withMessages(['editor_document_json' => 'Use unique section IDs and no more than 120 content blocks.']);
        if (($document['format'] ?? null) === 'sections') {
            if ($original && app(EditableEmailContent::class)->blocks($original) !== []) {
                throw ValidationException::withMessages(['editor_document_json' => 'Edit the connected message sections; the automated layout must stay connected.']);
            }
            $this->preview = $preview;
            try { $data['body_html'] = $this->html($document['sections'] ?? []); } finally { $this->preview = false; }
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
        if ($url === '' && $this->preview && ! $image) return '#';
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
