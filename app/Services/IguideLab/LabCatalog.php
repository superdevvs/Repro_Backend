<?php

namespace App\Services\IguideLab;

use App\Services\Studio\StudioProviderSettings;

class LabCatalog
{
    public const MODELS = [
        'gpt-image-2.6-sunburst' => ['label' => 'OpenAI 2.6 Sunburst', 'provider' => 'openai'],
        'gpt-image-2.6-flare' => ['label' => 'OpenAI 2.6 Flare', 'provider' => 'openai'],
        'gpt-image-2.5-sunburst' => ['label' => 'OpenAI 2.5 Sunburst', 'provider' => 'openai'],
        'gpt-image-2.5-flare' => ['label' => 'OpenAI 2.5 Flare', 'provider' => 'openai'],
        'bytedance/seedream/v5/pro/edit' => ['label' => 'Seedream 5 Pro', 'provider' => 'fal'],
    ];
    public const PRESETS = ['grass' => 'Green grass', 'declutter' => 'Digital decluttering', 'staging' => 'Virtual staging', 'twilight' => 'Twilight', 'golden-hour' => 'Golden hour', 'lifestyle' => 'People and events', 'interior-design' => 'Interior design'];
    public const STYLES = ['scandinavian' => 'Scandinavian', 'bohemian' => 'Bohemian', 'modern' => 'Modern contemporary', 'mid-century' => 'Mid-century modern', 'modern-farmhouse' => 'Modern farmhouse'];

    public static function models(): array
    {
        return collect(self::MODELS)->map(fn ($model, $id) => ['id' => $id, ...$model,
            'capabilities' => ['complexity' => self::supportsComplexity($id)
                ? ['supported' => true, 'values' => ['low', 'medium', 'high'], 'default' => 'medium']
                : ['supported' => false, 'values' => []]],
            'ready' => $model['provider'] === 'openai'
            ? app(LabCredentials::class)->ready()
            : filled(app(StudioProviderSettings::class)->credentials($model['provider'])['api_key'] ?? null)])->values()->all();
    }

    public static function supportsComplexity(string $model): bool
    {
        return in_array($model, ['gpt-image-2.6-sunburst', 'gpt-image-2.6-flare'], true);
    }

    public static function supportsSectionMask(string $model): bool
    {
        return in_array($model, ['gpt-image-2.6-sunburst', 'gpt-image-2.6-flare'], true);
    }

    public static function is25(string $model): bool
    {
        return in_array($model, ['gpt-image-2.5-sunburst', 'gpt-image-2.5-flare'], true);
    }

    public static function labels(array $items): array
    {
        return collect($items)->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values()->all();
    }

    public static function prompt(string $preset, ?string $style, string $projection, string $extra, array $options = []): string
    {
        $base = 'Edit the supplied property image. Preserve the camera position, field of view and perspective. ';
        if ($options['preserveArchitecture'] ?? true) $base .= 'Preserve architecture, room dimensions, walls, doors, windows and permanent fixtures. ';
        $base .= 'Keep all unchanged surfaces faithful to the source. No text, logos or watermarks. ';
        if ($projection === 'equirectangular') {
            $base .= 'This is a full 360 degree equirectangular panorama at 2:1 aspect ratio. Preserve its spherical projection, horizon and exact orientation. Left and right edges join: make them seamless. Do not turn it into a normal perspective photograph. ';
        }
        $base .= match ($preset) {
            'grass' => 'Refresh only existing grass to healthy natural green, retaining lawn boundaries, texture, trees, paths and shadows.',
            'declutter' => ($options['declutterMode'] ?? 'generic') === 'selected-object'
                ? 'Remove only these explicitly selected objects: '.implode('; ', $options['selectedObjects']).'. Keep every other object and surface unchanged. Reconstruct only the surfaces occluded by the selected objects. Explicitly preserve: '.implode('; ', $options['preserveObjects'] ?: ['all objects outside the selection']).'.'
                : 'Remove loose clutter, small personal belongings and rubbish. Retain furniture, permanent fixtures and the building. Reconstruct occluded surfaces naturally.',
            'staging' => 'Add realistic, appropriately scaled furniture and decor in a '.(self::STYLES[$style] ?? 'Modern').' style. Preserve existing room layout and leave doors and circulation routes clear.',
            'twilight' => 'Create believable blue-hour twilight with a coherent sky and warm existing windows and lights. Keep architecture and landscaping unchanged. Match shadows and reflections.',
            'golden-hour' => 'Relight the scene with warm low-angle golden-hour sunlight, coherent sun direction, shadows and reflections. Preserve the property and landscaping.',
            'lifestyle' => 'Add a small number of natural-looking adults enjoying the space, with realistic scale, poses, shadows and occlusion. Preserve the property. This is a staged lifestyle visualization.',
            'interior-design' => 'Restyle furniture, textiles, colours and removable decor in a '.(self::STYLES[$style] ?? 'Modern').' style. Keep architectural layout, openings and permanent fixtures unchanged.',
        };

        $base .= ' Room type: '.($options['roomType'] ?? 'auto').'. Furniture density: '.($options['furnitureDensity'] ?? 'balanced').'. Lighting intensity: '.($options['lightingIntensity'] ?? 'natural').'.';
        if (($options['preserveArchitecture'] ?? true) === false) {
            $base .= ' Architectural changes may be proposed only if explicitly requested below; preserve the camera and projection in every case.';
        }
        $base .= ($options['allowFinishChanges'] ?? false) && $preset === 'interior-design' && ! ($options['preserveFixedFinishes'] ?? true)
            ? ' You may change fixed surface finishes such as paint, flooring and cabinetry finishes.'
            : ' Preserve existing fixed finishes including flooring, countertops and cabinetry.';
        if (! empty($options['referenceCaseIds']) || ! empty($options['referenceRunIds']) || ! empty($options['referenceCompositeIds'])) $base .= ' Additional input images are visual references: match their furniture identity and materials while retaining the first image camera viewpoint. Do not copy a reference viewpoint.';

        return $base.($extra !== '' ? ' Additional instructions: '.$extra : '');
    }
}
