<?php

namespace Tests\Feature;

use App\Models\AiListingVideoJob;
use App\Models\Project;
use App\Models\Shoot;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Targeted endpoint coverage for unified Studio search.
 *
 * Validates: Requirements 6.1, 6.2, 6.3, 6.6, 6.10, 16.2
 */
class StudioSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/studio/search';

    public function test_empty_query_returns_an_empty_grouped_result_without_exposing_records(): void
    {
        $user = $this->actor('editor', 41);
        $this->project(41, $user, 'Should Not Be Returned');
        Sanctum::actingAs($user);

        $this->getJson(self::URL . '?q=')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.query', '')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_search_returns_all_and_only_supported_studio_groups_with_valid_deep_links(): void
    {
        $user = $this->actor('editor', 42);
        $shoot = $this->shoot($user, '22 Video Vista', 'video-vista');
        $project = $this->project(42, $user, 'Video Launch Project', $shoot);
        $template = $this->template(42, $user, 'Video Showcase Template');
        $job = $this->listingVideoJob($user, $shoot, $project);
        User::factory()->create(['name' => 'Video Non-Studio User']);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::URL . '?q=video')->assertOk();
        $groups = collect($response->json('data'));
        $this->assertEqualsCanonicalizing(
            ['project', 'shoot', 'template', 'workflow', 'ai_job'],
            $groups->pluck('recordType')->all()
        );

        $results = $groups->flatMap(fn (array $group): array => $group['results'])->values();
        $this->assertNotEmpty($results);
        $this->assertContains((string) $project->id, $results->pluck('recordId')->all());
        $this->assertContains((string) $shoot->id, $results->pluck('recordId')->all());
        $this->assertContains((string) $template->id, $results->pluck('recordId')->all());
        $this->assertContains('video-' . $job->id, $results->pluck('recordId')->all());

        foreach ($results as $result) {
            $this->assertContains($result['recordType'], ['project', 'shoot', 'template', 'workflow', 'ai_job']);
            $this->assertNotSame('', trim($result['title']));
            $this->assertNotSame('', trim($result['context']));
            $this->assertSame($result['recordType'], $result['deepLink']['recordType']);
            $this->assertSame($result['recordId'], $result['deepLink']['recordId']);
            $this->assertContains($result['deepLink']['destination'], [
                'command-center', 'projects', 'templates', 'queue',
                'photo-enhancement', 'twilight', 'video-cleanup',
                'listing-video', 'reel-generator', 'batch-ai-jobs',
            ]);
        }

        $this->assertStringNotContainsString('Video Non-Studio User', $response->getContent());
    }

    public function test_editor_scope_omits_peer_and_cross_team_records_while_privileged_scope_stays_in_team(): void
    {
        $editor = $this->actor('editor', 70);
        $peer = $this->actor('editor', 70);
        $outsider = $this->actor('editor', 71);
        $admin = $this->actor('admin', 70);

        $ownShoot = $this->shoot($editor, 'Scope Beacon Own');
        $peerShoot = $this->shoot($peer, 'Scope Beacon Peer');
        $outsideShoot = $this->shoot($outsider, 'Scope Beacon Outside');

        $ownProject = $this->project(70, $editor, 'Scope Beacon Own Project', $ownShoot);
        $peerProject = $this->project(70, $peer, 'Scope Beacon Peer Project', $peerShoot);
        $outsideProject = $this->project(71, $outsider, 'Scope Beacon Outside Project', $outsideShoot);

        $this->listingVideoJob($editor, $ownShoot, $ownProject);
        $this->listingVideoJob($peer, $peerShoot, $peerProject);
        $this->listingVideoJob($outsider, $outsideShoot, $outsideProject);

        Sanctum::actingAs($editor);
        $editorResponse = $this->getJson(self::URL . '?q=Scope%20Beacon')->assertOk();
        $this->assertStringContainsString('Scope Beacon Own', $editorResponse->getContent());
        $this->assertStringNotContainsString('Scope Beacon Peer', $editorResponse->getContent());
        $this->assertStringNotContainsString('Scope Beacon Outside', $editorResponse->getContent());

        Sanctum::actingAs($admin);
        $adminResponse = $this->getJson(self::URL . '?q=Scope%20Beacon')->assertOk();
        $this->assertStringContainsString('Scope Beacon Own', $adminResponse->getContent());
        $this->assertStringContainsString('Scope Beacon Peer', $adminResponse->getContent());
        $this->assertStringNotContainsString('Scope Beacon Outside', $adminResponse->getContent());
    }

    private function actor(string $role, int $teamId): User
    {
        return User::factory()->create([
            'role' => $role,
            'metadata' => ['team_id' => $teamId],
        ]);
    }

    private function shoot(User $owner, string $address, ?string $slug = null): Shoot
    {
        return Shoot::factory()->create([
            'client_id' => $owner->id,
            'editor_id' => $owner->id,
            'created_by' => $owner->id,
            'address' => $address,
            'property_slug' => $slug,
        ]);
    }

    private function project(
        int $teamId,
        User $owner,
        string $name,
        ?Shoot $shoot = null
    ): Project {
        return Project::query()->create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'shoot_id' => $shoot?->id,
            'name' => $name,
            'address' => $shoot?->address,
            'source_type' => $shoot ? 'shoot' : 'upload',
            'workflow_id' => 'listing-video',
            'status' => 'draft',
        ]);
    }

    private function template(int $teamId, User $owner, string $name): Template
    {
        return Template::query()->create([
            'team_id' => $teamId,
            'created_by' => $owner->id,
            'name' => $name,
            'workflow_id' => 'listing-video',
            'config' => ['target_seconds' => 30],
        ]);
    }

    private function listingVideoJob(
        User $owner,
        Shoot $shoot,
        ?Project $project = null
    ): AiListingVideoJob {
        return AiListingVideoJob::query()->create([
            'project_id' => $project?->id,
            'shoot_id' => $shoot->id,
            'user_id' => $owner->id,
            'provider' => 'fal',
            'selected_file_ids' => [1, 2, 3, 4, 5, 6],
            'target_seconds' => 30,
            'status' => AiListingVideoJob::STATUS_QUEUED,
        ]);
    }
}
