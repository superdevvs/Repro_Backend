<?php

namespace App\Services\SystemEmails;

use App\Models\MessageTemplate;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Editable copy inside trusted, recipient-scoped Blade control flow. */
class EditableEmailContent
{
    private static array $catalogs = [];

    private static array $blocks = [];

    public function viewFor(MessageTemplate $template): ?string
    {
        foreach (DirectEmailTemplates::definitions() as $view => $definition) {
            if ($template->slug === $definition['slug']) {
                return $view;
            }
        }
        if ($template->email_type && app(EmailTypeRegistry::class)->has($template->email_type)) {
            return app(EmailTypeRegistry::class)->definition($template->email_type)->templateView;
        }

        return null;
    }

    public function blocks(MessageTemplate $template): array
    {
        $view = $this->viewFor($template);
        if (! $view || ! $this->usesRuntimeContent($template)) {
            return [];
        }
        $catalog = $this->catalog($view);
        $overrides = $template->content_blocks_json ?? [];

        return array_values(array_map(function (string $key) use ($overrides): array {
            $block = self::$blocks[$key];
            unset($block['expressions']);
            foreach (['body_html', 'body_text'] as $field) {
                if (isset($overrides[$key]) && array_key_exists($field, $overrides[$key])) {
                    $block[$field] = (string) $overrides[$key][$field];
                    if ($field === 'body_html') {
                        $block[$field] = app(TemplateRenderer::class)->editableBodyHtml($block[$field]);
                    }
                }
            }

            return $block;
        }, $catalog['keys']));
    }

    private function usesRuntimeContent(MessageTemplate $template): bool
    {
        if (ProtectedEmailTemplates::hasScopedRuntimeBlock($template)) {
            return true;
        }
        $definition = DirectEmailTemplates::definitions()[$this->viewFor($template) ?? ''] ?? null;
        if (! $definition) {
            return false;
        }
        $variable = trim($definition['body_html'], '{}');

        return preg_match('/\{\{\s*'.preg_quote($variable, '/').'\s*\}\}|\['.preg_quote($variable, '/').'\]/', (string) $template->body_html) === 1;
    }

    public function editableSubject(MessageTemplate $template): string
    {
        $subject = (string) $template->subject;
        if (! preg_match('/^\s*\{\{\s*(?:system_subject|email_subject)\s*\}\}\s*$/', $subject)) {
            return $subject;
        }
        $view = $this->viewFor($template);
        if ($view && preg_match('/@section\(\s*[\'"]title[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/s', $this->source($view), $match)) {
            return $match[2];
        }

        return (string) $template->name;
    }

    public function footerNote(string $view, array $data): string
    {
        if (preg_match('/@section\(\s*[\'"]footer_note[\'"]\s*\)(.*?)@endsection/s', $this->source($view), $match)) {
            return trim(Blade::render($match[1], $data));
        }

        return '';
    }

    public function missingVariables(MessageTemplate $template): array
    {
        $missing = [];
        foreach ($this->blocks($template) as $block) {
            foreach (['body_html', 'body_text'] as $field) {
                preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $block[$field], $matches);
                foreach (array_diff($matches[1], $block['variables_json']) as $variable) {
                    $missing[] = $block['key'].'.'.$variable;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    public static function upgradeDefaultDescriptions(): void
    {
        foreach (DirectEmailTemplates::definitions() as $definition) {
            $variable = trim($definition['body_html'], '{}');
            MessageTemplate::where('channel', 'EMAIL')->where('slug', $definition['slug'])
                ->where('description', 'Editable copy with a live content block. Keep {{'.$variable.'}} to include the recipient-specific details.')
                ->update(['description' => $definition['description']]);
        }
        MessageTemplate::where('channel', 'EMAIL')->where('is_system', true)
            ->whereIn('email_type', app(EmailTypeRegistry::class)->protectedAliases())
            ->where('description', 'Keep {{system_body_html}} for the live recipient-specific details. Enable the protected override to apply edited copy.')
            ->update(['description' => 'Edit the message sections and enable the protected override to apply saved copy. Recipient-specific fields and conditional sections stay connected to current data.']);
    }

    public function render(string $view, array $data, MessageTemplate $template, string $mode = 'html'): string
    {
        $catalog = $this->catalog($view);
        $data['__editableEmailTemplate'] = $template;
        $data['__editableEmailMode'] = $mode;
        // Inline trusted views do not run the named email view composer.
        $data['emailAtelier'] ??= app(EmailArtwork::class)->forView($view, $data);

        return Blade::render($catalog['source'], $data);
    }

    public function renderIncluded(MessageTemplate $template, string $mode, array $scope, string $view, array $data = []): string
    {
        return $this->render($view, array_merge($scope, $data), $template, $mode);
    }

    /** Values are evaluated only by trusted repository expressions, inside their original loop/condition. */
    public function renderBlock(string $key, array $values, MessageTemplate $template, string $mode): string
    {
        $block = self::$blocks[$key] ?? throw new InvalidArgumentException('Unknown email copy block.');
        $field = $mode === 'text' ? 'body_text' : 'body_html';
        $overrides = $template->content_blocks_json ?? [];
        $content = array_key_exists($field, $overrides[$key] ?? []) ? (string) $overrides[$key][$field] : $block[$field];
        if ($mode === 'html') {
            $content = app(TemplateRenderer::class)->editableBodyHtml($content);
        }
        $content = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $match) use ($values, $mode): string {
            $value = (string) ($values[$match[1]] ?? '');

            return $mode === 'text' ? html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
        }, $content) ?? $content;

        // Database content is output, never Blade/PHP source. Text still traverses
        // the canonical layout so all row/section boundaries remain available.
        if ($mode === 'text' && preg_match('/^(<(\w+)\b[^>]*>)/s', $block['body_html'], $tag)) {
            return $tag[1].nl2br(e($content)).'</'.$tag[2].'>';
        }

        return $content;
    }

    public static function plainText(string $html): string
    {
        $html = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<\/(?:p|div|tr|h[1-6]|li|table)>|<br\s*\/?>/i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function source(string $view): string
    {
        if (! preg_match('/^emails\.[a-zA-Z0-9_.-]+$/', $view) || str_contains($view, '..')) {
            throw new InvalidArgumentException('Unknown email view.');
        }

        return file_get_contents(resource_path('views/'.str_replace('.', '/', $view).'.blade.php'));
    }

    private function catalog(string $view): array
    {
        if (isset(self::$catalogs[$view])) {
            return self::$catalogs[$view];
        }
        $source = $this->source($view);
        $keys = [];
        $process = function (string $fragment) use ($view, &$keys): string {
            $replacements = [];
            // A complete paragraph (including its inline links/strong text) is one
            // editable unit. Table cells are included only when they contain no
            // nested paragraphs, rows or tables, preserving dynamic report rows.
            $pattern = '~<(p|h[1-6]|li|td|th)\b[^>]*>(?:(?!<(?:p|h[1-6]|li|td|th|table|tr)\b).)*?</\1>~si';
            $replace = function (array $match) use ($view, &$keys, &$replacements): string {
                $original = $match[0];
                if (preg_match('/@(?:if|else|foreach|forelse|php|include|isset|empty|unless|switch|end)/', $original)) {
                    return $original;
                }
                if (preg_replace('/[\s\x{00a0}]+/u', '', html_entity_decode(strip_tags($original))) === '') {
                    return $original;
                }
                $key = 'copy_'.substr(hash('sha256', $view.'|'.preg_replace('/\s+/', ' ', $original)), 0, 20);
                $expressions = [];
                $body = preg_replace_callback('/\{!!\s*(.*?)\s*!!\}|\{\{\s*(.*?)\s*\}\}/s', function (array $echo) use (&$expressions): string {
                    $raw = str_starts_with($echo[0], '{!!');
                    $expression = trim($raw ? $echo[1] : $echo[2]);
                    $name = $this->variableName($expression, count($expressions) + 1);
                    while (isset($expressions[$name]) && $expressions[$name] !== [$expression, $raw]) {
                        $name .= '_value';
                    }
                    $expressions[$name] = [$expression, $raw];

                    return '{{'.$name.'}}';
                }, $original);
                $text = self::plainText($body);
                $label = Str::limit(preg_replace('/\s+/', ' ', $text), 100);
                if (preg_match('/^\{\{(\w+)\}\}$/', trim($label), $labelVariable)) {
                    $label = Str::headline($labelVariable[1]);
                }
                self::$blocks[$key] = [
                    'key' => $key, 'label' => $label, 'body_html' => $body,
                    'body_text' => $text, 'variables_json' => array_keys($expressions),
                    'section' => 'content', 'source_view' => $view, 'expressions' => $expressions,
                ];
                $keys[] = $key;
                $values = [];
                foreach ($expressions as $name => [$expression, $raw]) {
                    $values[] = var_export($name, true).' => '.($raw ? '(string) ('.$expression.')' : 'e('.$expression.')');
                }
                $marker = 'EMAIL_COPY_BLOCK_'.count($replacements).'_'.substr($key, 5);
                $replacements[$marker] = '<?php echo app(\\App\\Services\\SystemEmails\\EditableEmailContent::class)->renderBlock('.var_export($key, true).', ['.implode(', ', $values).'], $__editableEmailTemplate, $__editableEmailMode); ?>';

                return $marker;
            };
            $fragment = preg_replace_callback($pattern, $replace, $fragment);
            $fragment = preg_replace_callback('~<a\b[^>]*>.*?</a>~si', $replace, $fragment);
            $fragment = strtr($fragment, $replacements);

            return $this->instrumentIncludes($fragment, $keys);
        };
        if (str_contains($source, "@section('content')")) {
            $source = preg_replace_callback('/(@section\(\s*[\'"]content[\'"]\s*\))(.*?)(@endsection)/s', fn ($match) => $match[1].$process($match[2]).$match[3], $source);
        } else {
            $source = $process($source);
        }

        return self::$catalogs[$view] = ['source' => $source, 'keys' => array_values(array_unique($keys))];
    }

    private function instrumentIncludes(string $source, array &$keys): string
    {
        return preg_replace_callback('/@include\((?<args>(?:[^()]+|\((?&args)\))*)\)/s', function ($match) use (&$keys): string {
            if (! preg_match('/^\s*([\'"])(emails\.[a-zA-Z0-9_.-]+)\1/', $match['args'], $view)) {
                return $match[0];
            }
            $keys = array_merge($keys, $this->catalog($view[2])['keys']);

            return '<?php echo app(\\App\\Services\\SystemEmails\\EditableEmailContent::class)->renderIncluded($__editableEmailTemplate, $__editableEmailMode, get_defined_vars(), '.$match['args'].'); ?>';
        }, $source);
    }

    private function variableName(string $expression, int $index): string
    {
        if (preg_match('/\$(\w+)(?:->(\w+)|\[[\'"](\w+)[\'"]\])?/', $expression, $match)) {
            $name = $match[1];
            $property = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
            if ($property !== '' && ! in_array($property, ['format', 'copy', 'toDateString'])) {
                $name .= '_'.$property;
            }

            return Str::snake($name);
        }

        return 'value_'.$index;
    }
}
