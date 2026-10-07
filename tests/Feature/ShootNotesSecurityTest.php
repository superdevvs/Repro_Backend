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

    public function test_all_sales_aliases_can_read_and_edit_all_categories_globally(): void
    {
        $shoot = Shoot::factory()->create(['rep_id' => null, 'notes' => 'Historical approval decision', 'approval_notes' => 'Approved by office']);
        foreach (['salesRep', 'sales_rep', 'sales-rep', 'rep', 'representative'] as $role) {
            $rep = User::factory()->create(['role' => $role]);
            Sanctum::actingAs($rep);
            foreach (['shoot' => 'client_visible', 'company' => 'internal', 'photographer' => 'photographer_only', 'editing' => 'internal', 'approval' => 'internal'] as $type => $visibility) {
                $this->postJson("/api/shoots/{$shoot->id}/notes", compact('type', 'visibility') + ['content' => "$role $type annotation"])->assertCreated();
            }
            $this->getJson("/api/shoots/{$shoot->id}/notes")->assertOk()->assertJsonFragment(['content' => "$role approval annotation"]);
            $this->patchJson("/api/shoots/{$shoot->id}/notes", ['approvalAnnotation' => 'Updated annotation'])->assertOk();
        }
        $this->assertSame('Historical approval decision', $shoot->fresh()->getRawOriginal('notes'));
        $this->assertSame('Approved by office', $shoot->fresh()->approval_notes);
    }

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

    public function test_approval_upgrade_and_clear_preserve_existing_notes_and_decisions(): void
    {
        $rep = User::factory()->create(['role' => 'representative']);
        $shoot = Shoot::factory()->create(['approval_notes' => 'Approved before upgrade', 'rep_id' => null]);
        $note = $shoot->notes()->create(['author_id' => $rep->id, 'type' => 'company', 'visibility' => 'internal', 'content' => 'Preserve original']);
        $migration = require database_path('migrations/2026_10_07_100000_add_shoot_approval_annotations.php');
        $migration->down();
        $migration->up();
        $this->assertSame('Preserve original', $note->fresh()->content);
        $this->assertSame('Approved before upgrade', $shoot->fresh()->approval_notes);
        Sanctum::actingAs($rep);
        $this->patchJson('/api/shoots/'.$shoot->id.'/notes', ['approval_annotation' => 'Current annotation'])->assertOk();
        $annotationId = $shoot->notes()->where('type', 'approval')->latest('id')->value('id');
        $this->patchJson('/api/shoots/'.$shoot->id.'/notes', ['approval_annotation' => ''])->assertOk();
        $this->assertDatabaseHas('shoot_notes', ['id' => $annotationId, 'content' => 'Current annotation']);
        $this->assertSame('', $shoot->notes()->where('type', 'approval')->latest('id')->value('content'));
        $this->assertSame('Approved before upgrade', $shoot->fresh()->approval_notes);
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

    public function test_assigned_contractors_keep_their_note_visibility_and_write_limits(): void
    {
        $photographer = User::factory()->photographer()->create();
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id, 'editor_id' => $editor->id]);
        foreach (['shoot' => 'client_visible', 'photographer' => 'photographer_only', 'editing' => 'internal', 'company' => 'internal', 'approval' => 'internal'] as $type => $visibility) {
            $shoot->notes()->create(['type' => $type, 'visibility' => $visibility, 'content' => $type.' fixture', 'author_id' => $photographer->id]);
        }
        Sanctum::actingAs($photographer);
        $this->getJson('/api/shoots/'.$shoot->id.'/notes')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissing(['type' => 'company'])->assertJsonMissing(['type' => 'approval']);
        $this->patchJson('/api/shoots/'.$shoot->id.'/notes', ['company_notes' => 'Denied'])->assertForbidden();
        Sanctum::actingAs($editor);
        $this->getJson('/api/shoots/'.$shoot->id.'/notes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'editing');
        $this->postJson('/api/shoots/'.$shoot->id.'/notes', ['type' => 'approval', 'visibility' => 'internal', 'content' => 'Denied'])->assertForbidden();
    }
}
