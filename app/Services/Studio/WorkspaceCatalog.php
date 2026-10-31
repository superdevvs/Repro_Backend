<?php

namespace App\Services\Studio;

use Illuminate\Support\Facades\Schema;

/** Public, provider-independent recipes for the native and web Studio clients. */
class WorkspaceCatalog
{
    public const PRESETS = ['listing-ready', 'color-correction', 'twilight', 'green-grass', 'virtual-staging', 'full-shoot', 'sky-replacement', 'perspective-correction', 'upscale', 'walkthrough', 'property-reel', 'social-teaser'];

    public const VIDEO_PRESETS = ['walkthrough', 'property-reel', 'social-teaser'];

    private const DETAILS = [
        'listing-ready' => ['Listing ready', 'Enhance a property photo or describe a custom photo edit.'],
        'color-correction' => ['Color correction', 'Balance light, white balance, and natural color.'],
        'twilight' => ['Twilight', 'Create a photorealistic dusk exterior.'],
        'green-grass' => ['Green grass', 'Refresh the lawn while preserving the property.'],
        'virtual-staging' => ['Virtual staging', 'Furnish a room with a selected style.'],
        'full-shoot' => ['Full shoot', 'Process a complete shoot using its editing workflow.'],
        'sky-replacement' => ['Sky replacement', 'Replace the sky with clear or cloudy daylight.'],
        'perspective-correction' => ['Perspective correction', 'Correct verticals and lens distortion.'],
        'upscale' => ['Upscale', 'Increase image resolution while preserving detail.'],
        'walkthrough' => ['Walkthrough', 'Animate selected property photos into a walkthrough.'],
        'property-reel' => ['Property reel', 'Combine selected photos into a property reel.'],
        'social-teaser' => ['Social teaser', 'Create a short vertical property preview.'],
    ];

    public function __construct(private StudioProviderSettings $settings) {}

    public function present(?string $role = null): array
    {
        $capabilities = $this->settings->capabilities();
        $video = class_exists(WorkspaceVideoOptions::class) ? WorkspaceVideoOptions::capabilities() : null;
        $presets = [];
        foreach (self::PRESETS as $id) {
            $isVideo = in_array($id, self::VIDEO_PRESETS, true);
            $presets[] = [
                'id' => $id, 'label' => self::DETAILS[$id][0], 'description' => self::DETAILS[$id][1],
                'mode' => $isVideo ? 'video' : 'photo', 'sourceWorkflow' => 'photo-enhancement',
                'requiresPreparation' => $isVideo, 'requiresConfirmation' => $id === 'full-shoot',
                'minPhotos' => 1, 'maxPhotos' => $isVideo ? 12 : 300,
                'readiness' => $capabilities['presets'][$id], 'defaultConfig' => $this->defaults($id),
            ];
        }

        return [
            'version' => 1, 'presets' => $presets, 'templates' => $this->templates(), 'capabilities' => $capabilities,
            'controls' => [
                'ratios' => ['9:16', '16:9', '1:1', '4:5'], 'durationSeconds' => ['min' => 5, 'max' => 120],
                'frameDurationSeconds' => ['min' => 0.5, 'max' => 30], 'transitions' => ReelCompositionService::TRANSITIONS,
                'audio' => $video !== null, 'selectableResolution' => $video !== null, 'video' => $video,
                'outputDimensions' => ['9:16' => [1080, 1920], '16:9' => [1920, 1080], '1:1' => [1080, 1080], '4:5' => [1080, 1350]],
                'templateScopes' => Schema::hasColumns('templates', ['visibility', 'preset_id']) ? ($role === 'client' ? ['curated', 'mine'] : ['curated', 'mine', 'team']) : ['curated'],
                'rooms' => VirtualStagingOptions::ROOMS, 'furnitureStyles' => VirtualStagingOptions::STYLES,
            ],
        ];
    }

    public function defaults(string $id): array
    {
        $config = ['prompt' => ''];
        if (in_array($id, self::VIDEO_PRESETS, true)) {
            $config += ['ratio' => $id === 'walkthrough' ? '16:9' : '9:16', 'duration' => $id === 'social-teaser' ? 15 : 30, 'transition' => 'dissolve'];
            if (class_exists(WorkspaceVideoOptions::class)) {
                $config += WorkspaceVideoOptions::defaults();
            }
        }
        if ($id === 'virtual-staging') {
            $config['adjustments'] = ['roomType' => 'living', 'furnitureStyle' => 'modern', 'removal' => 'off', 'addFurniture' => true, 'variationCount' => 1];
        }

        return $config;
    }

    public function label(string $id): string
    {
        return self::DETAILS[$id][0];
    }

    private function templates(): array
    {
        $templates = [];
        foreach (self::PRESETS as $id) {
            $templates[] = ['id' => $id, 'label' => $this->label($id), 'presetId' => $id, 'scope' => 'curated', 'config' => $this->defaults($id)];
        }
        foreach (['warm' => 'Warm and inviting', 'neutral' => 'Natural listing', 'modern' => 'Bright and modern'] as $style => $label) {
            $templates[] = ['id' => 'listing-'.$style, 'label' => $label, 'presetId' => 'listing-ready', 'scope' => 'curated', 'config' => ['prompt' => '', 'adjustments' => ['enhanceType' => $style]]];
        }
        foreach (['coastal', 'luxury', 'scandinavian'] as $style) {
            $config = $this->defaults('virtual-staging');
            $config['adjustments']['furnitureStyle'] = $style;
            $templates[] = ['id' => 'staging-'.$style, 'label' => ucfirst($style).' staging', 'presetId' => 'virtual-staging', 'scope' => 'curated', 'config' => $config];
        }
        foreach ([
            'evening-glow' => ['Evening glow', 'twilight', []],
            'natural-lawn' => ['Natural lawn', 'green-grass', []],
            'modern-living' => ['Modern living', 'virtual-staging', ['adjustments' => ['roomType' => 'living', 'furnitureStyle' => 'modern', 'removal' => 'off', 'addFurniture' => true, 'variationCount' => 1]]],
            'listing-polish' => ['Listing polish', 'listing-ready', ['adjustments' => ['enhanceType' => 'neutral', 'lensCorrection' => true, 'verticalCorrection' => true, 'skyReplacement' => false]]],
            'room-by-room' => ['Room by room', 'walkthrough', ['ratio' => '16:9', 'duration' => 30, 'transition' => 'dissolve']],
            'first-impression' => ['First impression', 'social-teaser', ['ratio' => '9:16', 'duration' => 15, 'transition' => 'dissolve']],
        ] as $id => [$label, $preset, $config]) {
            $templates[] = ['id' => $id, 'label' => $label, 'presetId' => $preset, 'scope' => 'curated', 'config' => array_replace($this->defaults($preset), $config)];
        }

        return $templates;
    }
}
