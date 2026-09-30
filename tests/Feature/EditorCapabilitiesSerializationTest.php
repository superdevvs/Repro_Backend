<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EditorCapabilitiesSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_model_exposes_editor_type_and_role_display_label(): void
    {
        $photo = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['photo']],
        ]);
        $video = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);
        $both = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['photo', 'video']],
        ]);
        $defaultBoth = User::factory()->create([
            'role' => 'editor',
            'metadata' => [],
        ]);
        $client = User::factory()->create(['role' => 'client']);

        $this->assertSame(['photo'], $photo->getEditingCapabilities());
        $this->assertSame('photo', $photo->getEditorType());
        $this->assertSame('Photo editor', $photo->getRoleDisplayLabel());

        $this->assertSame(['video'], $video->getEditingCapabilities());
        $this->assertSame('video', $video->getEditorType());
        $this->assertSame('Video editor', $video->getRoleDisplayLabel());

        $this->assertSame(['photo', 'video'], $both->getEditingCapabilities());
        $this->assertSame('photo_video', $both->getEditorType());
        $this->assertSame('Photo & video editor', $both->getRoleDisplayLabel());

        $this->assertSame(['photo', 'video'], $defaultBoth->getEditingCapabilities());
        $this->assertSame('photo_video', $defaultBoth->getEditorType());

        $this->assertSame([], $client->getEditingCapabilities());
        $this->assertNull($client->getEditorType());
        $this->assertSame('Client', $client->getRoleDisplayLabel());
        $this->assertSame('client', $client->role);
    }

    public function test_admin_user_show_includes_editing_capabilities_and_labels(): void
    {
        $admin = User::factory()->admin()->create();
        $photoEditor = User::factory()->create([
            'role' => 'editor',
            'name' => 'Photo Editor QA',
            'metadata' => ['editing_capabilities' => ['photo']],
        ]);
        $videoEditor = User::factory()->create([
            'role' => 'editor',
            'name' => 'Video Editor QA',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/users/{$photoEditor->id}")
            ->assertOk()
            ->assertJsonPath('user.role', 'editor')
            ->assertJsonPath('user.editing_capabilities', ['photo'])
            ->assertJsonPath('user.editingCapabilities', ['photo'])
            ->assertJsonPath('user.editor_type', 'photo')
            ->assertJsonPath('user.editorType', 'photo')
            ->assertJsonPath('user.role_label', 'Photo editor')
            ->assertJsonPath('user.roleLabel', 'Photo editor');

        $this->getJson("/api/admin/users/{$videoEditor->id}")
            ->assertOk()
            ->assertJsonPath('user.role', 'editor')
            ->assertJsonPath('user.editing_capabilities', ['video'])
            ->assertJsonPath('user.editor_type', 'video')
            ->assertJsonPath('user.role_label', 'Video editor');
    }

    public function test_admin_users_index_includes_editing_capabilities_for_list_badges(): void
    {
        $admin = User::factory()->admin()->create();
        $photoEditor = User::factory()->create([
            'role' => 'editor',
            'name' => 'List Photo Editor',
            'metadata' => ['editing_capabilities' => ['photo']],
        ]);
        $videoEditor = User::factory()->create([
            'role' => 'editor',
            'name' => 'List Video Editor',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);

        Sanctum::actingAs($admin);

        $users = collect($this->getJson('/api/admin/users?light=1')->assertOk()->json('users'));

        $photo = $users->firstWhere('id', $photoEditor->id);
        $video = $users->firstWhere('id', $videoEditor->id);

        $this->assertNotNull($photo);
        $this->assertSame('editor', $photo['role']);
        $this->assertSame(['photo'], $photo['editing_capabilities']);
        $this->assertSame(['photo'], $photo['editingCapabilities']);
        $this->assertSame('photo', $photo['editor_type']);
        $this->assertSame('Photo editor', $photo['role_label']);

        $this->assertNotNull($video);
        $this->assertSame('editor', $video['role']);
        $this->assertSame(['video'], $video['editing_capabilities']);
        $this->assertSame('video', $video['editor_type']);
        $this->assertSame('Video editor', $video['role_label']);
    }

    public function test_api_user_profile_includes_editing_capabilities_for_editors(): void
    {
        $videoEditor = User::factory()->create([
            'role' => 'editor',
            'metadata' => ['editing_capabilities' => ['video']],
        ]);

        $this->withToken($videoEditor->createToken('editor-me')->plainTextToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('role', 'editor')
            ->assertJsonPath('editing_capabilities', ['video'])
            ->assertJsonPath('editingCapabilities', ['video'])
            ->assertJsonPath('editor_type', 'video')
            ->assertJsonPath('editorType', 'video')
            ->assertJsonPath('role_label', 'Video editor')
            ->assertJsonPath('roleLabel', 'Video editor');
    }
}
