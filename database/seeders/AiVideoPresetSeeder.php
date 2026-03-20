<?php

namespace Database\Seeders;

use App\Models\AiVideoPreset;
use Illuminate\Database\Seeder;

class AiVideoPresetSeeder extends Seeder
{
    public function run(): void
    {
        $presets = [
            [
                'slug' => 'weather',
                'name' => 'Weather Effects',
                'description' => 'Add cinematic weather effects to property exteriors',
                'icon' => 'Cloud',
                'category' => 'Lighting',
                'prompt_template' => 'Add cinematic weather effects — dramatic clouds, rain, or snow — to this real estate exterior, maintaining property visibility',
                'max_frames' => 1,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'slug' => 'twilight',
                'name' => 'Twilight',
                'description' => 'Transform property exteriors into golden hour twilight scenes',
                'icon' => 'Sunset',
                'category' => 'Lighting',
                'prompt_template' => 'Transform this property exterior into a stunning golden hour twilight scene with warm ambient lighting, glowing windows, and a dramatic sunset sky',
                'max_frames' => 1,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'day_to_night',
                'name' => 'Day to Night',
                'description' => 'Smooth cinematic transition from daytime to nighttime',
                'icon' => 'Moon',
                'category' => 'Lighting',
                'prompt_template' => 'Create a smooth cinematic transition from bright daytime to elegant nighttime with interior lights turning on and sky darkening',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'virtual_staging',
                'name' => 'Virtual Staging',
                'description' => 'Add realistic furniture and decor to empty rooms',
                'icon' => 'Sofa',
                'category' => 'Staging',
                'prompt_template' => 'Add realistic, elegant modern furniture and decor to this empty room, creating an inviting lived-in atmosphere',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'slug' => 'drone_flyover',
                'name' => 'Drone Flyover',
                'description' => 'Smooth aerial drone flyover of the property',
                'icon' => 'Plane',
                'category' => 'Movement',
                'prompt_template' => 'Create a smooth aerial drone flyover video starting from a wide establishing shot and slowly descending toward the property',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'slug' => 'entrance_walkthrough',
                'name' => 'Entrance Walk',
                'description' => 'Cinematic walkthrough approaching the entrance',
                'icon' => 'DoorOpen',
                'category' => 'Movement',
                'prompt_template' => 'Create a cinematic first-person walkthrough approaching and entering through the front entrance of this property',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'slug' => 'lifestyle',
                'name' => 'Lifestyle',
                'description' => 'Add lifestyle elements to make properties feel alive',
                'icon' => 'Users',
                'category' => 'Staging',
                'prompt_template' => 'Add lifestyle elements — people relaxing, subtle movement, warm lighting — to make this property feel alive and inviting',
                'max_frames' => 1,
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'slug' => 'construction_progress',
                'name' => 'Construction',
                'description' => 'Show construction progress transformation',
                'icon' => 'HardHat',
                'category' => 'Specialty',
                'prompt_template' => 'Show a time-lapse style construction progress transformation between these two stages of the build',
                'max_frames' => 2,
                'is_active' => true,
                'sort_order' => 7,
            ],
        ];

        foreach ($presets as $preset) {
            AiVideoPreset::updateOrCreate(
                ['slug' => $preset['slug']],
                $preset
            );
        }
    }
}
