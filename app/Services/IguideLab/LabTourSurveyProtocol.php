<?php
namespace App\Services\IguideLab;

/**
 * tour-survey/1 (spec 5.3-5.8, D6): PHP-only protocol with a fingerprinted recipe. Builds the context message and the
 * exact provider request bytes, and turns one stored Responses body into the normalised, index-based survey result.
 * Never reads import private input; nothing here performs I/O other than reading the recipe file.
 */
final class LabTourSurveyProtocol
{
    public const VERSION = 'tour-survey/1';
    public const RESULT_VERSION = 'tour-survey-result/1';
    public const PARSER_VERSION = 'tour-survey-parser/1';
    public const WIRE_NAME = 'iguide_tour_survey';
    /** Own fingerprint (pattern LabAnalysisRecipes.php:24-33); never part of LabAnalysisRecipes::FILES. */
    public const RECIPE = ['tour-survey-1.json', '4713356e6a42d074c2f1ff87202930148f3fd3592bba2c4746d31f846254823a'];
    public const EVIDENCE_IDS = ['source_panorama', 'view_front', 'view_right', 'view_back', 'view_left'];
    /** The parse-class error codes: a stored answer exists and the free re-parse may recover it (5.13). */
    public const PARSE_ERRORS = ['survey_unreadable', 'survey_too_large', 'survey_refused', 'survey_incomplete'];

    private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    private const EVIDENCE = [
        ['id' => 'source_panorama', 'projection' => 'equirectangular', 'width' => 2048, 'height' => 1024],
        ['id' => 'view_front', 'projection' => 'rectilinear', 'yawDegrees' => 0, 'pitchDegrees' => 0, 'hfovDegrees' => 100, 'width' => 1024, 'height' => 1024],
        ['id' => 'view_right', 'projection' => 'rectilinear', 'yawDegrees' => 90, 'pitchDegrees' => 0, 'hfovDegrees' => 100, 'width' => 1024, 'height' => 1024],
        ['id' => 'view_back', 'projection' => 'rectilinear', 'yawDegrees' => -180, 'pitchDegrees' => 0, 'hfovDegrees' => 100, 'width' => 1024, 'height' => 1024],
        ['id' => 'view_left', 'projection' => 'rectilinear', 'yawDegrees' => -90, 'pitchDegrees' => 0, 'hfovDegrees' => 100, 'width' => 1024, 'height' => 1024],
    ];
    /** Lighting description fallbacks: the templates of spec 6.2. */
    private const GOLDEN_HOUR_EXTERIOR = 'Warm, low evening light across the whole view.';
    private const GOLDEN_HOUR_INTERIOR = 'Warm evening light through the windows.';
    private const TWILIGHT = 'Blue hour outside the windows; lamps glow warm.';
    private const MAX_AREAS = 3;
    private const MAX_SURFACES = 4;
    private const MAX_ITEMS_PER_SURFACE = 12;
    private const MAX_ITEMS = 24;
    private const MAX_NOTES = 6;

    /** The recipe, refused when its bytes changed. */
    public static function recipe(): array
    {
        [$name, $sha] = self::RECIPE;
        $bytes = @file_get_contents(resource_path('iguide-lab/tour-recipes/'.$name));
        if (! is_string($bytes) || ! hash_equals($sha, hash('sha256', $bytes))) throw new \RuntimeException('tour_survey_recipe_changed');
        $recipe = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (($recipe['version'] ?? null) !== self::VERSION || ($recipe['wireName'] ?? null) !== self::WIRE_NAME
            || ! is_string($recipe['instructions'] ?? null) || ($recipe['schema']['type'] ?? null) !== 'object') {
            throw new \RuntimeException('tour_survey_recipe_invalid');
        }

        return $recipe;
    }

    /**
     * Stored in surveys.protocol (shape of LabInteriorRoomRouteProtocol.php:18-26).
     *
     * @throws LabTourConfigException when the survey model, effort or token cap is not supported
     */
    public static function snapshot(): array
    {
        $recipe = self::recipe();

        return ['version' => self::VERSION, 'recipeFile' => self::RECIPE[0], 'recipeSha256' => self::RECIPE[1],
            'instructionsSha256' => hash('sha256', $recipe['instructions']), 'schemaSha256' => LabAnalysisRecipes::hash($recipe['schema']),
            'protocolSha256' => hash_file('sha256', __FILE__), 'model' => LabTourConfig::surveyModel(), 'effort' => LabTourConfig::surveyEffort(),
            'maxOutputTokens' => LabTourConfig::surveyMaxOutputTokens(), 'wireName' => self::WIRE_NAME];
    }

    /** Context message (5.5). Names with a URL, a token word or a control character become null (D19); no hash is echoed. */
    public static function context(?string $roomName, ?string $floorName): array
    {
        return ['schemaVersion' => self::VERSION,
            'capture' => ['roomName' => LabTourText::clean($roomName, 1, 120, null), 'floorName' => LabTourText::clean($floorName, 1, 120, null)],
            'evidence' => self::EVIDENCE];
    }

    /**
     * Exact provider request bytes (5.5). $images are the five evidence images in EVIDENCE_IDS order, each
     * {id, sha256, bytes}; their sha256 and PNG dimensions are re-checked here.
     *
     * @param list<array{id:string,sha256:string,bytes:string}> $images
     * @throws LabTourConfigException for an unsupported model, effort or token cap
     * @throws \RuntimeException when the context or an image binding changed, or the payload is too large
     */
    public static function request(array $context, array $images): string
    {
        $model = LabTourConfig::surveyModel();
        $effort = LabTourConfig::surveyEffort();
        $tokens = LabTourConfig::surveyMaxOutputTokens();
        $recipe = self::recipe();
        $capture = $context['capture'] ?? null;
        if (array_keys($context) !== ['schemaVersion', 'capture', 'evidence'] || $context['schemaVersion'] !== self::VERSION
            || $context['evidence'] !== self::EVIDENCE || ! is_array($capture) || array_keys($capture) !== ['roomName', 'floorName']) {
            throw new \RuntimeException('tour_survey_context_invalid');
        }
        foreach ($capture as $name) {
            if ($name !== null && (! is_string($name) || LabTourText::clean($name, 1, 120, null) !== $name)) throw new \RuntimeException('tour_survey_context_invalid');
        }
        if (! array_is_list($images) || count($images) !== count(self::EVIDENCE)) throw new \RuntimeException('tour_survey_image_changed');
        $content = [['type' => 'input_text', 'text' => json_encode($context, self::JSON)]];
        foreach (self::EVIDENCE as $i => $evidence) {
            $image = $images[$i];
            if (! is_array($image) || ($image['id'] ?? null) !== $evidence['id'] || ! is_string($image['bytes'] ?? null) || ! is_string($image['sha256'] ?? null)
                || ! hash_equals($image['sha256'], hash('sha256', $image['bytes']))) {
                throw new \RuntimeException('tour_survey_image_changed');
            }
            $size = @getimagesizefromstring($image['bytes']);
            if ($size === false || [$size[0], $size[1], $size[2]] !== [$evidence['width'], $evidence['height'], IMAGETYPE_PNG]) {
                throw new \RuntimeException('tour_survey_image_changed');
            }
            $content[] = ['type' => 'input_text', 'text' => 'Evidence ID: '.$evidence['id']];
            $content[] = ['type' => 'input_image', 'detail' => 'high', 'image_url' => 'data:image/png;base64,'.base64_encode($image['bytes'])];
        }
        $bytes = json_encode(['model' => $model, 'store' => false, 'service_tier' => 'default', 'reasoning' => ['effort' => $effort],
            'max_output_tokens' => $tokens, 'instructions' => $recipe['instructions'], 'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => self::WIRE_NAME, 'strict' => true, 'schema' => $recipe['schema']]]], self::JSON);
        if (strlen($bytes) > LabTourConfig::surveyMaxPayloadBytes()) throw new \RuntimeException('tour_survey_payload_too_large');

        return $bytes;
    }

    /**
     * Steps 3-8 of spec 5.8 on one stored Responses body: envelope, output extraction, strict structural validation
     * against the recipe schema, tolerant normalisation.
     *
     * @return array the normalised result ("tour-survey-result/1")
     * @throws LabTourSurveyParseException
     */
    public static function parse(string $body, string $model, string $providerResponseId): array
    {
        try {
            $raw = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LabTourSurveyParseException('survey_unreadable', 'envelope_json');
        }
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) throw new LabTourSurveyParseException('survey_unreadable', 'envelope_json');
        $status = $raw['status'] ?? null;
        if ($status === 'incomplete' && is_array($raw['incomplete_details'] ?? null) && ($raw['incomplete_details']['reason'] ?? null) === 'max_output_tokens') {
            throw new LabTourSurveyParseException('survey_incomplete', 'max_output_tokens');
        }
        if ($status !== 'completed') throw new LabTourSurveyParseException('survey_unreadable', 'envelope_status');
        $echo = $raw['model'] ?? null;
        if (! is_string($echo) || ($echo !== $model && preg_match('/\A'.preg_quote($model, '/').'-\d{4}-\d{2}-\d{2}\z/D', $echo) !== 1)) {
            throw new LabTourSurveyParseException('survey_unreadable', 'envelope_model');
        }
        $id = $raw['id'] ?? null;
        if (! is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,200}\z/D', $id) !== 1 || ! hash_equals($providerResponseId, $id)) {
            throw new LabTourSurveyParseException('survey_unreadable', 'envelope_id');
        }
        $text = self::outputText($raw['output'] ?? null);
        try {
            $tree = json_decode($text, false, 32, JSON_THROW_ON_ERROR);
            $answer = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LabTourSurveyParseException('survey_unreadable', 'output_json');
        }
        if (! $tree instanceof \stdClass || ! is_array($answer)) throw new LabTourSurveyParseException('survey_unreadable', 'output_json');
        self::validate($tree, self::recipe()['schema'], '');

        return self::normalise($answer);
    }

    /** Copy of LabStagingFramingProtocol::structured (LabStagingFramingProtocol.php:55-63; pinned, so copied). */
    private static function outputText(mixed $output): string
    {
        if (! is_array($output) || ! array_is_list($output)) throw new LabTourSurveyParseException('survey_unreadable', 'output_items');
        $texts = [];
        foreach ($output as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'reasoning') continue;
            if (! is_array($item) || ($item['type'] ?? null) !== 'message' || ($item['role'] ?? null) !== 'assistant' || ($item['status'] ?? null) !== 'completed'
                || ! is_array($item['content'] ?? null) || ! array_is_list($item['content'])) {
                throw new LabTourSurveyParseException('survey_unreadable', 'output_items');
            }
            foreach ($item['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'refusal') throw new LabTourSurveyParseException('survey_refused', 'refusal');
                if (! is_array($part) || ($part['type'] ?? null) !== 'output_text' || ! is_string($part['text'] ?? null)) {
                    throw new LabTourSurveyParseException('survey_unreadable', 'output_parts');
                }
                $texts[] = $part['text'];
            }
        }
        if (count($texts) !== 1) throw new LabTourSurveyParseException('survey_unreadable', 'output_count');
        if (strlen($texts[0]) > LabTourConfig::surveyMaxOutputTextBytes()) throw new LabTourSurveyParseException('survey_unreadable', 'output_size');

        return $texts[0];
    }

    /**
     * Strict check against the 5.4 schema: exact key sets, types, enums and lists. Objects are decoded as stdClass so a
     * JSON object can never pass for a list or the reverse. min/max/maxItems are left to the tolerant normalisation.
     */
    private static function validate(mixed $value, array $node, string $path): void
    {
        $fail = fn (string $at) => new LabTourSurveyParseException('survey_unreadable', 'schema:'.($at === '' ? '$' : $at));
        $child = fn (string|int $key) => ltrim($path.'.'.(preg_match('/\A[A-Za-z0-9_]{1,40}\z/D', (string) $key) === 1 ? $key : '?'), '.');
        switch ($node['type']) {
            case 'object':
                if (! $value instanceof \stdClass) throw $fail($path);
                $present = array_map('strval', array_keys(get_object_vars($value)));
                foreach ($present as $key) if (! in_array($key, $node['required'], true)) throw $fail($child($key));
                foreach ($node['required'] as $key) if (! property_exists($value, $key)) throw $fail($child($key));
                foreach ($node['properties'] as $key => $schema) self::validate($value->{$key}, $schema, $child($key));
                return;
            case 'array':
                if (! is_array($value) || ! array_is_list($value)) throw $fail($path);
                foreach ($value as $i => $item) self::validate($item, $node['items'], $child($i));
                return;
            case 'string':
                if (! is_string($value) || (isset($node['enum']) && ! in_array($value, $node['enum'], true))) throw $fail($path);
                return;
            case 'number':
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) throw $fail($path);
                return;
            case 'integer':
                if (! is_int($value) && ! (is_float($value) && is_finite($value) && floor($value) === $value)) throw $fail($path);
                return;
            case 'boolean':
                if (! is_bool($value)) throw $fail($path);
                return;
            default:
                throw $fail($path);
        }
    }

    /** Tolerant normalisation (5.8 step 8). Input is schema-valid; every emitted list is rebuilt with array_values. */
    private static function normalise(array $a): array
    {
        $warnings = [];
        $setting = $a['space']['setting'];
        $exterior = $setting === 'exterior';
        $space = ['setting' => $setting, 'label' => self::text($a['space']['label'], 1, 60, $exterior ? 'Exterior space' : 'Interior space', $warnings),
            'confidence' => self::unit($a['space']['confidence'], $warnings)];
        $skyShare = self::unit($a['skyShare'], $warnings);
        $l = $a['light'];
        $light = ['windows' => $l['windows'], 'windowKind' => $l['windowKind'], 'daylight' => $l['daylight'], 'daylightStrength' => $l['daylightStrength'],
            'lightFixtures' => $l['lightFixtures'], 'lampsOn' => $l['lampsOn'], 'hardShadows' => $l['hardShadows'], 'mirrorsOrGlass' => $l['mirrorsOrGlass']];
        $lighting = [];
        foreach (['goldenHour' => $exterior ? self::GOLDEN_HOUR_EXTERIOR : self::GOLDEN_HOUR_INTERIOR, 'twilight' => self::TWILIGHT] as $look => $fallback) {
            $lighting[$look] = ['description' => self::text($a['lighting'][$look]['description'], 0, 160, $fallback, $warnings),
                'confidence' => self::unit($a['lighting'][$look]['confidence'], $warnings)];
        }

        return ['version' => self::RESULT_VERSION, 'space' => $space, 'skyShare' => $skyShare, 'light' => $light, 'lighting' => $lighting,
            'lawn' => self::lawn($a['lawn'], $exterior, $warnings), 'clutter' => ['surfaces' => self::surfaces($a['clutter']['surfaces'], $warnings)],
            'notes' => self::notes($a['notes'], $warnings), 'warnings' => array_values(array_unique($warnings))];
    }

    private static function lawn(array $lawn, bool $exterior, array &$warnings): array
    {
        $none = ['status' => 'none', 'condition' => null, 'areas' => []];
        if (! $exterior) {
            if ($lawn['status'] !== 'none' || $lawn['condition'] !== 'none' || $lawn['areas'] !== []) $warnings[] = 'interior_lawn_ignored';

            return $none;
        }
        $raw = $lawn['areas'];
        if (count($raw) > self::MAX_AREAS) { $warnings[] = 'truncated_areas'; $raw = array_slice($raw, 0, self::MAX_AREAS); }
        $areas = [];
        foreach ($raw as $area) {
            $region = self::region($area['box'], $warnings);
            if ($region === null) continue;
            $areas[] = ['label' => self::text($area['label'], 1, 60, 'Lawn area', $warnings), 'position' => $area['position'], 'region' => $region,
                'confidence' => self::unit($area['confidence'], $warnings)];
        }
        // Contract lawn rules (tourContract.ts:167-170), applied last and in this order.
        $status = $lawn['status'];
        if ($status === 'found' && $areas === []) { $warnings[] = 'lawn_found_without_areas'; $status = 'uncertain'; }
        if ($status === 'none') {
            if ($lawn['condition'] !== 'none') $warnings[] = 'lawn_condition_dropped';
            if ($areas !== []) $warnings[] = 'lawn_areas_dropped';

            return $none;
        }

        return ['status' => $status, 'condition' => in_array($lawn['condition'], ['none', 'unknown'], true) ? null : $lawn['condition'], 'areas' => array_values($areas)];
    }

    private static function surfaces(array $raw, array &$warnings): array
    {
        if (count($raw) > self::MAX_SURFACES) { $warnings[] = 'truncated_surfaces'; $raw = array_slice($raw, 0, self::MAX_SURFACES); }
        $surfaces = [];
        $kept = 0;
        foreach ($raw as $surface) {
            $region = self::region($surface['box'], $warnings);
            if ($region === null) continue;
            $rawItems = $surface['items'];
            if (count($rawItems) > self::MAX_ITEMS_PER_SURFACE) { $warnings[] = 'truncated_items'; $rawItems = array_slice($rawItems, 0, self::MAX_ITEMS_PER_SURFACE); }
            $items = [];
            foreach ($rawItems as $item) {
                $itemRegion = self::region($item['box'], $warnings);
                if ($itemRegion === null) continue;
                if ($kept >= self::MAX_ITEMS) { $warnings[] = 'items_over_capture_limit'; continue; }
                $group = (int) $item['touchGroup'];
                if ($group < 0 || $group > 12) { $warnings[] = 'value_clamped'; $group = max(0, min(12, $group)); }
                $items[] = ['label' => self::text($item['label'], 1, 60, 'Item', $warnings), 'description' => self::text($item['description'], 0, 160, '', $warnings),
                    'region' => $itemRegion, 'confidence' => self::unit($item['confidence'], $warnings), 'glassOrMirror' => $item['glassOrMirror'], 'touchGroup' => $group];
                $kept++;
            }
            if ($items === []) { $warnings[] = 'empty_surface_dropped'; continue; }
            $surfaces[] = ['label' => self::text($surface['label'], 1, 60, 'Surface', $warnings), 'region' => $region, 'items' => array_values($items)];
        }

        return array_values($surfaces);
    }

    private static function notes(array $raw, array &$warnings): array
    {
        if (count($raw) > self::MAX_NOTES) { $warnings[] = 'truncated_notes'; $raw = array_slice($raw, 0, self::MAX_NOTES); }
        $notes = [];
        foreach ($raw as $note) {
            $text = LabTourText::clean($note['text'], 1, 200, null);
            if ($text === null) { $warnings[] = 'note_dropped'; continue; }
            $notes[] = ['topic' => $note['topic'], 'text' => $text];
        }

        return array_values($notes);
    }

    /** A box through LabTourGeometry::region (5.9); null drops the entity (box_dropped). */
    private static function region(array $box, array &$warnings): ?array
    {
        foreach ($box as $value) if ($value < 0 || $value > 1) { $warnings[] = 'value_clamped'; break; }
        $region = LabTourGeometry::region($box);
        if ($region === null) $warnings[] = 'box_dropped';

        return $region;
    }

    /** A 0..1 number, clamped (value_clamped) and rounded to 2 decimals; never -0. */
    private static function unit(int|float $value, array &$warnings): float
    {
        $value = (float) $value;
        if ($value < 0 || $value > 1) { $warnings[] = 'value_clamped'; $value = max(0.0, min(1.0, $value)); }

        return round($value, 2) + 0.0;
    }

    /** LabTourText::clean with text_replaced when the fallback is used (D19: replaced, never emitted raw). */
    private static function text(mixed $value, int $min, int $max, string $fallback, array &$warnings): string
    {
        $clean = LabTourText::clean($value, $min, $max, null);
        if ($clean !== null) return $clean;
        $warnings[] = 'text_replaced';

        return $fallback;
    }
}
