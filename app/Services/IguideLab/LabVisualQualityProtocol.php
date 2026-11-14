<?php

namespace App\Services\IguideLab;

use InvalidArgumentException;
use JsonException;

/** Pure paired-image evaluation protocol; never submits providers or creates a human review. */
final class LabVisualQualityProtocol
{
    public const VERSION = 'visual-quality-v1';
    public const MODEL = 'gpt-6-astra';
    public const RECIPE_SHA256 = 'c753b32ffc6a96bd7eab807dbb8aa3b56499dc13414565d3e65cf677dce9c483';
    public const VIEW_IDS = ['overview', 'front', 'right', 'back', 'left', 'poles', 'wrap'];
    public const CHECKS = ['edit', 'geometry', 'appearance', 'seam', 'poles', 'protected_features', 'optical_dependencies'];
    public const ACTIONS = ['none', 'replan', 'regenerate', 'refine_support', 'recompose', 'manual_review'];

    public static function recipe(): array
    {
        if (! hash_equals(self::RECIPE_SHA256, self::recipeSha256())) self::invalid('Visual evaluation recipe bytes changed.');
        $recipe = json_decode(file_get_contents(self::recipePath()), true, 16, JSON_THROW_ON_ERROR);
        if (($recipe['protocolVersion'] ?? null) !== self::VERSION || ($recipe['model'] ?? null) !== self::MODEL) self::invalid('Unsupported visual evaluation recipe.');
        return $recipe;
    }

    public static function recipeSha256(): string { return hash_file('sha256', self::recipePath()); }

    private static function recipePath(): string { return resource_path('iguide-lab/quality-recipes/visual-quality-1.json'); }

    public static function request(array $context, array $images): array
    {
        self::validateContext($context);
        if (count($images) !== count($context['views'])) self::invalid('Evaluation images do not match the ordered evidence.');
        $recipe = self::recipe();
        $content = [['type' => 'input_text', 'text' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]];
        $total = 0;
        foreach ($images as $i => $image) {
            if (! is_string($image) || ! preg_match('/\Adata:image\/(png|jpeg);base64,([A-Za-z0-9+\/=]+)\z/D', $image, $match)) self::invalid('Evaluation accepts inline PNG/JPEG evidence only.');
            $bytes = base64_decode($match[2], true);
            if ($bytes === false || strlen($bytes) < 8 || ($total += strlen($bytes)) > 24 * 1024 * 1024) self::invalid('Invalid or oversized evaluation images.');
            $size = @getimagesizefromstring($bytes); $view = $context['views'][$i];
            if (! $size || $size['mime'] !== ($match[1] === 'png' ? 'image/png' : 'image/jpeg')
                || [$size[0], $size[1]] !== [$view['width'], $view['height']]
                || ! hash_equals($view['sha256'], hash('sha256', $bytes))) self::invalid('Evaluation image differs from its ordered evidence binding.');
            $content[] = ['type' => 'input_text', 'text' => 'Evidence view: '.$view['id'].'. SOURCE LEFT; CANDIDATE RIGHT. Same capture and matching view; not an independent camera.'];
            $content[] = ['type' => 'input_image', 'image_url' => $image, 'detail' => 'high'];
        }
        return ['model' => $recipe['model'], 'store' => false, 'service_tier' => 'default',
            'reasoning' => ['effort' => 'high'], 'max_output_tokens' => 8000, 'instructions' => $recipe['instructions'],
            'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'iguide_visual_quality', 'strict' => true, 'schema' => self::schema()]]];
    }

    public static function parse(array $response, array $context): array
    {
        self::validateContext($context);
        if (($response['status'] ?? null) !== 'completed') self::invalid('Visual evaluation is incomplete.');
        $texts = [];
        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'reasoning') continue;
            if (($item['type'] ?? '') !== 'message' || ($item['role'] ?? '') !== 'assistant' || ($item['status'] ?? '') !== 'completed') self::invalid('Unexpected visual evaluation output.');
            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? '') !== 'output_text' || ! is_string($part['text'] ?? null)) self::invalid('Visual evaluation was refused or returned unexpected content.');
                $texts[] = $part['text'];
            }
        }
        if (count($texts) !== 1 || strlen($texts[0]) > 32 * 1024) self::invalid('Expected one bounded visual evaluation.');
        try { $evaluation = json_decode($texts[0], true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { self::invalid('Invalid visual evaluation JSON.'); }
        self::keys($evaluation, ['protocolVersion', 'sourceSha256', 'candidateSha256', 'intentSha256', 'evidenceManifestSha256', 'checks', 'unresolvedDependencies', 'recommendedAction', 'summary']);
        if ($evaluation['protocolVersion'] !== self::VERSION) self::invalid('Visual evaluation protocol changed.');
        foreach (['sourceSha256', 'candidateSha256', 'intentSha256', 'evidenceManifestSha256'] as $key)
            if ($evaluation[$key] !== $context[$key]) self::invalid('Visual evaluation belongs to different source, candidate, intent or evidence.');
        self::keys($evaluation['checks'], self::CHECKS);
        $allPass = $context['mechanicalQa']['passed'] && $context['mechanicalQa']['blockingReasons'] === [];
        $knownViews = array_column($context['views'], 'id');
        foreach ($evaluation['checks'] as $check) {
            self::keys($check, ['status', 'evidenceViewIds', 'reason']);
            if (! in_array($check['status'], ['pass', 'fail', 'uncertain'], true)) self::invalid('Invalid visual check status.');
            self::text($check['reason'], 900);
            self::strings($check['evidenceViewIds'], 9, 40, 1);
            if (count($check['evidenceViewIds']) !== count(array_unique($check['evidenceViewIds'])) || array_diff($check['evidenceViewIds'], $knownViews)) self::invalid('A visual check cites unknown or duplicate evidence.');
            $allPass = $allPass && $check['status'] === 'pass';
        }
        // Require seam and pole observations to cite their dedicated views, not only the small overview.
        if (! in_array('wrap', $evaluation['checks']['seam']['evidenceViewIds'], true)
            || ! in_array('poles', $evaluation['checks']['poles']['evidenceViewIds'], true)) self::invalid('Visual evaluation omits mandatory wrap or pole evidence.');
        self::strings($evaluation['unresolvedDependencies'], 12, 600);
        self::text($evaluation['summary'], 1200);
        if (! in_array($evaluation['recommendedAction'], self::ACTIONS, true)) self::invalid('Unknown visual repair action.');
        $allPass = $allPass && $evaluation['unresolvedDependencies'] === [] && $evaluation['recommendedAction'] === 'none';
        return ['protocolVersion' => self::VERSION, 'qualityStatus' => $allPass ? 'passed_automated_checks' : 'needs_review',
            'checks' => $evaluation['checks'], 'unresolvedDependencies' => $evaluation['unresolvedDependencies'],
            'recommendedAction' => ! $allPass && $evaluation['recommendedAction'] === 'none' ? 'manual_review' : $evaluation['recommendedAction'],
            'summary' => $evaluation['summary'], 'manualAcceptance' => false, 'crossViewVerified' => false];
    }

    public static function schema(): array
    {
        $object = fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $check = $object(['status' => ['type' => 'string', 'enum' => ['pass', 'fail', 'uncertain']],
            'evidenceViewIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 9], 'reason' => ['type' => 'string']]);
        $hash = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        return $object(['protocolVersion' => ['type' => 'string', 'enum' => [self::VERSION]],
            'sourceSha256' => $hash, 'candidateSha256' => $hash, 'intentSha256' => $hash, 'evidenceManifestSha256' => $hash,
            'checks' => $object(array_fill_keys(self::CHECKS, $check)),
            'unresolvedDependencies' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 12],
            'recommendedAction' => ['type' => 'string', 'enum' => self::ACTIONS], 'summary' => ['type' => 'string']]);
    }

    private static function validateContext(array $context): void
    {
        self::keys($context, ['sourceSha256', 'candidateSha256', 'intentSha256', 'evidenceManifestSha256', 'projection', 'preset', 'style', 'intent', 'protectedFeatures', 'opticalDependencies', 'reviewRequirements', 'sourceCaveats', 'mechanicalQa', 'views']);
        foreach (['sourceSha256', 'candidateSha256', 'intentSha256', 'evidenceManifestSha256'] as $key) self::hash($context[$key]);
        if ($context['projection'] !== 'equirectangular' || ! is_string($context['preset']) || ! array_key_exists($context['preset'], LabCatalog::PRESETS)
            || ($context['style'] !== null && (! is_string($context['style']) || ! array_key_exists($context['style'], LabCatalog::STYLES)))) self::invalid('Invalid visual evaluation intent.');
        // The original 2,000-character instructions can expand sixfold when JSON-encoded.
        self::text($context['intent'], 16000); self::strings($context['protectedFeatures'], 20, 600); self::strings($context['opticalDependencies'], 12, 600);
        self::strings($context['sourceCaveats'], 13, 600);
        self::strings($context['reviewRequirements'], 12, 600);
        self::keys($context['mechanicalQa'], ['passed', 'blockingReasons']);
        if (! is_bool($context['mechanicalQa']['passed'])) self::invalid('Mechanical QA requires a boolean result.');
        self::strings($context['mechanicalQa']['blockingReasons'], 20, 600);
        if (! is_array($context['views']) || ! array_is_list($context['views']) || count($context['views']) < 7 || count($context['views']) > 9) self::invalid('Visual evaluation requires full-sphere evidence.');
        $ids = array_column($context['views'], 'id'); $expected = array_slice([...self::VIEW_IDS, 'detail_1', 'detail_2'], 0, count($context['views']));
        if ($ids !== $expected) self::invalid('Visual evidence order or required coverage changed.');
        foreach ($context['views'] as $view) {
            self::keys($view, ['id', 'sha256', 'width', 'height']); self::hash($view['sha256']);
            if (! is_int($view['width']) || ! is_int($view['height']) || $view['width'] < 128 || $view['width'] > 4096
                || $view['height'] < 64 || $view['width'] !== 2 * $view['height']) self::invalid('Invalid paired evidence dimensions.');
        }
    }

    private static function keys(mixed $value, array $keys): void
    {
        if (! is_array($value) || array_is_list($value) || count($value) !== count($keys)
            || array_diff(array_keys($value), $keys) || array_diff($keys, array_keys($value))) self::invalid('Unexpected or missing visual evaluation fields.');
    }

    private static function strings(mixed $value, int $max, int $length, int $min = 0): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $min || count($value) > $max) self::invalid('Invalid visual evaluation list.');
        foreach ($value as $text) self::text($text, $length);
    }

    private static function text(mixed $value, int $max): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) self::invalid('Invalid or oversized visual evaluation text.');
    }

    private static function hash(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[a-f0-9]{64}\z/D', $value)) self::invalid('Invalid visual evaluation fingerprint.');
    }

    private static function invalid(string $message): never { throw new InvalidArgumentException($message); }
}
