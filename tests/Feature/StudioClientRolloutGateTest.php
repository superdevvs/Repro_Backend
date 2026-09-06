<?php

namespace Tests\Feature;

use App\Exceptions\StudioClientAccessPaused;
use App\Jobs\ProcessStudioWorkspace;
use App\Models\Setting;
use App\Models\StudioWorkspace;
use App\Models\User;
use App\Services\FalService;
use App\Services\RolePermissionService;
use App\Services\Studio\WorkspaceProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioClientRolloutGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['studio_uploads.disk' => 'public', 'studio.client_access_enabled' => false]);
    }

    public function test_client_cannot_reach_any_workspace_endpoint_or_write_data_while_paused(): void
    {
        // Secondary staff grants must not bypass a rollout paused for this primary client account.
        foreach ([[], ['superadmin']] as $secondaryRoles) {
            $user = User::factory()->create(['role' => 'client', 'secondary_roles' => $secondaryRoles]);
            $workspace = $this->workspace($user);
            $before = $workspace->fresh()->getAttributes();
            Sanctum::actingAs($user);
            $base = '/api/studio/workspaces';
            $routes = [
                ['GET', $base], ['POST', $base], ['GET', "$base/{$workspace->id}"],
                ['PATCH', "$base/{$workspace->id}"], ['GET', "$base/{$workspace->id}/outputs/saved/download"],
                ['GET', "$base/sources/shoots"], ['GET', "$base/sources/shoots/999999/media"],
                ['GET', "$base/sources/uploads/preview?mediaRef=missing.nef"], ['POST', "$base/sources/resolve"],
            ];
            foreach (['prepare', 'generate', 'revisions', 'segments', 'cancel'] as $action) {
                $routes[] = ['POST', "$base/{$workspace->id}/$action"];
            }
            // Empty/invalid bodies and unknown source IDs must be denied before controller validation.
            foreach ($routes as [$method, $url]) {
                $this->json($method, $url)->assertForbidden()->assertJsonPath('message', (new StudioClientAccessPaused)->getMessage());
            }
            $this->post("$base/sources/uploads", ['workflow' => 'photo-enhancement', 'files' => [UploadedFile::fake()->image('client.jpg')]], ['Accept' => 'application/json'])->assertForbidden();
            $this->getJson('/api/studio/templates')->assertForbidden();
            $this->assertSame($before, $workspace->fresh()->getAttributes());
        }
        $this->assertSame(2, StudioWorkspace::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_staff_can_browse_create_update_and_enqueue_work_with_client_rollout_paused(): void
    {
        foreach (['superadmin', 'admin', 'editing_manager', 'editor'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            Sanctum::actingAs($user);
            $ref = "studio/uploads/{$user->id}/{$user->id}/room.jpg";
            Storage::disk('public')->put($ref, UploadedFile::fake()->image('room.jpg')->getContent());
            $this->getJson('/api/studio/workspaces/sources/shoots')->assertOk();
            $created = $this->postJson('/api/studio/workspaces', [
                'name' => 'Staff draft', 'presetId' => 'listing-ready', 'media' => [['id' => 'm1', 'mediaRef' => $ref]],
            ])->assertCreated();
            $id = $created->json('data.id');
            $this->getJson('/api/studio/workspaces')->assertOk();
            $this->getJson('/api/studio/workspaces/'.$id)->assertOk();
            $this->patchJson('/api/studio/workspaces/'.$id, ['name' => 'Updated draft'])->assertOk();
            $this->postJson('/api/studio/workspaces/'.$id.'/generate')->assertAccepted();
        }
        Queue::assertPushed(ProcessStudioWorkspace::class, 4);
    }

    public function test_already_queued_client_operations_fail_once_before_provider_calls_and_keep_progress(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $this->mock(FalService::class)->shouldNotReceive('submitModel', 'submitImageEditFromBuffer', 'getStatus', 'getResult');
        foreach (['prepare', 'generate', 'revision'] as $type) {
            $workspace = $this->workspace($user);
            $workspace->update(['status' => $type === 'prepare' ? 'preparing' : 'generating', 'operation' => [
                'id' => 'queued-before-pause', 'type' => $type, 'requests' => ['m1' => 'existing-paid-request'],
            ]]);
            $before = $workspace->fresh();
            $job = (new ProcessStudioWorkspace($workspace->id, 'queued-before-pause'))->withFakeQueueInteractions();
            $job->handle(app(WorkspaceProcessor::class));
            $job->assertFailedWith(StudioClientAccessPaused::class);
            $after = $workspace->fresh();
            $this->assertSame('failed', $after->status);
            $this->assertSame((new StudioClientAccessPaused)->getMessage(), $after->error);
            foreach (['media', 'config', 'outputs', 'prepared_frames', 'operation'] as $attribute) {
                $this->assertSame($before->{$attribute}, $after->{$attribute});
            }
            $this->assertSame($before->version + 1, $after->version);
            $job->handle(app(WorkspaceProcessor::class));
            $this->assertSame($after->version, $workspace->fresh()->version);
        }
        Http::assertNothingSent();
    }

    public function test_effective_client_permissions_are_filtered_without_changing_stored_grants(): void
    {
        $service = app(RolePermissionService::class);
        $service->adminPayload();
        $setting = Setting::where('key', 'permissions.role_map.v1')->firstOrFail();
        $map = json_decode($setting->value, true);
        $map['roles']['client'][] = 'ai-editing-view';
        $setting->update(['value' => json_encode($map)]);
        // Complete existing unrelated map migrations before taking the preservation snapshot.
        $service->adminPayload();
        $before = $setting->fresh()->value;
        foreach ([[], ['superadmin']] as $secondaryRoles) {
            $client = User::factory()->create(['role' => 'client', 'secondary_roles' => $secondaryRoles]);
            Sanctum::actingAs($client);
            $response = $this->getJson('/api/me/permissions')->assertOk();
            $this->assertNotContains('ai-editing-view', $response->json('permissionIds'));
            $this->assertNotContains('ai-editing', array_column($response->json('permissions'), 'resource'));
            $this->assertContains('dashboard-view', $response->json('permissionIds'));
            $this->assertFalse($service->userCan($client, 'ai-editing', 'view'));
        }
        $this->assertSame($before, $setting->fresh()->value);
        config(['studio.client_access_enabled' => true]);
        $this->getJson('/api/me/permissions')->assertOk()->assertJsonFragment(['id' => 'ai-editing-view', 'resource' => 'ai-editing', 'action' => 'view']);
        $this->assertTrue($service->userCan($client, 'ai-editing', 'view'));
        $this->assertSame($before, $setting->fresh()->value);
    }

    public function test_staff_effective_permissions_and_guest_authentication_are_unchanged(): void
    {
        $this->getJson('/api/studio/workspaces')->assertUnauthorized();
        foreach (['superadmin', 'admin', 'editing_manager', 'editor'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $service = app(RolePermissionService::class);
            config(['studio.client_access_enabled' => true]);
            $expected = $service->effectivePayloadForUser($user);
            config(['studio.client_access_enabled' => false]);
            Sanctum::actingAs($user);
            $this->getJson('/api/me/permissions')->assertOk()->assertExactJson($expected);
        }
    }

    private function workspace(User $user): StudioWorkspace
    {
        return StudioWorkspace::create([
            'team_id' => $user->id, 'created_by' => $user->id, 'name' => 'Saved client work', 'preset_id' => 'walkthrough',
            'media' => [['id' => 'm1', 'mediaRef' => "studio/uploads/{$user->id}/{$user->id}/retained.jpg"]],
            'config' => ['prompt' => 'Keep this draft'], 'outputs' => [['id' => 'saved', 'url' => '/retained.jpg']],
            'prepared_frames' => [['mediaId' => 'm1', 'url' => '/prepared.jpg']], 'status' => 'draft',
        ]);
    }
}
