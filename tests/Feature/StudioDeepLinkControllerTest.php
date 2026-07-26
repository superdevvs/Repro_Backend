<?php

namespace Tests\Feature;

use App\Models\AiEditingJob;
use App\Models\AiListingVideoJob;
use App\Models\AiReelJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Targeted endpoint coverage for authorized Studio deep-link resolution.
 *
 * Validates: Requirements 1.8, 1.9, 15.2, 16.9
 */
class StudioDeepLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/deep-links/resolve';

    public function test_resolves_a_valid_destination_without_a_record_reference(): void
    {
        Sanctum::actingAs($this->actor('editor', 41));

        $this->postJson(self::URL, ['destination' => 'metrics'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.destination', 'metrics')
            ->assertJsonPath('data.record', null);
    }

    public function test_resolves_each_supported_record_type_and_ai_job_namespace(): void
    {
        $user = $this->actor('editor', 42);
        $shoot = $this->shoot($user, '42 Authorized Avenue');
        $project = $this->project(42, $user, $shoot, 'Authorized Project');
        $template = $this->template(42, $user, 'Authorized Template');
        $photo = $this->photoJob($user, $shoot, $project);
        $video = $this->videoJob($user, $shoot, $project);
        $reel = $this->reelJob($user, $shoot);
        Sanctum::actingAs($user);

        $references = [
            ['projects', 'project', (string) $project->id],
            ['photo-enhancement', 'shoot', (string) $shoot->id],
            ['templates', 'template', (string) $template->id],
            ['twilight', 'workflow', 'twilight'],
            ['queue', 'ai_job', 'photo-' . $photo->id],
            ['queue', 'ai_job', 'video-' . $video->id],
            ['queue', 'ai_job', 'reel-' . $reel->id],
        ];

        foreach ($references as [$destination, $recordType, $recordId]) {
            $this->postJson(self::URL, compact('destination', 'recordType', 'recordId'))
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.destination', $destination)
                ->assertJsonPath('data.record.recordType', $recordType)
                ->assertJsonPath('data.record.id', $recordId);
        }
    }

    public function test_missing_and_malformed_references_return_generic_not_found(): void
    {
        Sanctum::actingAs($this->actor('editor', 43));

        foreach (['not-a-number', 'photo-0', 'unknown-9'] as $recordId) {
            $response = $this->postJson(self::URL, [
                'destination' => 'queue',
                'recordType' => 'ai_job',
                'recordId' => $recordId,
            ]);

            $response->assertNotFound()
                ->assertExactJson([
                    'success' => false,
                    'error' => [
                        'code' => 'studio_record_not_found',
                        'message' => 'The requested Studio record was not found.',
                    ],
                ]);
        }
    }

    public function test_out_of_scope_references_return_generic_forbidden_without_record_data(): void
    {
        $requester = $this->actor('editor', 50);
        $outsider = $this->actor('editor', 51);
        $shoot = $this->shoot($outsider, 'Restricted Secret Shoot');
        $project = $this->project(51, $outsider, $shoot, 'Restricted Secret Project');
        $template = $this->template(51, $outsider, 'Restricted Secret Template');
        $job = $this->photoJob($outsider, $shoot, $project);
        Sanctum::actingAs($requester);

        $references = [
            ['projects', 'project', (string) $project->id],
            ['command-center', 'shoot', (string) $shoot->id],
            ['templates', 'template', (string) $template->id],
            ['queue', 'ai_job', 'photo-' . $job->id],
        ];

        foreach ($references as [$destination, $recordType, $recordId]) {
            $response = $this->postJson(self::URL, compact('destination', 'recordType', 'recordId'));

            $response->assertForbidden()
                ->assertExactJson([
                    'success' => false,
                    'error' => [
                        'code' => 'studio_record_forbidden',
                        'message' => 'You are not authorized to access the requested Studio record.',
                    ],
                ]);
            $this->assertStringNotContainsString('Restricted Secret', $response->getContent());
            $this->assertStringNotContainsString($recordId, $response->getContent());
        }
    }

    public function test_privileged_user_can_resolve_peer_records_only_inside_their_team(): void
    {
        $admin = $this->actor('admin', 60);
        $peer = $this->actor('editor', 60);
        $outsider = $this->actor('editor', 61);
        $peerProject = $this->project(60, $peer, null, 'Peer Team Project');
        $outsideProject = $this->project(61, $outsider, null, 'Outside Team Project');
        Sanctum::actingAs($admin);

        $this->postJson(self::URL, [
            'destination' => 'projects',
            'recordType' => 'project',
            'recordId' => (string) $peerProject->id,
        ])->assertOk()->assertJsonPath('data.record.name', 'Peer Team Project');

        $this->postJson(self::URL, [
            'destination' => 'projects',
            'recordType' => 'project',
            'recordId' => (string) $outsideProject->id,
        ])->assertForbidden()->assertJsonMissing(['name' => 'Outside Team Project']);
    }

    public function test_rejects_invalid_destination_type_and_incomplete_record_reference(): void
    {
        Sanctum::actingAs($this->actor('editor', 70));

        $this->postJson(self::URL, ['destination' => 'not-a-studio-destination'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message']);

        $this->postJson(self::URL, [
            'destination' => 'projects',
            'recordType' => 'other',
            'recordId' => '1',
        ])->assertUnprocessable()->assertJsonStructure(['message']);

        $this->postJson(self::URL, [
            'destination' => 'projects',
            'recordType' => 'project',
        ])->assertUnprocessable()->assertJsonStructure(['message']);
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function shoot(User $owner, string $address): Shoot
    {
        return Shoot::factory()->create([
            'client_id' => $owner->id,
            'editor_id' => $owner->id,
            'created_by' => $owner->id,
            'address' => $address,
        ]);
    }

    private function project(int $teamId, User $owner, ?Shoot $shoot, string $name): Project
    {
        return Project::query()->create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'shoot_id' => $shoot?->id,
            'name' => $name,
            'address' => $shoot?->address,
            'source_type' => $shoot ? 'shoot' : 'upload',
            'workflow_id' => 'photo-enhancement',
            'status' => 'draft',
        ]);
    }

    private function template(int $teamId, User $owner, string $name): Template
    {
        return Template::query()->create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'name' => $name,
            'workflow_id' => 'photo-enhancement',
            'config' => ['strength' => 70],
        ]);
    }

    private function photoJob(User $owner, Shoot $shoot, Project $project): AiEditingJob
    {
        return AiEditingJob::query()->create([
            'project_id' => $project->id,
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'status' => AiEditingJob::STATUS_PENDING,
            'editing_type' => AiEditingJob::TYPE_ENHANCE,
            'original_image_url' => 'https://example.test/source.jpg',
        ]);
    }

    private function videoJob(User $owner, Shoot $shoot, Project $project): AiListingVideoJob
    {
        return AiListingVideoJob::query()->create([
            'project_id' => $project->id,
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'provider' => 'fal',
            'selected_file_ids' => [1, 2, 3, 4, 5, 6],
            'target_seconds' => 30,
            'status' => AiListingVideoJob::STATUS_QUEUED,
        ]);
    }

    private function reelJob(User $owner, Shoot $shoot): AiReelJob
    {
        return AiReelJob::query()->create([
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'provider' => 'fal',
            'selected_file_ids' => [1, 2, 3, 4, 5, 6],
            'status' => AiReelJob::STATUS_QUEUED,
        ]);
    }
}
