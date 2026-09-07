<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootService;
use App\Models\User;
use App\Services\Shoots\Actions\UploadShootFilesAction;
use App\Services\Shoots\ShootAuthorizationSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ShootUploadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_cannot_mutate_shoot_media_through_any_upload_entry_point(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        Sanctum::actingAs($client);

        $responses = [
            $this->postJson("/api/shoots/{$shoot->id}/upload", ['upload_type' => 'raw']),
            $this->postJson("/api/shoots/{$shoot->id}/upload-extra", []),
            $this->postJson("/api/shoots/{$shoot->id}/upload-from-pc", ['upload_type' => 'raw']),
            $this->postJson("/api/shoots/{$shoot->id}/media", ['type' => 'raw']),
            $this->postJson("/api/shoots/{$shoot->id}/upload-from-source", [
                'upload_type' => 'raw',
                'source_type' => 'url',
                'urls' => ['https://example.test/photo.jpg'],
            ]),
        ];

        foreach ($responses as $response) {
            $response
                ->assertForbidden()
                ->assertJsonPath('error_type', 'forbidden')
                ->assertJsonPath('success_count', 0)
                ->assertJsonPath('partial_success', false);
        }
    }

    public function test_unassigned_photographer_and_editor_cannot_upload_to_an_arbitrary_shoot(): void
    {
        $shoot = Shoot::factory()->create();
        $photographer = User::factory()->create(['role' => 'photographer']);
        $editor = User::factory()->create(['role' => 'editor']);

        Sanctum::actingAs($photographer);
        $this->postJson("/api/shoots/{$shoot->id}/upload", ['upload_type' => 'raw'])
            ->assertForbidden()
            ->assertJsonPath('error_type', 'forbidden');

        Sanctum::actingAs($editor);
        $this->postJson("/api/shoots/{$shoot->id}/upload", ['upload_type' => 'edited'])
            ->assertForbidden()
            ->assertJsonPath('error_type', 'forbidden');
        $this->postJson("/api/shoots/{$shoot->id}/media", ['type' => 'edited'])
            ->assertForbidden()
            ->assertJsonPath('error_type', 'forbidden');
    }

    public function test_client_cannot_browse_or_mutate_upload_source_accounts(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs($client);

        $responses = [
            $this->getJson('/api/upload-sources'),
            $this->postJson('/api/upload-sources/google_drive/connect'),
            $this->deleteJson('/api/upload-sources/google_drive'),
            $this->getJson('/api/upload-sources/google_drive/items'),
        ];

        foreach ($responses as $response) {
            $response
                ->assertForbidden()
                ->assertJsonPath('error_type', 'forbidden');
        }
    }

    public function test_upload_authorization_enforces_role_type_and_service_assignment(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $peerPhotographer = User::factory()->create(['role' => 'photographer']);
        $editor = User::factory()->create(['role' => 'editor']);
        $peerEditor = User::factory()->create(['role' => 'editor']);
        $client = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'editor_id' => $editor->id,
        ]);
        $service = Service::factory()->create(['requires_editing' => true]);
        $legacyItem = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'photographer_id' => null,
            'editor_id' => null,
            'price' => 100,
            'quantity' => 1,
        ]);
        $support = app(ShootAuthorizationSupport::class);

        // Null-pivot service items retain the intentional top-level legacy fallbacks.
        $this->assertTrue($support->canUploadShootMedia($shoot, $photographer, 'raw', $legacyItem->id));
        $this->assertTrue($support->canUploadShootMedia($shoot, $editor, 'edited', $legacyItem->id));

        $this->assertFalse($support->canUploadShootMedia($shoot, $peerPhotographer, 'raw', $legacyItem->id));
        $this->assertFalse($support->canUploadShootMedia($shoot, $peerEditor, 'edited', $legacyItem->id));
        $this->assertFalse($support->canUploadShootMedia($shoot, $photographer, 'edited', $legacyItem->id));
        $this->assertFalse($support->canUploadShootMedia($shoot, $editor, 'raw', $legacyItem->id));
        $this->assertFalse($support->canUploadShootMedia($shoot, $client, 'raw', $legacyItem->id));
        $this->assertTrue($support->canUploadShootMedia($shoot, $admin, 'raw'));
        $this->assertTrue($support->canUploadShootMedia($shoot, $admin, 'edited'));
    }

    public function test_legacy_pc_and_extra_routes_delegate_to_the_canonical_upload_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $action = Mockery::mock(UploadShootFilesAction::class);
        $action->shouldReceive('execute')
            ->once()
            ->withArgs(function (Request $request, Shoot $target, User $actor) use ($shoot, $admin) {
                return (int) $target->id === (int) $shoot->id
                    && (int) $actor->id === (int) $admin->id
                    && $request->input('idempotency_key') === 'pc-route-key'
                    && $request->input('upload_type') === 'raw';
            })
            ->andReturn($this->canonicalSuccess('pc.jpg'));
        $action->shouldReceive('execute')
            ->once()
            ->withArgs(function (Request $request, Shoot $target, User $actor) use ($shoot, $admin) {
                return (int) $target->id === (int) $shoot->id
                    && (int) $actor->id === (int) $admin->id
                    && $request->input('upload_type') === 'raw'
                    && $request->input('media_type') === 'extra'
                    && $request->boolean('is_extra');
            })
            ->andReturn($this->canonicalSuccess('floorplan.jpg'));
        app()->instance(UploadShootFilesAction::class, $action);

        $this->postJson("/api/shoots/{$shoot->id}/upload-from-pc", [
            'upload_type' => 'raw',
            'idempotency_key' => 'pc-route-key',
        ])->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('uploaded_files.0.filename', 'pc.jpg');

        $this->postJson("/api/shoots/{$shoot->id}/upload-extra", [])
            ->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('uploaded_files.0.filename', 'floorplan.jpg');
    }

    public function test_obsolete_dropbox_copy_path_is_retired_for_authorized_uploaders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/shoots/{$shoot->id}/copy-from-dropbox", [])
            ->assertNotFound();

        $this->getJson('/api/dropbox/browse')
            ->assertNotFound();
    }

    public function test_legacy_pc_route_allows_top_level_photographer_for_a_null_pivot_service(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);
        $serviceItem = ShootService::query()->create([
            'shoot_id' => $shoot->id,
            'service_id' => Service::factory()->create()->id,
            'photographer_id' => null,
            'price' => 100,
            'quantity' => 1,
        ]);
        Sanctum::actingAs($photographer);

        $action = Mockery::mock(UploadShootFilesAction::class);
        $action->shouldReceive('execute')
            ->once()
            ->withArgs(function (Request $request, Shoot $target, User $actor) use ($shoot, $photographer, $serviceItem) {
                return (int) $target->id === (int) $shoot->id
                    && (int) $actor->id === (int) $photographer->id
                    && (int) $request->input('shoot_service_id') === (int) $serviceItem->id
                    && $request->input('idempotency_key') === 'legacy-null-pivot-key';
            })
            ->andReturn($this->canonicalSuccess('legacy.jpg'));
        app()->instance(UploadShootFilesAction::class, $action);

        $this->postJson("/api/shoots/{$shoot->id}/upload-from-pc", [
            'upload_type' => 'raw',
            'shoot_service_id' => $serviceItem->id,
            'idempotency_key' => 'legacy-null-pivot-key',
        ])->assertOk()
            ->assertJsonPath('success_count', 1)
            ->assertJsonPath('uploaded_files.0.filename', 'legacy.jpg');
    }

    private function canonicalSuccess(string $filename): array
    {
        return [
            'status' => 200,
            'payload' => [
                'uploaded_files' => [['id' => 1, 'filename' => $filename]],
                'errors' => [],
                'success_count' => 1,
                'error_count' => 0,
                'partial_success' => false,
            ],
        ];
    }
}
