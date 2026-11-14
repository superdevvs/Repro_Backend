<?php

namespace App\Services\IguideLab;

use InvalidArgumentException;

/** Explicit six-input compiler. This never changes the frozen v1 wire representation. */
final class LabSectionAnalysisProtocol
{
    public const VERSION = 'responses-section-context-v1';

    public static function compile(array $recipe, array $context, array $images): array
    {
        self::validate($context);
        $base = $context; unset($base['sourceContext']);
        $payload = LabAnalysisRequestV1::compile($recipe, $base, $images);
        foreach ($images as $i => $image) {
            $bytes = base64_decode(substr($image, strpos($image, ',') + 1), true);
            $view = $context['sourceContext']['views'][$i]; $size = getimagesizefromstring($bytes);
            if (! hash_equals($view['sha256'], hash('sha256', $bytes)) || [$size[0], $size[1]] !== [$view['width'], $view['height']]) self::invalid('Context image bytes differ from their ordered manifest.');
        }
        $payload['input'][0]['content'][0]['text'] = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ($context['sourceContext']['views'] as $i => $view) {
            $payload['input'][0]['content'][1 + 2 * $i]['text'] = 'View ID: '.$view['id'].'; role: '.$view['role']
                .($i === 0 ? '; this exact cut is the only image editing target.' : '; context only, not an editing target or an independent camera.');
        }
        return $payload;
    }

    public static function parse(array $response, array $context): array
    {
        self::validate($context);
        $base = $context; unset($base['sourceContext']);
        return LabScenePlanProtocol::parse($response, $base);
    }

    private static function validate(array $context): void
    {
        $manifest = $context['sourceContext'] ?? null; $binding = $manifest['binding'] ?? null;
        if (is_array($manifest) && array_diff(array_keys($manifest), ['version', 'binding', 'bindingSha256', 'views'])) self::invalid('Unknown context manifest field.');
        if (! is_array($manifest) || ! is_array($binding) || ($manifest['version'] ?? null) !== LabAnalysisSourceContext::VERSION
            || ($context['projection'] ?? null) !== 'rectilinear' || ($context['viewIds'] ?? null) !== LabAnalysisSourceContext::IDS
            || ($binding['sourceSha256'] ?? null) !== ($context['sourceSha256'] ?? null)
            || ($binding['version'] ?? null) !== LabAnalysisSourceContext::VERSION
            || ($binding['viewRecipe'] ?? null) !== LabAnalysisSourceContext::VIEW_RECIPE
            || ($binding['sourceOrigin'] ?? null) !== 'uploaded_native_attachment'
            || ($binding['sourceRole'] ?? null) !== 'authoritative_edit_cut' || ($binding['contextRole'] ?? null) !== 'same_capture_context_only'
            || ($binding['scopeExpansion'] ?? null) !== false || ($binding['independentCameraEvidence'] ?? null) !== false
            || ($binding['dedicatedPoleViews'] ?? null) !== false
            || ! hash_equals(LabAnalysisRecipes::hash($binding), $manifest['bindingSha256'] ?? '')
            || array_column($manifest['views'] ?? [], 'id') !== LabAnalysisSourceContext::IDS) self::invalid('Invalid section context authority or identity.');
        foreach (['sectionId', 'nativeCaseId', 'visibleParentCaseId'] as $key)
            if (! is_string($binding[$key] ?? null) || ! \Illuminate\Support\Str::isUuid($binding[$key])) self::invalid('Invalid context record identity.');
        foreach (['sourceSha256', 'nativeSourceSha256', 'geometrySha256'] as $key)
            if (! is_string($binding[$key] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $binding[$key])) self::invalid('Invalid context fingerprint.');
        if (array_diff(array_keys($binding), ['version', 'sectionId', 'nativeCaseId', 'visibleParentCaseId', 'sourceSha256', 'nativeSourceSha256', 'nativeDimensions', 'geometrySha256', 'view', 'sourceOrigin', 'sourceRole', 'contextRole', 'scopeExpansion', 'independentCameraEvidence', 'dedicatedPoleViews', 'viewRecipe', 'preparationHashes'])) self::invalid('Unknown context binding field.');
        foreach (['preparer', 'imagePreparation', 'diagnosticAdapter', 'geometry'] as $key)
            if (! is_string($binding['preparationHashes'][$key] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $binding['preparationHashes'][$key])) self::invalid('Invalid context preparation fingerprint.');
        if (array_diff(array_keys($binding['preparationHashes']), ['preparer', 'imagePreparation', 'diagnosticAdapter', 'geometry'])) self::invalid('Unknown preparation fingerprint.');
        $dimensions = $binding['nativeDimensions'] ?? [];
        if (! is_int($dimensions['width'] ?? null) || ! is_int($dimensions['height'] ?? null)
            || array_diff(array_keys($dimensions), ['width', 'height'])
            || $dimensions['width'] !== 2 * $dimensions['height'] || $dimensions['width'] < 2048
            || $dimensions['width'] * $dimensions['height'] > 64_000_000) self::invalid('Invalid native context dimensions.');
        $angles = [[0, 0], [90, 0], [180, 0], [270, 0]];
        foreach ($manifest['views'] as $i => $view) {
            $shape = $i === 0 ? [2048, 2048] : ($i === 1 ? [2048, 1024] : [1024, 1024]);
            if ([$view['width'] ?? null, $view['height'] ?? null] !== $shape
                || ($view['role'] ?? null) !== ($i === 0 ? 'authoritative_edit_cut' : 'same_capture_context_only')
                || ($view['projection'] ?? null) !== ($i === 1 ? 'equirectangular' : 'rectilinear')
                || ! is_string($view['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $view['sha256'])) self::invalid('Invalid ordered context image descriptor.');
            if (array_diff(array_keys($view), ['id', 'sha256', 'width', 'height', 'projection', 'role', 'yaw', 'pitch', 'hFov'])) self::invalid('Unknown context image field.');
            if ($i === 0 && [(float) ($view['yaw'] ?? NAN), (float) ($view['pitch'] ?? NAN), (float) ($view['hFov'] ?? NAN)]
                !== [(float) ($binding['view']['yaw'] ?? NAN), (float) ($binding['view']['pitch'] ?? NAN), (float) ($binding['view']['hFov'] ?? NAN)]) self::invalid('The authoritative cut pose differs from native geometry.');
            if ($i === 1 && array_intersect(array_keys($view), ['yaw', 'pitch', 'hFov'])) self::invalid('A full parent panorama cannot claim a perspective pose.');
            if ($i >= 2 && [(float) ($view['yaw'] ?? NAN), (float) ($view['pitch'] ?? NAN), (float) ($view['hFov'] ?? NAN)] !== [(float) $angles[$i - 2][0], 0.0, 100.0]) self::invalid('Context cardinal pose changed.');
        }
        foreach (['yaw' => [-180, 180], 'pitch' => [-90, 90], 'hFov' => [30, 130]] as $key => [$low, $high]) {
            $n = $binding['view'][$key] ?? null;
            if ((! is_float($n) && ! is_int($n)) || ! is_finite((float) $n) || $n < $low || $n > $high) self::invalid('Invalid source cut pose.');
        }
        if (array_diff(array_keys($binding['view']), ['yaw', 'pitch', 'hFov', 'width', 'height'])
            || [$binding['view']['width'] ?? null, $binding['view']['height'] ?? null] !== [2048, 2048]) self::invalid('Invalid authoritative cut dimensions.');
    }

    private static function invalid(string $message): never { throw new InvalidArgumentException($message); }
}
