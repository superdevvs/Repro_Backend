<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootNotesSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrelated_and_ghost_clients_cannot_access_notes_routes(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create([
            'client_id' => $owner->id,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $shoot->ghostUsers()->attach($other->id);

        Sanctum::actingAs($other);

        $this->getJson("/api/shoots/{$shoot->id}/notes")->assertForbidden();
        $this->postJson("/api/shoots/{$shoot->id}/notes", [
            'type' => 'shoot',
            'visibility' => 'client_visible',
            'content' => 'Should not be stored',
        ])->assertForbidden();
        $this->patchJson("/api/shoots/{$shoot->id}/notes", [
            'shoot_notes' => 'Should not be stored',
        ])->assertForbidden();

        $this->assertDatabaseMissing('shoot_notes', ['content' => 'Should not be stored']);
    }

    public function test_owner_sees_only_client_visible_shoot_notes_and_writes_relational_notes(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create(['client_id' => $owner->id]);
        $visible = $shoot->notes()->create([
            'author_id' => $admin->id,
            'type' => 'shoot',
            'visibility' => 'client_visible',
            'content' => 'Front door code is in the lockbox.',
        ]);
        $shoot->notes()->create([
            'author_id' => $admin->id,
            'type' => 'company',
            'visibility' => 'internal',
            'content' => 'Internal margin note',
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/shoots/{$shoot->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonMissing(['content' => 'Internal margin note']);

        $this->postJson("/api/shoots/{$shoot->id}/notes", [
            'type' => 'shoot',
            'visibility' => 'client_visible',
            'content' => 'Please photograph the garden.',
        ])->assertCreated();

        $this->assertDatabaseHas('shoot_notes', [
            'shoot_id' => $shoot->id,
            'author_id' => $owner->id,
            'type' => 'shoot',
            'visibility' => 'client_visible',
            'content' => 'Please photograph the garden.',
        ]);
        $this->assertSame('Please photograph the garden.', $shoot->fresh()->shoot_notes);

        $this->postJson("/api/shoots/{$shoot->id}/notes", [
            'type' => 'company',
            'visibility' => 'internal',
            'content' => 'Forbidden internal note',
        ])->assertForbidden();
    }

    public function test_shoot_link_allows_read_but_not_write(): void
    {
        $linkedViewer = User::factory()->create(['role' => 'client']);
        $shootOwner = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->admin()->create();
        $shoot = Shoot::factory()->create(['client_id' => $shootOwner->id]);
        AccountLink::create([
            'main_account_id' => $linkedViewer->id,
            'linked_account_id' => $shootOwner->id,
            'shared_details' => ['shoots' => true],
            'status' => 'active',
            'linked_at' => now(),
            'created_by' => $admin->id,
        ]);
        $shoot->notes()->create([
            'author_id' => $admin->id,
            'type' => 'shoot',
            'visibility' => 'client_visible',
            'content' => 'Shared client note',
        ]);

        Sanctum::actingAs($linkedViewer);

        $this->getJson("/api/shoots/{$shoot->id}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Shared client note');
        $this->patchJson("/api/shoots/{$shoot->id}/notes", [
            'shoot_notes' => 'Linked account write',
        ])->assertForbidden();
    }
}
