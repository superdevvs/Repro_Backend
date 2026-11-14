<?php

namespace App\Services\IguideLab;

use InvalidArgumentException;

/** Frozen v1 context/image encoding. Add a new compiler for a changed wire representation. */
final class LabAnalysisRequestV1
{
    public const VERSION = 'responses-scene-input-v1';

    public static function compile(array $recipe, array $context, array $images): array
    {
        self::validateContext($context);
        if (count($images) !== count($context['viewIds'])) self::invalid('Input views do not match image count.');
        $content = [['type' => 'input_text', 'text' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]];
        $totalBytes = 0;
        foreach ($images as $index => $image) {
            if (! is_string($image) || ! preg_match('/\Adata:image\/(png|jpeg);base64,([A-Za-z0-9+\/=]+)\z/D', $image, $match)) self::invalid('Use inline PNG or JPEG inputs.');
            $bytes = base64_decode($match[2], true);
            if ($bytes === false || strlen($bytes) < 8 || ($totalBytes += strlen($bytes)) > 24 * 1024 * 1024) self::invalid('Invalid or oversized image input.');
            $size = @getimagesizefromstring($bytes);
            $mime = $match[1] === 'png' ? 'image/png' : 'image/jpeg';
            if (! $size || $size['mime'] !== $mime || $size[0] < 64 || $size[1] < 64 || $size[0] * $size[1] > 8_388_608) self::invalid('Invalid analysis image dimensions or type.');
            if ($index === 0 && $context['projection'] === 'equirectangular' && $size[0] !== $size[1] * 2) self::invalid('The source panorama must have a 2:1 aspect ratio.');
            $content[] = ['type' => 'input_text', 'text' => 'View ID: '.$context['viewIds'][$index]];
            $content[] = ['type' => 'input_image', 'detail' => 'high', 'image_url' => $image];
        }
        return ['model' => $recipe['model'], 'store' => $recipe['store'], 'service_tier' => $recipe['service_tier'],
            'reasoning' => $recipe['reasoning'], 'max_output_tokens' => $recipe['max_output_tokens'],
            'instructions' => $recipe['instructions'], 'input' => [['role' => 'user', 'content' => $content]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'iguide_scene_plan', 'strict' => true, 'schema' => $recipe['schema']]]];
    }

    private static function validateContext(array $context): void
    {
        if (array_diff(array_keys($context), ['sourceSha256', 'projection', 'preset', 'style', 'viewIds', 'instructions'])) self::invalid('Unknown analysis context field.');
        if (! is_string($context['sourceSha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $context['sourceSha256'])
            || ! in_array($context['projection'] ?? null, ['equirectangular', 'rectilinear'], true)
            || ! is_string($context['preset'] ?? null) || ! isset(LabCatalog::PRESETS[$context['preset']]) || ! array_key_exists('style', $context)
            || ($context['style'] !== null && (! is_string($context['style']) || ! isset(LabCatalog::STYLES[$context['style']])))
            || ! is_array($context['viewIds'] ?? null) || ! array_is_list($context['viewIds'])
            || count($context['viewIds']) < 1 || count($context['viewIds']) > 7 || $context['viewIds'][0] !== 'source') self::invalid('Invalid source analysis context.');
        foreach ($context['viewIds'] as $id) if (! is_string($id) || ! preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $id)) self::invalid('Invalid analysis view ID.');
        if (count(array_unique($context['viewIds'])) !== count($context['viewIds'])) self::invalid('Analysis view IDs must be unique.');
        if (isset($context['instructions']) && (! is_string($context['instructions']) || mb_strlen($context['instructions']) > 4000)) self::invalid('Analysis instructions are too long.');
    }

    private static function invalid(string $message): never { throw new InvalidArgumentException($message); }
}
