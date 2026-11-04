<?php

namespace App\Services\ListingStudio;

use App\Services\Studio\VirtualStagingOptions;

/** Versioned server-owned recipe; clients cannot select prompts, routes or provider controls. */
class ListingStudioPaidRecipe
{
    public const VERSION = 'quality-v2';

    public const PRESETS = ['staging' => 'virtual-staging', 'decluttering' => 'listing-ready', 'grass' => 'green-grass', 'twilight' => 'twilight'];

    public const LEGACY_DECLUTTER_PROMPT = 'Remove loose everyday clutter such as scattered clothing, papers, toys, trash and loose countertop items. Preserve all furniture, permanent fixtures, appliances, cabinetry, doors, windows, flooring, architectural details, room layout and camera perspective. Do not empty the room, add furniture or alter the property.';

    public const PREVIOUS_DECLUTTER_PROMPT = self::LEGACY_DECLUTTER_PROMPT.' Preserve intentional cushions, rugs, artwork, lamps and decorative objects. If none of the defined loose clutter is present, leave the photo unchanged. Do not remove or replace furniture or decor to create a more minimal room.';

    public const PREVIOUS_TWILIGHT_PROMPT = 'Create a bright early blue-hour real estate marketing photograph just after sunset. Keep the entire facade, roof, driveway, lawn and landscaping clearly readable with balanced natural exposure and detail in shadows. Use a soft natural blue-hour sky and warm light only in existing windows and existing light fixtures. Preserve every building, roofline, opening, door, window, material, tree, shrub, lawn boundary, object and camera perspective exactly. Do not add fixtures or change the property. Do not create a dark night scene, black silhouettes, crushed shadows or dramatic underexposure.';

    public const DECLUTTER_PROMPT = 'Make minimal localized removals of clearly identifiable everyday clutter ONLY: scattered loose paper sheets and envelopes, discarded packaging and rubbish, loose laundry, and scattered toys. Reconstruct only the small surfaces hidden by those removed items. Preserve EVERY other object in its exact original position, shape, size and appearance. In particular KEEP all furniture, appliances, computers, monitors, keyboards, mice, functional cables, lamps, cushions, rugs, artwork, plants and planters, decorative bowls and vases, cups and mugs, books and notebooks, shelving, storage and intentional decor. A tidy-looking object is not clutter merely because it is on a table, desk or sideboard. If an item is ambiguous, KEEP it. Preserve the exact architecture, windows and outdoor view, doors, cabinetry, flooring, materials, camera viewpoint, crop, lighting, shadows and colors. Do not simplify, restyle, rearrange, repaint, relight or enhance the room. Leave all areas outside the removed clutter unchanged. If the specified loose clutter is absent, return the original photo unchanged.';

    public const GRASS_PROMPT = 'Edit ONLY the existing lawn grass to achieve a lush, dense, healthy, freshly maintained real-estate lawn. Replace every visible dry yellow, straw-brown, sparse or dead patch WITHIN the existing lawn with realistic dense natural green grass matching the surrounding blade texture, scale and mowing direction. Make the entire existing lawn consistently rich green, with believable variation, directional sunlight, tree shade and contact shadows; no neon green, flat paint, plastic carpet or artificial turf. Keep the exact existing grass boundaries, shape, slopes and perspective. Do not extend grass over any driveway, pavement, path, mulch, flower bed, stone, tree trunk or shrub. Preserve every building, roof, wall, window, door, fixture, tree, bush, flower, garden feature, fence and object exactly. Preserve the sky, time of day, camera position and framing. The requested result is a visibly rejuvenated green lawn, not merely a brighter version of the existing dry grass.';

    public const TWILIGHT_PROMPT = 'Transform this exact daylight exterior into a premium real-estate digital twilight photograph at vivid sunset. The sky must have a clearly visible dramatic but photorealistic layered sunset: warm orange and coral glow near the horizon, saturated rosy pink and magenta illuminated cloud streaks, and lavender-purple upper clouds. Match the elegant colorful sunset look of professional luxury-property twilight marketing; do not leave a pale daytime or plain blue sky. Lower the ambient exposure to believable dusk while keeping facade materials, rooflines, driveway, lawn and landscaping readable, with natural cooler shaded foreground and balanced shadows. Add soft warm golden interior illumination ONLY behind the existing windows and turn on ONLY existing exterior light fixtures. Retain window panes, muntins, frames and reflections; keep realistic light intensity without solid orange glowing rectangles or blown highlights. Do NOT create, add, remove or move light fixtures, windows, doors or any other objects. Preserve all architecture, roof edges, building proportions, materials, driveway and garden boundaries, trees and shrubs, camera viewpoint, crop and composition exactly. The requested change is sunset sky and dusk illumination, not renovation or restyling. Avoid black silhouettes, crushed shadows and excessive darkness.';

    public static function readiness(string $service, array $options = []): array
    {
        $recipe = self::definition($service, $options);

        return app(\App\Services\Studio\StudioProviderSettings::class)->readiness($recipe['routeKey'], $recipe['route']);
    }

    public static function definition(string $service, array $options = [], ?string $version = null): array
    {
        if (! isset(self::PRESETS[$service])) {
            throw new \InvalidArgumentException('Unsupported paid editing service.');
        }
        $version ??= $service === 'staging' ? 'preserve-v1' : self::VERSION;
        if (! in_array($version, ['preserve-v1', self::VERSION], true)) {
            throw new \InvalidArgumentException('Unsupported editing recipe version.');
        }
        $legacy = $version === 'preserve-v1';
        $adjustments = [];
        if ($service === 'staging') {
            $adjustments = ['roomType' => $options['roomType'] ?? 'living', 'furnitureStyle' => $options['style'] ?? 'modern', 'removal' => 'off', 'addFurniture' => true, 'variationCount' => 1, 'watermark' => false, 'useDetectedMask' => false];
            VirtualStagingOptions::assert($adjustments);
        }

        return ['version' => $version, 'preset' => self::PRESETS[$service], 'type' => $service === 'decluttering' ? 'revision' : 'generate',
            'prompt' => match ($service) {
                'twilight' => $legacy ? self::PREVIOUS_TWILIGHT_PROMPT : self::TWILIGHT_PROMPT, 'grass' => $legacy ? '' : self::GRASS_PROMPT, default => ''
            }, 'adjustments' => $adjustments,
            'payload' => $service === 'decluttering' ? ['mediaId' => 'source', 'prompt' => $legacy ? self::PREVIOUS_DECLUTTER_PROMPT : self::DECLUTTER_PROMPT] : [],
            'routeKey' => $service === 'decluttering' ? 'revision' : self::PRESETS[$service],
            'route' => match ($service) {
                'staging' => ['provider' => 'virtualstagingai', 'model' => 'staging', 'fallback' => null],
                'decluttering', 'twilight' => ['provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit', 'fallback' => null],
                'grass' => $legacy ? ['provider' => 'autoenhance', 'model' => 'enhance', 'fallback' => null] : ['provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit', 'fallback' => null],
                default => ['provider' => 'autoenhance', 'model' => 'enhance', 'fallback' => null],
            }];
    }
}
