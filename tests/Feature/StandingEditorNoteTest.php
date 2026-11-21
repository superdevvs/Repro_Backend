<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\StandingEditorNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StandingEditorNoteTest extends TestCase
{
    use RefreshDatabase;

    private const GRASS = 'All Weichert agents get 1 complimentary exterior green grass.';

    public function test_brokerage_match_is_case_insensitive_and_counts_every_spelling(): void
    {
        foreach (['Weichert, Realtors', 'WEICHERT REALTORS - Bethesda', 'weichert'] as $company) {
            User::factory()->create(['role' => 'client', 'company_name' => $company]);
        }
        User::factory()->create(['role' => 'client', 'company_name' => 'Long & Foster']);
        User::factory()->create(['role' => 'photographer', 'company_name' => 'Weichert Media']);
        User::factory()->create(['role' => 'client', 'company_name' => '100% Realty']);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/standing-editor-notes/matches?company=weiCHERT')
            ->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonCount(3, 'data.clients');
        // LIKE wildcards in the keyword are matched literally.
        $this->getJson('/api/standing-editor-notes/matches?company='.urlencode('%'))
            ->assertOk()->assertJsonPath('data.count', 1);
    }

    public function test_client_note_is_one_per_client_and_saving_again_updates_it(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/standing-editor-notes', ['scope' => 'client', 'client_id' => $client->id, 'content' => 'First'])
            ->assertCreated();
        $this->postJson('/api/standing-editor-notes', ['scope' => 'client', 'client_id' => $client->id, 'content' => 'Second'])
            ->assertOk()->assertJsonPath('data.content', 'Second');

        $this->assertDatabaseCount('standing_editor_notes', 1);
        $this->getJson("/api/standing-editor-notes?client_id={$client->id}")
            ->assertOk()
            ->assertJsonPath('data.client_note.content', 'Second')
            ->assertJsonPath('data.applicable.0.content', 'Second');
    }

    public function test_only_staff_manage_notes_and_editing_managers_only_read(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $payload = ['scope' => 'client', 'client_id' => $client->id, 'content' => 'Nope'];

        foreach (['client', 'editor', 'photographer'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/standing-editor-notes')->assertForbidden();
            $this->postJson('/api/standing-editor-notes', $payload)->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'editing_manager']));
        $this->getJson('/api/standing-editor-notes')->assertOk();
        $this->postJson('/api/standing-editor-notes', $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'salesRep']));
        $this->postJson('/api/standing-editor-notes', $payload)->assertCreated();
    }

    public function test_editor_sees_brokerage_then_client_note_and_client_never_sees_them(): void
    {
        [$client, $editor, $shoot] = $this->editingShoot();
        $this->brokerageNote();
        StandingEditorNote::create(['scope' => 'client', 'client_id' => $client->id, 'content' => 'Keep skies natural.']);

        Sanctum::actingAs($editor);
        $this->getJson("/api/shoots/{$shoot->id}")
            ->assertOk()
            ->assertJsonPath('data.standing_editor_notes.hidden', false)
            ->assertJsonPath('data.standing_editor_notes.notes.0.scope', 'brokerage')
            ->assertJsonPath('data.standing_editor_notes.notes.0.content', self::GRASS)
            ->assertJsonPath('data.standing_editor_notes.notes.1.content', 'Keep skies natural.')
            ->assertJsonMissingPath('data.standing_editor_notes_snapshot');

        Sanctum::actingAs($client);
        $response = $this->getJson("/api/shoots/{$shoot->id}")->assertOk();
        $this->assertArrayNotHasKey('standing_editor_notes', $response->json('data'));
        $this->assertStringNotContainsString('complimentary exterior green grass', $response->getContent());
    }

    public function test_switched_off_and_non_matching_notes_do_not_apply(): void
    {
        [, $editor, $shoot] = $this->editingShoot('Long & Foster');
        $this->brokerageNote();
        StandingEditorNote::create(['scope' => 'brokerage', 'company_match' => 'Foster', 'content' => 'Off note', 'is_active' => false]);

        Sanctum::actingAs($editor);
        $this->getJson("/api/shoots/{$shoot->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.standing_editor_notes.notes');
    }

    public function test_admin_can_hide_the_note_on_one_shoot_and_editor_then_sees_nothing(): void
    {
        [$client, $editor, $shoot] = $this->editingShoot();
        $this->brokerageNote();

        Sanctum::actingAs($editor);
        $this->patchJson("/api/shoots/{$shoot->id}/standing-editor-notes", ['hidden' => true])->assertForbidden();
        Sanctum::actingAs($client);
        $this->patchJson("/api/shoots/{$shoot->id}/standing-editor-notes", ['hidden' => true])->assertForbidden();

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->patchJson("/api/shoots/{$shoot->id}/standing-editor-notes", ['hidden' => true])
            ->assertOk()
            ->assertJsonPath('data.hidden', true)
            ->assertJsonPath('data.notes.0.content', self::GRASS);

        Sanctum::actingAs($editor);
        $this->getJson("/api/shoots/{$shoot->id}")
            ->assertOk()
            ->assertJsonPath('data.standing_editor_notes.hidden', true)
            ->assertJsonCount(0, 'data.standing_editor_notes.notes');
    }

    public function test_delivered_shoot_keeps_the_note_it_was_delivered_with(): void
    {
        [$client, $editor, $delivered] = $this->editingShoot();
        $open = Shoot::factory()->create([
            'client_id' => $client->id, 'editor_id' => $editor->id,
            'status' => Shoot::STATUS_EDITING, 'workflow_status' => Shoot::STATUS_EDITING,
        ]);
        $note = $this->brokerageNote();

        $delivered->update(['status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $this->assertSame(self::GRASS, $delivered->fresh()->standing_editor_notes_snapshot[0]['content']);

        $note->update(['content' => 'Updated instruction.']);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/shoots/{$delivered->id}")
            ->assertOk()
            ->assertJsonPath('data.standing_editor_notes.frozen', true)
            ->assertJsonPath('data.standing_editor_notes.notes.0.content', self::GRASS);
        $this->getJson("/api/shoots/{$open->id}")
            ->assertOk()
            ->assertJsonPath('data.standing_editor_notes.frozen', false)
            ->assertJsonPath('data.standing_editor_notes.notes.0.content', 'Updated instruction.');
    }

    public function test_shoots_delivered_before_the_note_existed_show_no_note(): void
    {
        $client = User::factory()->create(['role' => 'client', 'company_name' => 'Weichert Realtors']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $this->brokerageNote();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/shoots/{$shoot->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.standing_editor_notes.notes');
    }

    public function test_staff_can_skip_the_note_when_booking_but_a_client_cannot(): void
    {
        config(['app.timezone' => 'UTC']);
        \Illuminate\Support\Facades\Queue::fake();
        $client = User::factory()->create(['role' => 'client', 'company_name' => 'Weichert Realtors']);
        $photographer = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        $service = \App\Models\Service::factory()->create(['name' => 'HDR Photos', 'price' => 150, 'photographer_required' => true]);
        $photographer->setServiceCapabilities([$service->id]);
        $photographer->save();
        $book = fn (string $time) => $this->postJson('/api/shoots', [
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'address' => '412 Maple Ridge Ct', 'city' => 'Cherry Hill', 'state' => 'NJ', 'zip' => '08003',
            'services' => [['id' => $service->id, 'quantity' => 1]],
            'scheduled_at' => "2026-12-02T{$time}:00", 'timezone' => 'America/New_York',
            'standing_editor_notes_hidden' => true,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
        $book('09:00')->assertCreated();
        $this->assertTrue((bool) Shoot::query()->latest('id')->firstOrFail()->standing_editor_notes_hidden);

        Sanctum::actingAs($client);
        $book('15:00')->assertCreated();
        $this->assertFalse((bool) Shoot::query()->latest('id')->firstOrFail()->standing_editor_notes_hidden);
    }

    /** @return array{0: User, 1: User, 2: Shoot} */
    private function editingShoot(string $company = 'Weichert, Realtors – Cherry Hill'): array
    {
        $client = User::factory()->create(['role' => 'client', 'company_name' => $company]);
        $editor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'editor_id' => $editor->id,
            'status' => Shoot::STATUS_EDITING,
            'workflow_status' => Shoot::STATUS_EDITING,
        ]);

        return [$client, $editor, $shoot];
    }

    private function brokerageNote(): StandingEditorNote
    {
        return StandingEditorNote::create(['scope' => 'brokerage', 'company_match' => 'Weichert', 'content' => self::GRASS]);
    }
}
