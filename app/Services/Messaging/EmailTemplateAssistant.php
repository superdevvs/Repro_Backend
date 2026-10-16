<?php

namespace App\Services\Messaging;

use App\Models\MessageTemplate;
use App\Services\ReproAi\LlmClient;
use App\Services\SystemEmails\EditableEmailContent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmailTemplateAssistant
{
    public function __construct(private readonly LlmClient $llm) {}

    public function suggest(array $draft, string $instruction, string $scope, ?string $sectionId, ?MessageTemplate $template): array
    {
        $blocks = $template ? app(EditableEmailContent::class)->blocks($template) : [];
        $document = $draft['editor_document_json'] ?? ['version' => 1, 'format' => 'legacy', 'preheader' => '', 'sections' => []];
        $sections = $document['sections'] ?? [];
        $ids = array_merge(array_column($sections, 'id'), array_column($blocks, 'key'));
        if ($scope === 'section' && ! in_array($sectionId, $ids, true)) {
            throw ValidationException::withMessages(['section_id' => 'Select an editable section first.']);
        }
        $context = ['instruction' => $instruction, 'scope' => $scope, 'selected_section' => $sectionId,
            'draft' => $draft, 'editable_blocks' => $blocks];
        $response = $this->llm->chatCompletion([
            ['role' => 'system', 'content' => <<<'PROMPT'
You are Robbie, the email design assistant for R/E Pro Photos. Return a proposed revision, never send or save it.
Follow the user's request using their facts. Do not invent dates, prices, links, contact details, offers or claims.
Keep all {{personalization_fields}} exactly intact. Stay warm, clear and concise, using existing wording where appropriate.
For sections format, compose useful editable sections: text, tips (one tip per line), notice, image, button, divider.
Preserve existing section IDs unless adding a section. Use plain text in headings/body. Keep existing image URLs; never invent image assets.
Use Atelier editorial styling: calm navy/blue, generous whitespace, concise headings. The application supplies the email layout.
For HTML format, preserve the document head, CSS, tables, conditional comments, links and original design; edit requested content only.
For legacy templates with editable_blocks, change the wording in blocks using their key as id; do not replace the live wrapper or loops.
Respect scope: subject changes subject/preheader only; section changes only the selected section/block; email may change the full editable message.
Return all schema fields. Use empty arrays/strings for fields that do not apply. No markdown or commentary.
PROMPT],
            ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ], [], false, ['temperature' => 0.4, 'usage_feature' => 'email_assistant', 'max_tokens' => 6500,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'email_template_revision', 'strict' => true, 'schema' => $this->schema()]]]);
        $proposal = json_decode((string) ($response['choices'][0]['message']['content'] ?? ''), true);
        if (! is_array($proposal) || ! is_string($proposal['subject'] ?? null) || ! is_array($proposal['sections'] ?? null)) {
            throw new RuntimeException('Robbie did not return a valid revision.');
        }
        $result = $draft;
        if ($scope !== 'section') {
            $result['subject'] = mb_substr($proposal['subject'], 0, 255);
            $document['preheader'] = mb_substr((string) ($proposal['preheader'] ?? ''), 0, 255);
        }
        if ($scope !== 'subject') {
            if ($blocks !== []) {
                $updates = [];
                foreach ($proposal['blocks'] ?? [] as $block) {
                    $original = collect($blocks)->firstWhere('key', $block['id'] ?? '');
                    if (! $original || ($scope === 'section' && $original['key'] !== $sectionId)) {
                        continue;
                    }
                    $this->keepFields($original['body_html'], $block['body_html']);
                    $updates[$original['key']] = Arr::only($block, ['body_html', 'body_text']);
                }
                $result['content_blocks_json'] = array_replace($draft['content_blocks_json'] ?? [], $updates);
            } elseif ($document['format'] === 'sections') {
                if ($scope === 'section') {
                    $replacement = collect($proposal['sections'])->firstWhere('id', $sectionId);
                    if (! $replacement) {
                        throw new RuntimeException('The selected section was not returned.');
                    }
                    $document['sections'] = array_map(fn ($section) => $section['id'] === $sectionId ? $replacement : $section, $sections);
                } else {
                    $document['sections'] = $proposal['sections'];
                }
                $this->keepFields(json_encode($sections), json_encode($document['sections']));
            } else {
                $this->keepFields((string) ($draft['body_html'] ?? ''), (string) ($proposal['body_html'] ?? ''));
                $result['body_html'] = (string) ($proposal['body_html'] ?? '');
                $result['body_text'] = (string) ($proposal['body_text'] ?? '');
            }
        }
        $result['editor_document_json'] = $document;
        Validator::make($result, array_merge(EmailTemplateDocument::rules(), ['subject' => ['required', 'string', 'max:255']]))->validate();

        return app(EmailTemplateDocument::class)->prepare($result, $template);
    }

    private function keepFields(string $original, string $candidate): void
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $original, $matches);
        foreach ($matches[1] as $key) {
            if (! preg_match('/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/', $candidate)) {
                throw ValidationException::withMessages(['instruction' => 'Robbie removed a connected field. Your draft is unchanged; ask for a narrower edit.']);
            }
        }
    }

    private function schema(): array
    {
        $text = ['type' => 'string'];
        $section = array_fill_keys(['id', 'heading', 'body', 'image_url', 'alt', 'url'], $text);
        $section['type'] = ['type' => 'string', 'enum' => ['text', 'tips', 'notice', 'image', 'button', 'divider']];
        $section['alignment'] = ['type' => 'string', 'enum' => ['left', 'center', 'right']];
        $section['spacing'] = ['type' => 'integer'];
        $object = fn (array $properties) => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];

        return $object(['subject' => $text, 'preheader' => $text,
            'sections' => ['type' => 'array', 'items' => $object($section)],
            'body_html' => $text, 'body_text' => $text,
            'blocks' => ['type' => 'array', 'items' => $object(['id' => $text, 'body_html' => $text, 'body_text' => $text])]]);
    }
}
