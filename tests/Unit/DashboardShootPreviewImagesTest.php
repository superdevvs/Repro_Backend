<?php

namespace Tests\Unit;

use App\Http\Controllers\API\DashboardController;
use App\Models\Shoot;
use App\Models\ShootFile;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class DashboardShootPreviewImagesTest extends TestCase
{
    private function previewsFor(Shoot $shoot): array
    {
        $method = new ReflectionMethod(DashboardController::class, 'buildShootPreviewImages');
        $method->setAccessible(true);

        return $method->invoke(new DashboardController(), $shoot);
    }

    private function file(array $attrs): ShootFile
    {
        $file = new ShootFile($attrs);
        $file->id = $attrs['id'];
        $file->exists = true;

        return $file;
    }

    public function test_it_skips_floorplans_and_prefers_edited_photo_grids_for_keysville_style_ordering(): void
    {
        $floorplanGrid = 'shoots/166/grids/0-5933-keysville-road-keymar-md-united-states-0-6d830b71_grid.jpg';
        $exteriorGrid = 'shoots/166/grids/5933 Keysville Rd-2847_0116_grid.jpg';

        $shoot = new Shoot();
        $shoot->id = 166;
        $shoot->setRelation('files', new Collection([
            $this->file([
                'id' => 10625,
                'shoot_id' => 166,
                'workflow_stage' => ShootFile::STAGE_VERIFIED,
                'is_cover' => false,
                'is_hidden' => false,
                'media_type' => 'floorplan',
                'filename' => '0_5933-keysville-road-keymar-md-united-states_0.jpg',
                'stored_filename' => '0-5933-keysville-road-keymar-md-united-states-0-6d830b71.jpg',
                'path' => 'shoots/166/floorplans/0-5933-keysville-road-keymar-md-united-states-0-6d830b71.jpg',
                'grid_path' => $floorplanGrid,
                'file_type' => 'image/jpeg',
                'mime_type' => 'image/jpeg',
                'sort_order' => 3,
            ]),
            $this->file([
                'id' => 10794,
                'shoot_id' => 166,
                'workflow_stage' => ShootFile::STAGE_VERIFIED,
                'is_cover' => false,
                'is_hidden' => false,
                'media_type' => 'edited',
                'filename' => '5933 Keysville Rd-2847_0116.jpg',
                'stored_filename' => 'COMPLETED_5933 Keysville Rd-2847_0116.jpg',
                'path' => 'shoots/166/completed/COMPLETED_5933 Keysville Rd-2847_0116.jpg',
                'grid_path' => $exteriorGrid,
                'file_type' => 'image/jpeg',
                'mime_type' => 'image/jpeg',
                'sort_order' => 7,
            ]),
        ]));

        $previews = $this->previewsFor($shoot);

        $this->assertNotEmpty($previews);
        $decodedFirst = rawurldecode($previews[0]);
        $this->assertStringContainsString('5933 Keysville Rd-2847_0116_grid.jpg', $decodedFirst);
        foreach ($previews as $url) {
            $decoded = rawurldecode($url);
            $this->assertStringNotContainsString('6d830b71', $decoded);
            $this->assertStringNotContainsString('/floorplans/', $decoded);
        }
    }

    public function test_it_skips_misclassified_cubicasa_grids_and_videos(): void
    {
        $cubicasaGrid = 'shoots/166/grids/0-5933-keysville-road-keymar-md-united-states-0-6d830b71_grid.jpg';
        $snapGrid = 'shoots/145/grids/003_SNAP4206_grid.jpg';

        $shoot = new Shoot();
        $shoot->id = 166;
        $shoot->setRelation('files', new Collection([
            $this->file([
                'id' => 1,
                'shoot_id' => 166,
                'workflow_stage' => ShootFile::STAGE_VERIFIED,
                'is_cover' => false,
                'is_hidden' => false,
                // Misclassified / missing media_type — path heuristics must still skip.
                'media_type' => null,
                'filename' => '0-5933-keysville-road-keymar-md-united-states-0-6d830b71.jpg',
                'stored_filename' => '0-5933-keysville-road-keymar-md-united-states-0-6d830b71.jpg',
                'path' => $cubicasaGrid,
                'grid_path' => $cubicasaGrid,
                'file_type' => 'image/jpeg',
                'mime_type' => 'image/jpeg',
            ]),
            $this->file([
                'id' => 2,
                'shoot_id' => 166,
                'workflow_stage' => ShootFile::STAGE_VERIFIED,
                'is_cover' => false,
                'is_hidden' => false,
                'media_type' => 'video',
                'filename' => 'walkthrough.mp4',
                'stored_filename' => 'walkthrough.mp4',
                'path' => 'shoots/166/videos/walkthrough.mp4',
                'grid_path' => null,
                'file_type' => 'video/mp4',
                'mime_type' => 'video/mp4',
            ]),
            $this->file([
                'id' => 3,
                'shoot_id' => 166,
                'workflow_stage' => ShootFile::STAGE_VERIFIED,
                'is_cover' => false,
                'is_hidden' => false,
                'media_type' => 'edited',
                'filename' => '003_SNAP4206.jpg',
                'stored_filename' => '003_SNAP4206.jpg',
                'path' => 'shoots/145/completed/003_SNAP4206.jpg',
                'grid_path' => $snapGrid,
                'file_type' => 'image/jpeg',
                'mime_type' => 'image/jpeg',
            ]),
        ]));

        $previews = $this->previewsFor($shoot);

        $this->assertCount(1, $previews);
        $this->assertStringContainsString('003_SNAP4206_grid.jpg', $previews[0]);
    }
}
