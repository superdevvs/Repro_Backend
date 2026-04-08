<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EditorRatesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_view_their_own_rates(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
            'metadata' => [
                'photo_edit_rate' => 0.45,
                'video_edit_rate' => 20,
                'service_rates' => [
                    [
                        'service_id' => 'service-photo-1',
                        'service_name' => '10 Exterior HDR Photos',
                        'rate' => 0.45,
                    ],
                    [
                        'service_id' => 'service-video-1',
                        'service_name' => '40 HDR + 1 Min Vertical Video',
                        'rate' => 20,
                    ],
                ],
            ],
        ]);

        Sanctum::actingAs($editor);

        $response = $this->getJson("/api/editors/{$editor->id}/rates");

        $response->assertOk();
        $response->assertJsonPath('data.photo_edit_rate', 0.45);
        $response->assertJsonPath('data.video_edit_rate', 20);
        $response->assertJsonPath('data.service_rates.0.service_id', 'service-photo-1');
        $response->assertJsonPath('data.service_rates.1.service_name', '40 HDR + 1 Min Vertical Video');
    }

    public function test_admin_can_view_and_update_editor_rates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create([
            'role' => 'editor',
            'metadata' => [
                'service_rates' => [],
            ],
        ]);

        Sanctum::actingAs($admin);

        $showResponse = $this->getJson("/api/editors/{$editor->id}/rates");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.service_rates', []);

        $updateResponse = $this->putJson("/api/editors/{$editor->id}/rates", [
            'service_rates' => [
                [
                    'service_id' => 'svc-1',
                    'service_name' => '10 Exterior HDR Photos',
                    'rate' => 0.5,
                ],
            ],
        ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.photo_edit_rate', 0.5);
        $updateResponse->assertJsonPath('data.service_rates.0.service_id', 'svc-1');
        $updateResponse->assertJsonPath('data.service_rates.0.rate', 0.5);
    }

    public function test_editor_rate_updates_round_trip_saved_service_rows(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
            'metadata' => [],
        ]);

        Sanctum::actingAs($editor);

        $payload = [
            'service_rates' => [
                [
                    'service_id' => 'svc-photo',
                    'service_name' => '10 Exterior HDR Photos',
                    'rate' => 0.45,
                ],
                [
                    'service_id' => 'svc-video',
                    'service_name' => '40 HDR + 1 Min Vertical Video',
                    'rate' => 20,
                ],
                [
                    'service_id' => null,
                    'service_name' => 'Virtual Staging (per image)',
                    'rate' => 12.5,
                ],
            ],
        ];

        $updateResponse = $this->putJson("/api/editors/{$editor->id}/rates", $payload);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.photo_edit_rate', 0.45);
        $updateResponse->assertJsonPath('data.video_edit_rate', 20);
        $updateResponse->assertJsonPath('data.virtual_staging_rate', 12.5);
        $updateResponse->assertJsonPath('data.service_rates.2.service_name', 'Virtual Staging (per image)');

        $showResponse = $this->getJson("/api/editors/{$editor->id}/rates");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.service_rates.0.service_id', 'svc-photo');
        $showResponse->assertJsonPath('data.service_rates.1.service_name', '40 HDR + 1 Min Vertical Video');
        $showResponse->assertJsonPath('data.service_rates.2.rate', 12.5);
    }

    public function test_editor_can_persist_an_explicitly_empty_service_rate_list(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
            'metadata' => [
                'photo_edit_rate' => 0.45,
                'video_edit_rate' => 20,
                'virtual_staging_rate' => 12,
                'service_rates' => [
                    [
                        'service_id' => 'svc-photo',
                        'service_name' => '10 Exterior HDR Photos',
                        'rate' => 0.45,
                    ],
                ],
            ],
        ]);

        Sanctum::actingAs($editor);

        $response = $this->putJson("/api/editors/{$editor->id}/rates", [
            'service_rates' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.service_rates', []);
        $response->assertJsonPath('data.photo_edit_rate', 0);
        $response->assertJsonPath('data.video_edit_rate', 0);
        $response->assertJsonPath('data.virtual_staging_rate', 0);
    }

    public function test_non_admin_user_cannot_read_or_update_another_editors_rates(): void
    {
        $viewer = User::factory()->create(['role' => 'photographer']);
        $editor = User::factory()->create(['role' => 'editor']);

        Sanctum::actingAs($viewer);

        $this->getJson("/api/editors/{$editor->id}/rates")
            ->assertForbidden();

        $this->putJson("/api/editors/{$editor->id}/rates", [
            'service_rates' => [
                [
                    'service_name' => '10 Exterior HDR Photos',
                    'rate' => 0.45,
                ],
            ],
        ])->assertForbidden();
    }
}
