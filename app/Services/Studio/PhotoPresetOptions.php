<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Public provider contracts, deliberately separate from custom pixel edits. */
class PhotoPresetOptions
{
    public const CLOUDS = ['open_house_puffs', 'full_house_puffs', 'streaks_with_puffs', 'sweep_streaks', 'scatter_streaks', 'crisp_streaks', 'clear_fade', 'original'];

    public static function normalize(string $preset, array $options): array
    {
        $options = array_filter($options, fn ($value) => $value !== '' && $value !== null);
        if ($preset === 'virtual-staging') {
            VirtualStagingOptions::assert($options);

            return \Illuminate\Support\Arr::only($options, ['roomType', 'furnitureStyle', 'removal', 'addFurniture', 'variationCount', 'watermark', 'resolution', 'useDetectedMask']);
        }
        $rules = match ($preset) {
            'full-shoot' => ['sceneType' => Rule::in(['auto', 'interior', 'exterior']),
                'cloud_style' => Rule::in(self::CLOUDS), 'interior_cloud_style' => Rule::in(self::CLOUDS),
                'custom_style_id' => ['string', 'max:200']],
            'sky-replacement' => ['cloudType' => Rule::in(['CLEAR', 'LOW_CLOUD', 'HIGH_CLOUD'])],
            'green-grass' => [],
            'perspective-correction' => ['lensCorrection' => 'boolean', 'perspectiveMode' => Rule::in(['DEFAULT_ENABLED', 'PRESERVE_CONTENT', 'PRIORITIZE_PERSPECTIVE'])],
            'listing-ready', 'color-correction' => ['enhanceType' => Rule::in(['warm', 'neutral', 'modern']),
                'lensCorrection' => 'boolean', 'verticalCorrection' => 'boolean', 'skyReplacement' => 'boolean',
                'cloudType' => Rule::in(['CLEAR', 'LOW_CLOUD', 'HIGH_CLOUD']),
                'windowPull' => Rule::in(['NONE', 'ONLY_WINDOWS', 'WINDOWS_WITH_SKIES']), 'privacy' => 'boolean',
                'fireplace' => Rule::in(['AS_SHOT', 'ALIGHT']), 'tv' => Rule::in(['AS_SHOT', 'BLACK_OUT']),
                'perspectiveMode' => Rule::in(['DEFAULT_ENABLED', 'PRESERVE_CONTENT', 'PRIORITIZE_PERSPECTIVE'])],
            'upscale', 'twilight' => [],
            default => null,
        };
        if ($rules === null) {
            return $options;
        } // Video workflows have separate contracts.
        $validated = Validator::make($options, array_map(fn ($rule) => array_merge(['sometimes'], is_array($rule) ? $rule : [$rule]), $rules))->validate();
        foreach (['lensCorrection', 'verticalCorrection', 'skyReplacement', 'privacy'] as $key) {
            if (isset($validated[$key])) {
                $validated[$key] = (bool) $validated[$key];
            }
        }

        return $validated;
    }

    public static function fotello(array $options): array
    {
        return \Illuminate\Support\Arr::only(self::normalize('full-shoot', $options), ['cloud_style', 'interior_cloud_style', 'custom_style_id']);
    }

    public static function autoenhance(string $preset, array $options): array
    {
        $options = self::normalize($preset, $options);
        if ($preset === 'upscale') {
            return ['enhance' => false, 'upscale' => true, 'lens_correction' => false, 'perspective_correction' => false, 'sky_replacement' => false];
        }
        $result = ['enhance' => true, 'upscale' => false, 'lens_correction' => $options['lensCorrection'] ?? true,
            'perspective_correction' => $options['verticalCorrection'] ?? true,
            'sky_replacement' => $preset === 'sky-replacement' || ($options['skyReplacement'] ?? false)];
        foreach (['enhanceType' => 'enhance_type', 'windowPull' => 'window_pull_type', 'privacy' => 'privacy', 'perspectiveMode' => 'perspective_correction_mode'] as $key => $field) {
            if (isset($options[$key])) {
                $result[$field] = $options[$key];
            }
        }
        if ($result['sky_replacement']) {
            $result['cloud_type'] = $options['cloudType'] ?? 'CLEAR';
        }
        if ($preset === 'green-grass') {
            $result['restage']['grass'] = 'GREEN';
        }
        if (isset($options['fireplace'])) {
            $result['restage']['fire_in_fireplaces'] = $options['fireplace'];
        }
        if (isset($options['tv'])) {
            $result['restage']['tvs'] = $options['tv'];
        }

        return $result;
    }
}
