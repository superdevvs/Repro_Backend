<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EditorTourLinksEmbedMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_video_editor_can_merge_embed_tour_links_only(): void
    {
        $videoEditor = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);
        $photoEditor = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['photo']],
        ]);
        $unassigned = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);

        $category = Category::firstOrCreate(['name' => 'Photos & Video']);
        $service = Service::factory()->photoVideoIntake()->create([
            'category_id' => $category->id,
            'name' => 'HDR Photos & Video',
        ]);

        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
            'tour_links' => [
                'branded' => 'https://tour.example/branded',
                'video_link' => 'https://old.example/video',
            ],
            'editor_id' => null,
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 100,
            'quantity' => 1,
            'editor_id' => $photoEditor->id,
            'video_editor_id' => $videoEditor->id,
        ]);

        $embeds = [
            ['id' => 'e1', 'url' => 'https://player.vimeo.com/video/1', 'sort_order' => 0, 'label' => 'Walkthrough'],
            ['id' => 'e2', 'url' => 'https://www.youtube.com/embed/abc', 'sort_order' => 1],
        ];

        $this->actingAs($videoEditor)
            ->patchJson('/api/shoots/'.$shoot->id, [
                'tour_links' => [
                    'embeds' => $embeds,
                    'video_link' => 'https://player.vimeo.com/video/1',
                    'featured_embed_id' => 'e1',
                ],
            ])
            ->assertOk();

        $shoot->refresh();
        $links = $shoot->tour_links;
        $this->assertSame('https://tour.example/branded', $links['branded']);
        $this->assertSame('https://player.vimeo.com/video/1', $links['video_link']);
        $this->assertSame('e1', $links['featured_embed_id']);
        $this->assertCount(2, $links['embeds']);

        $this->actingAs($videoEditor)
            ->patchJson('/api/shoots/'.$shoot->id, [
                'tour_links' => [
                    'branded' => 'https://evil.example/hijack',
                ],
            ])
            ->assertForbidden();

        $this->actingAs($videoEditor)
            ->patchJson('/api/shoots/'.$shoot->id, [
                'address' => 'Should Not Change',
                'tour_links' => [
                    'video_link' => 'https://player.vimeo.com/video/2',
                ],
            ])
            ->assertForbidden();

        $this->actingAs($unassigned)
            ->patchJson('/api/shoots/'.$shoot->id, [
                'tour_links' => [
                    'video_link' => 'https://player.vimeo.com/video/3',
                ],
            ])
            ->assertForbidden();

        $this->assertSame('https://tour.example/branded', $shoot->fresh()->tour_links['branded']);
    }

    public function test_delivered_incomplete_video_lane_stays_on_video_editor_completed_tab(): void
    {
        $videoEditor = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);
        $photoEditor = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['photo']],
        ]);

        $category = Category::firstOrCreate(['name' => 'Photos & Video']);
        $service = Service::factory()->photoVideoIntake()->create([
            'category_id' => $category->id,
            'name' => 'HDR Photos & Video',
        ]);

        $shoot = Shoot::factory()->create([
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'editor_id' => null,
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 100,
            'quantity' => 1,
            'editor_id' => $photoEditor->id,
            'video_editor_id' => $videoEditor->id,
            'editing_completed_at' => now(),
            'video_editing_completed_at' => null,
        ]);

        $response = $this->actingAs($videoEditor)
            ->getJson('/api/shoots?tab=completed&no_cache=true')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($shoot->id, $ids);

        DB::table('shoot_service')->where('shoot_id', $shoot->id)->update([
            'video_editing_completed_at' => now(),
        ]);

        $after = $this->actingAs($videoEditor)
            ->getJson('/api/shoots?tab=completed&no_cache=true')
            ->assertOk();
        $afterIds = collect($after->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains($shoot->id, $afterIds);
    }
}
