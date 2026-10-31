<?php

namespace App\Services\ListingStudio;

use App\Services\Studio\VirtualStagingOptions;

/** Versioned server-owned recipe; clients cannot select prompts, routes or provider controls. */
class ListingStudioPaidRecipe
{
    public const PRESETS = ['staging' => 'virtual-staging', 'decluttering' => 'listing-ready', 'grass' => 'green-grass', 'twilight' => 'twilight'];

    public const LEGACY_DECLUTTER_PROMPT = 'Remove loose everyday clutter such as scattered clothing, papers, toys, trash and loose countertop items. Preserve all furniture, permanent fixtures, appliances, cabinetry, doors, windows, flooring, architectural details, room layout and camera perspective. Do not empty the room, add furniture or alter the property.';

    public const DECLUTTER_PROMPT = self::LEGACY_DECLUTTER_PROMPT.' Preserve intentional cushions, rugs, artwork, lamps and decorative objects. If none of the defined loose clutter is present, leave the photo unchanged. Do not remove or replace furniture or decor to create a more minimal room.';

    public const TWILIGHT_PROMPT = 'Create a bright early blue-hour real estate marketing photograph just after sunset. Keep the entire facade, roof, driveway, lawn and landscaping clearly readable with balanced natural exposure and detail in shadows. Use a soft natural blue-hour sky and warm light only in existing windows and existing light fixtures. Preserve every building, roofline, opening, door, window, material, tree, shrub, lawn boundary, object and camera perspective exactly. Do not add fixtures or change the property. Do not create a dark night scene, black silhouettes, crushed shadows or dramatic underexposure.';

    public static function readiness(string $service, array $options = []): array
    {
        $recipe = self::definition($service, $options);

        return app(\App\Services\Studio\StudioProviderSettings::class)->readiness($recipe['routeKey'], $recipe['route']);
    }

    public static function definition(string $service, array $options = []): array
    {
        if (! isset(self::PRESETS[$service])) {
            throw new \InvalidArgumentException('Unsupported paid editing service.');
        }
        $adjustments = [];
        if ($service === 'staging') {
            $adjustments = ['roomType' => $options['roomType'] ?? 'living', 'furnitureStyle' => $options['style'] ?? 'modern', 'removal' => 'off', 'addFurniture' => true, 'variationCount' => 1, 'watermark' => false, 'useDetectedMask' => false];
            VirtualStagingOptions::assert($adjustments);
        }

        return ['version' => 'preserve-v1', 'preset' => self::PRESETS[$service], 'type' => $service === 'decluttering' ? 'revision' : 'generate',
            'prompt' => $service === 'twilight' ? self::TWILIGHT_PROMPT : '', 'adjustments' => $adjustments,
            'payload' => $service === 'decluttering' ? ['mediaId' => 'source', 'prompt' => self::DECLUTTER_PROMPT] : [],
            'routeKey' => $service === 'decluttering' ? 'revision' : self::PRESETS[$service],
            'route' => match ($service) {
                'staging' => ['provider' => 'virtualstagingai', 'model' => 'staging', 'fallback' => null],
                'decluttering', 'twilight' => ['provider' => 'fal', 'model' => 'fal-ai/nano-banana-pro/edit', 'fallback' => null],
                default => ['provider' => 'autoenhance', 'model' => 'enhance', 'fallback' => null],
            }];
    }
}
