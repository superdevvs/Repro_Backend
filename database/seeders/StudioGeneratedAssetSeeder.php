<?php

namespace Database\Seeders;

use App\Models\GeneratedAsset;
use App\Models\User;
use Illuminate\Database\Seeder;

class StudioGeneratedAssetSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::query()
            ->orderByRaw("case when role in ('super_admin', 'admin') then 0 else 1 end")
            ->orderBy('id')
            ->first();

        if (! $creator) {
            $this->command?->warn('Studio generated assets were not seeded because no user exists.');

            return;
        }

        foreach ($this->assets() as $asset) {
            GeneratedAsset::query()->updateOrCreate(
                [
                    'team_id' => $creator->id,
                    'instruction_index' => $asset['instruction_index'],
                ],
                [
                    'created_by' => $creator->id,
                    'instruction_text' => $asset['instruction_text'],
                    'asset_path' => $asset['asset_path'],
                    'placement' => $asset['placement'],
                    'alt_text' => $asset['alt_text'],
                    'status' => 'produced',
                ],
            );
        }
    }

    /**
     * @return array<int, array{
     *     instruction_index: int,
     *     instruction_text: string,
     *     asset_path: string,
     *     placement: string,
     *     alt_text: string
     * }>
     */
    private function assets(): array
    {
        return [
            [
                'instruction_index' => 1,
                'instruction_text' => 'Wide front-facing contemporary two-storey luxury suburban house during a dull overcast afternoon, photographed as a realistic unedited listing from a locked symmetrical camera angle.',
                'asset_path' => 'hero-before.webp',
                'placement' => 'hero-before',
                'alt_text' => 'Contemporary luxury home before AI twilight enhancement',
            ],
            [
                'instruction_index' => 2,
                'instruction_text' => 'The exact same contemporary house and locked camera angle transformed into a premium twilight real-estate photograph with a dramatic sunset and warm interior lighting.',
                'asset_path' => 'hero-after.webp',
                'placement' => 'hero-after',
                'alt_text' => 'The same contemporary luxury home after AI twilight enhancement',
            ],
            [
                'instruction_index' => 3,
                'instruction_text' => 'Compact front-facing real-estate thumbnail of a modern white and grey suburban home with a dark roof, driveway, lawn, and soft daylight.',
                'asset_path' => 'selected-shoot.webp',
                'placement' => 'selected-shoot',
                'alt_text' => 'Selected modern suburban property',
            ],
            [
                'instruction_index' => 4,
                'instruction_text' => 'Bright luxury open-plan living room connected to a modern kitchen, photographed with balanced verticals and abundant natural daylight.',
                'asset_path' => 'queue-photo-enhancement.webp',
                'placement' => 'queue-photo-enhancement',
                'alt_text' => 'Bright open-plan living room queued for photo enhancement',
            ],
            [
                'instruction_index' => 5,
                'instruction_text' => 'Modern two-storey luxury residence photographed at blue hour with warm amber windows, architectural lighting, and a deep blue sky.',
                'asset_path' => 'queue-twilight.webp',
                'placement' => 'queue-twilight',
                'alt_text' => 'Modern luxury residence queued for twilight conversion',
            ],
            [
                'instruction_index' => 6,
                'instruction_text' => 'Wide-angle frame from a polished luxury property walkthrough showing a bright contemporary open-plan kitchen and living room.',
                'asset_path' => 'queue-video-cleanup.webp',
                'placement' => 'queue-video-cleanup',
                'alt_text' => 'Open-plan interior queued for video cleanup',
            ],
            [
                'instruction_index' => 7,
                'instruction_text' => 'Elegant high-end living room in bright daylight with a white sectional, sculptural lounge chairs, glass coffee table, pale oak floor, fireplace, and tall windows.',
                'asset_path' => 'workflow-photo-enhancement.webp',
                'placement' => 'workflow-photo-enhancement',
                'alt_text' => 'Enhanced luxury living room',
            ],
            [
                'instruction_index' => 8,
                'instruction_text' => 'Wide cinematic exterior of a large modern luxury home at twilight with a charcoal and timber facade, amber windows, landscaping, and an indigo sky.',
                'asset_path' => 'workflow-twilight.webp',
                'placement' => 'workflow-twilight',
                'alt_text' => 'Modern luxury home at twilight',
            ],
            [
                'instruction_index' => 9,
                'instruction_text' => 'Bright contemporary luxury kitchen with a white waterfall island, timber cabinetry, pale stone, expansive windows, and polished walkthrough-video framing.',
                'asset_path' => 'workflow-video-cleanup.webp',
                'placement' => 'workflow-video-cleanup',
                'alt_text' => 'Polished luxury kitchen walkthrough frame',
            ],
            [
                'instruction_index' => 10,
                'instruction_text' => 'Dramatic three-quarter exterior of an angular contemporary luxury house at dusk with stone, timber, glowing windows, a double garage, and central overlay space.',
                'asset_path' => 'workflow-listing-video.webp',
                'placement' => 'workflow-listing-video',
                'alt_text' => 'Cinematic luxury home listing video frame',
            ],
            [
                'instruction_index' => 11,
                'instruction_text' => 'Premium social-media real-estate composition with a softly blurred luxury living room and a modern smartphone showing a vertical property walkthrough.',
                'asset_path' => 'workflow-reel-generator.webp',
                'placement' => 'workflow-reel-generator',
                'alt_text' => 'Smartphone showing a vertical property reel',
            ],
            [
                'instruction_index' => 12,
                'instruction_text' => 'Organised six-image contact sheet of multiple professionally photographed modern luxury homes in a clean two-row grid with cohesive grading.',
                'asset_path' => 'workflow-batch-ai-jobs.webp',
                'placement' => 'workflow-batch-ai-jobs',
                'alt_text' => 'Six luxury property photos prepared for batch processing',
            ],
        ];
    }
}
