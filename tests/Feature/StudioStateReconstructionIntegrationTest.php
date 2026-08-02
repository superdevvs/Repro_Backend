<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\BrandState;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\Shoot;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration coverage for reconstructing Studio state from persisted records.
 *
 * Validates: Requirements 7.7, 7.8, 10.5, 10.6, 10.7.
 */
class StudioStateReconstructionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_member_reconstructs_queue_projects_templates_and_brand_on_fresh_requests(): void
    {
        $owner = $this->teamUser('admin', 501);
        $peer = $this->teamUser('editing_manager', 501);
        $outsider = $this->teamUser('admin', 502);
        $shoot = Shoot::factory()->create(['address' => '501 Persistent Way']);

        $project = Project::create([
            'team_id' => 501,
            'created_by' => $owner->id,
            'shoot_id' => $shoot->id,
            'name' => 'Persistent Listing',
            'address' => $shoot->address,
            'source_type' => 'shoot',
            'workflow_id' => 'photo-enhancement',
            'status' => 'submitted',
        ]);
        ProjectMedia::create([
            'project_id' => $project->id,
            'team_id' => 501,
            'created_by' => $owner->id,
            'media_ref' => '/studio-assets/selected-shoot.webp',
            'kind' => 'source',
        ]);
        $job = AiEditingJob::create([
            'project_id' => $project->id,
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'status' => AiEditingJob::STATUS_PROCESSING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => '/studio-assets/selected-shoot.webp',
            'progress' => 45,
        ]);
        $template = Template::create([
            'team_id' => 501,
            'created_by' => $owner->id,
            'name' => 'Persistent Polish',
            'workflow_id' => 'photo-enhancement',
            'config' => ['exposure' => 12],
        ]);
        BrandState::create([
            'team_id' => 501,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'settings' => ['logo' => 'brands/team-501.svg'],
        ]);

        Sanctum::actingAs($peer);

        $this->getJson('/api/studio/projects')
            ->assertOk()
            ->assertJsonPath('data.0.id', $project->id)
            ->assertJsonPath('data.0.mediaCount', 1);
        $this->getJson('/api/studio/queue')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'photo-'.$job->id)
            ->assertJsonPath('data.0.status', AiEditingJob::STATUS_PROCESSING)
            ->assertJsonPath('data.0.progress', null);
        $this->getJson('/api/studio/templates')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $template->id)
            ->assertJsonPath('data.0.config.exposure', 12);
        $this->getJson('/api/studio/brand')
            ->assertOk()
            ->assertJsonPath('data.settings.logo', 'brands/team-501.svg');

        // A fresh authenticated request from a different team must reconstruct
        // only its own empty state, proving no client/session cache is required.
        Sanctum::actingAs($outsider);
        $this->getJson('/api/studio/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/studio/queue')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/studio/templates')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/studio/brand')
            ->assertOk()
            ->assertJsonPath('data.teamId', 502)
            ->assertJsonPath('data.settings', []);
    }

    private function teamUser(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }
}
