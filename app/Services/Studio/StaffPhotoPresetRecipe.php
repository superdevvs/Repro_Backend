<?php

namespace App\Services\Studio;

use App\Services\ListingStudio\ListingStudioPaidRecipe;

/** Standard staff presets share Listing Studio's server-owned photo recipes. */
final class StaffPhotoPresetRecipe
{
    public static function operationFields(string $preset): array
    {
        $recipe = self::definition($preset);

        return $recipe ? ['recipeVersion' => $recipe['version'], 'presetPrompt' => $recipe['prompt']] : [];
    }

    public static function definition(string $preset): ?array
    {
        $service = ['decluttering' => 'decluttering', 'green-grass' => 'grass', 'twilight' => 'twilight'][$preset] ?? null;
        if ($service === null) {
            return null;
        }
        $recipe = ListingStudioPaidRecipe::definition($service);

        return ['version' => $recipe['version'], 'prompt' => $service === 'decluttering' ? $recipe['payload']['prompt'] : $recipe['prompt'], 'route' => $recipe['route']];
    }
}
