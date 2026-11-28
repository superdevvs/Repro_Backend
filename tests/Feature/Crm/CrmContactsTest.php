<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmAssignmentChange;
use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmDuplicateAlert;
use App\Models\Crm\CrmStageChange;
use Illuminate\Support\Facades\Route;

class CrmContactsTest extends CrmTestCase
{
    public function test_crm_is_dark_until_enabled(): void
    {
        config(['crm.enabled' => false]);
        $this->actAs($this->rep());

        $this->getJson('/api/crm/meta')->assertNotFound();
    }

    public function test_clients_and_photographers_cannot_use_the_crm(): void
    {
        $this->actAs(\App\Models\User::factory()->create(['role' => 'client']));
        $this->getJson('/api/crm/meta')->assertForbidden();

        $this->actAs(\App\Models\User::factory()->photographer()->create());
        $this->getJson('/api/crm/contacts')->assertForbidden();
    }

    public function test_rep_creates_contact_assigned_to_themselves_in_outreach(): void
    {
        $rep = $this->actAs($this->rep());

        $response = $this->postJson('/api/crm/contacts', [
            'name' => 'Dana Whitfield',
            'company' => 'Whitfield & Rowe Realty',
            'email' => 'Dana@WhitfieldRowe.com',
            'phone' => '(301) 555-0142',
            'segment' => 'residential',
            'lead_source' => 'conference',
        ])->assertCreated();

        $response->assertJsonPath('contact.stage', 'outreach')
            ->assertJsonPath('contact.assigned_rep.id', $rep->id);
        $contact = CrmContact::unscopedForSystem()->firstOrFail();
        $this->assertSame('dana@whitfieldrowe.com', $contact->email_normalized);
        $this->assertSame('3015550142', $contact->phone_digits);
        $this->assertSame(1, CrmStageChange::query()->where('contact_id', $contact->id)->count());
        $this->assertSame(1, CrmAssignmentChange::query()->where('contact_id', $contact->id)->where('to_rep_id', $rep->id)->count());
    }

    public function test_segment_and_name_are_required(): void
    {
        $this->actAs($this->rep());
        $this->postJson('/api/crm/contacts', ['company' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'segment']);
    }

    public function test_rep_cannot_set_or_change_the_assigned_rep_anywhere(): void
    {
        $rep = $this->rep();
        $other = $this->rep();
        $contact = $this->contactFor($rep);
        $this->actAs($rep);

        $this->postJson('/api/crm/contacts', ['name' => 'New Person', 'segment' => 'residential', 'assigned_rep_id' => $other->id])->assertForbidden();
        $this->patchJson("/api/crm/contacts/{$contact->id}", ['assigned_rep_id' => $other->id])->assertForbidden();
        $this->postJson("/api/crm/contacts/{$contact->id}/assign", ['rep_id' => $other->id])->assertForbidden();
        $this->postJson('/api/crm/contacts/bulk-assign', ['ids' => [$contact->id], 'rep_id' => $other->id])->assertForbidden();
        $this->postJson('/api/crm/imports/commit', ['csv' => "name,segment\nPat Doe,residential\n", 'assign_to' => $other->id])->assertForbidden();

        $this->assertSame($rep->id, $contact->fresh()->assigned_rep_id);
    }

    public function test_admin_assigns_and_every_change_is_logged(): void
    {
        $rep = $this->rep();
        $other = $this->rep();
        $contact = $this->contactFor($rep);
        $admin = $this->actAs($this->admin());

        $this->postJson("/api/crm/contacts/{$contact->id}/assign", ['rep_id' => $other->id])->assertOk()
            ->assertJsonPath('contact.assigned_rep.id', $other->id);

        $this->assertDatabaseHas('crm_assignment_changes', [
            'contact_id' => $contact->id, 'from_rep_id' => $rep->id, 'to_rep_id' => $other->id, 'changed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('crm_notices', ['user_id' => $other->id, 'type' => 'assigned', 'contact_id' => $contact->id]);
    }

    public function test_rep_sees_only_their_own_contacts_everywhere(): void
    {
        $repA = $this->rep();
        $repB = $this->rep();
        $mine = $this->contactFor($repA, ['name' => 'Mine One']);
        $theirs = $this->contactFor($repB, ['name' => 'Theirs One']);
        $this->contactFor($repB, ['name' => 'Theirs Pool', 'stage' => CrmContact::STAGE_CLIENT_POOL]);
        $this->actAs($repA);

        $ids = collect($this->getJson('/api/crm/contacts?include_lost=1')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$mine->id], $ids->all());

        $board = $this->getJson('/api/crm/pipeline')->assertOk();
        $this->assertSame([$mine->id], collect($board->json('columns'))->flatMap(fn ($c) => collect($c['contacts'])->pluck('id'))->all());
        $this->assertSame(0, $this->getJson('/api/crm/client-pool')->assertOk()->json('meta.total'));
        $this->assertSame([], $this->getJson('/api/crm/contacts?search=Theirs')->json('data'));

        // Every route that takes a contact id answers 404 for another rep's contact.
        foreach ([
            ['GET', "contacts/{$theirs->id}"],
            ['PATCH', "contacts/{$theirs->id}", ['company' => 'Hijack']],
            ['POST', "contacts/{$theirs->id}/stage", ['stage' => 'inquiry']],
            ['POST', "contacts/{$theirs->id}/lost", ['reason' => 'price']],
            ['POST', "contacts/{$theirs->id}/reopen", ['stage' => 'inquiry']],
            ['POST', "contacts/{$theirs->id}/first-shoot", ['date' => now()->toDateString()]],
            ['GET', "contacts/{$theirs->id}/activity"],
            ['GET', "contacts/{$theirs->id}/notes"],
            ['POST', "contacts/{$theirs->id}/notes", ['body' => 'x']],
        ] as $call) {
            $this->json($call[0], '/api/crm/'.$call[1], $call[2] ?? [])->assertNotFound();
        }

        $this->assertSame('Test Realty', $theirs->fresh()->company);
    }

    public function test_admin_sees_everyone(): void
    {
        $this->contactFor($this->rep());
        $this->contactFor($this->rep());
        $this->actAs($this->admin());

        $this->assertSame(2, $this->getJson('/api/crm/contacts')->assertOk()->json('meta.total'));
    }

    public function test_contact_search_matches_literal_wildcards_and_unicode_within_the_actor_scope(): void
    {
        $owner = $this->rep();
        $otherRep = $this->rep();
        $mine = $this->contactFor($owner, [
            'name' => '東京 café 50% [draft].',
            'company' => 'são_joão!',
            'email' => 'Sales_Desk@Example.Test',
        ]);
        $this->contactFor($owner, [
            'name' => 'Metro cafe 500 draft!',
            'company' => 'sãoxjoão?',
            'email' => 'salesxdesk@example.test',
        ]);
        $theirs = $this->contactFor($otherRep, ['name' => '東京 café 50%']);
        $path = fn (string $term) => '/api/crm/contacts?search='.rawurlencode($term);

        $this->assertSame('sales_desk@example.test', $mine->email_normalized);
        $this->actAs($owner);
        foreach (['50%', 'são_joão', 'joão!', '[draft].', 'CAFÉ 50%', '東京', ' SALES_DESK@EXAMPLE.TEST '] as $term) {
            $this->getJson($path($term))->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $mine->id);
        }
        $this->getJson($path('CAFE 50%'))->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');

        $this->actAs($otherRep);
        $this->getJson($path('東京'))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $theirs->id);
        $this->getJson($path('SALES_DESK@EXAMPLE.TEST'))->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');

        $this->actAs(\App\Models\User::factory()->create(['role' => 'client']));
        $this->getJson($path('東京'))->assertForbidden();
    }

    public function test_contact_search_preserves_sqlite_matching_for_existing_accented_case(): void
    {
        $rep = $this->actAs($this->rep());
        $contact = $this->contactFor($rep, ['name' => 'Élodie']);
        $path = '/api/crm/contacts?search='.rawurlencode('Élodie');

        $this->getJson($path)->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');

        $this->patchJson("/api/crm/contacts/{$contact->id}", ['name' => 'élodie'])->assertOk()
            ->assertJsonPath('contact.name', 'élodie');
        $this->getJson($path)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $contact->id);
    }

    public function test_contact_search_preserves_sqlite_matching_before_embedded_nul(): void
    {
        $rep = $this->actAs($this->rep());
        $prefix = $this->contactFor($rep, ['name' => "alpha\0beta"]);
        $suffix = $this->contactFor($rep, ['name' => 'Leading tail']);
        $this->contactFor($rep, ['name' => 'Leading tail extra']);
        $path = fn (string $term) => '/api/crm/contacts?search='.rawurlencode($term);

        $this->getJson($path('beta'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($path('alpha'))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $prefix->id);
        $this->getJson($path("tail\0ignored"))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $suffix->id);
    }

    public function test_no_delete_routes_exist_for_crm(): void
    {
        $deletes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/crm') && in_array('DELETE', $r->methods(), true))
            ->map(fn ($r) => $r->uri())->values()->all();

        $this->assertSame([], $deletes);
    }

    public function test_duplicate_email_is_blocked_and_rep_learns_nothing_about_the_owner(): void
    {
        $owner = $this->rep(['name' => 'Chris Patel']);
        $existing = $this->contactFor($owner, ['name' => 'Dana Whitfield', 'email' => 'dana@whitfieldrowe.com']);
        $rep = $this->actAs($this->rep());
        $this->admin();

        $response = $this->postJson('/api/crm/contacts', ['name' => 'D. Whitfield', 'email' => ' DANA@whitfieldrowe.com ', 'segment' => 'residential'])->assertStatus(409);

        $response->assertJsonPath('duplicate.reason', 'email')->assertJsonPath('duplicate.admin_notified', true);
        $this->assertArrayNotHasKey('match', $response->json('duplicate'));
        $this->assertStringNotContainsString('Chris', $response->getContent());
        $this->assertDatabaseHas('crm_duplicate_alerts', ['attempted_by' => $rep->id, 'matched_contact_id' => $existing->id, 'match_type' => 'email']);
    }

    public function test_duplicate_phone_ignores_formatting_and_checks_lost_contacts(): void
    {
        $this->contactFor($this->rep(), ['name' => 'Old Lead', 'phone' => '+1 301 555 0142', 'stage' => CrmContact::STAGE_LOST]);
        $this->actAs($this->rep());

        $this->postJson('/api/crm/contacts', ['name' => 'Someone Else', 'phone' => '301.555.0142', 'segment' => 'residential'])
            ->assertStatus(409)->assertJsonPath('duplicate.reason', 'phone');
    }

    public function test_same_name_needs_an_admin_to_confirm_a_different_person(): void
    {
        $this->contactFor($this->rep(), ['name' => 'James Carter']);

        $this->actAs($this->rep());
        $this->postJson('/api/crm/contacts', ['name' => 'james  carter', 'segment' => 'residential', 'confirm_different_person' => true])
            ->assertStatus(409)->assertJsonPath('duplicate.reason', 'name');

        $this->actAs($this->admin());
        $this->postJson('/api/crm/contacts', ['name' => 'James Carter', 'segment' => 'residential'])
            ->assertStatus(409)->assertJsonPath('duplicate.overridable', true);
        $this->postJson('/api/crm/contacts', ['name' => 'James Carter', 'segment' => 'residential', 'confirm_different_person' => true])->assertCreated();
    }

    public function test_editing_into_a_duplicate_is_blocked(): void
    {
        $rep = $this->rep();
        $this->contactFor($this->rep(), ['name' => 'A Person', 'email' => 'taken@example.com']);
        $mine = $this->contactFor($rep, ['name' => 'B Person']);
        $this->actAs($rep);

        $this->patchJson("/api/crm/contacts/{$mine->id}", ['email' => 'Taken@example.com'])->assertStatus(409);
        $this->patchJson("/api/crm/contacts/{$mine->id}", ['company' => 'New Co'])->assertOk()->assertJsonPath('contact.company', 'New Co');
    }

    public function test_stage_moves_are_recorded_with_old_new_and_who(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep);
        $this->actAs($rep);

        $this->postJson("/api/crm/contacts/{$contact->id}/stage", ['stage' => 'inquiry'])->assertOk()->assertJsonPath('contact.stage', 'inquiry');
        $this->assertDatabaseHas('crm_stage_changes', ['contact_id' => $contact->id, 'from_stage' => 'outreach', 'to_stage' => 'inquiry', 'changed_by' => $rep->id, 'source' => 'user']);

        $this->postJson("/api/crm/contacts/{$contact->id}/stage", ['stage' => 'client_pool'])->assertUnprocessable();
        $this->postJson("/api/crm/contacts/{$contact->id}/first-shoot", ['date' => now()->toDateString()])->assertUnprocessable();
    }

    public function test_first_shoot_completed_moves_booked_contact_to_client_pool(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['stage' => CrmContact::STAGE_BOOKED]);
        $this->actAs($rep);

        $this->postJson("/api/crm/contacts/{$contact->id}/first-shoot", ['date' => now()->subDay()->toDateString(), 'client_type' => 'recurring'])
            ->assertOk()->assertJsonPath('contact.stage', 'client_pool')->assertJsonPath('contact.client_type', 'recurring');
        $this->assertSame(now()->subDay()->toDateString(), $contact->fresh()->first_shoot_date->toDateString());
    }

    public function test_lost_needs_a_reason_and_can_be_reopened_to_any_stage(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['stage' => CrmContact::STAGE_ESTIMATE]);
        $this->actAs($rep);

        $this->postJson("/api/crm/contacts/{$contact->id}/lost", [])->assertUnprocessable();
        $this->postJson("/api/crm/contacts/{$contact->id}/lost", ['reason' => 'competitor', 'note' => 'Brokerage contract'])
            ->assertOk()->assertJsonPath('contact.stage', 'lost')->assertJsonPath('contact.lost_reason_label', 'Chose competitor');

        $this->assertSame([], $this->getJson('/api/crm/contacts')->json('data'));
        $this->assertCount(1, $this->getJson('/api/crm/contacts?include_lost=1')->json('data'));
        $this->assertSame(1, $this->getJson('/api/crm/pipeline')->json('lost_hidden'));

        $this->postJson("/api/crm/contacts/{$contact->id}/reopen", ['stage' => 'inquiry'])->assertOk()->assertJsonPath('contact.stage', 'inquiry');
        $this->assertNull($contact->fresh()->lost_reason);
        $this->assertSame(3, CrmStageChange::query()->where('contact_id', $contact->id)->count());
    }

    public function test_notes_are_versioned_and_only_the_author_edits(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep);
        $this->actAs($rep);

        $noteId = $this->postJson("/api/crm/contacts/{$contact->id}/notes", ['body' => 'First text'])->assertCreated()->json('note.id');
        $this->patchJson("/api/crm/contacts/{$contact->id}/notes/{$noteId}", ['body' => 'Second text'])->assertOk()->assertJsonPath('note.body', 'Second text');
        $versions = $this->getJson("/api/crm/contacts/{$contact->id}/notes/{$noteId}/versions")->assertOk()->json('versions');
        $this->assertSame('First text', $versions[0]['body']);

        $this->actAs($this->admin());
        $this->patchJson("/api/crm/contacts/{$contact->id}/notes/{$noteId}", ['body' => 'Admin rewrite'])->assertForbidden();
    }

    public function test_activity_lists_history_newest_first(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep);
        $this->actAs($rep);
        $this->postJson("/api/crm/contacts/{$contact->id}/stage", ['stage' => 'inquiry']);

        $types = collect($this->getJson("/api/crm/contacts/{$contact->id}/activity")->assertOk()->json('data'))->pluck('type')->all();
        $this->assertSame(['stage', 'created'], $types);
    }

    public function test_unassigned_queue_and_duplicate_resolution(): void
    {
        $rep = $this->rep(['name' => 'Maya Torres']);
        $owner = $this->rep();
        $lead = $this->contactFor(null, ['name' => 'Elliot Navarro', 'market_state' => 'MD']);
        $existing = $this->contactFor($owner, ['name' => 'Dana Whitfield', 'email' => 'dana@x.com']);

        $this->actAs($rep);
        $this->getJson('/api/crm/queue/unassigned')->assertForbidden();
        $this->postJson('/api/crm/contacts', ['name' => 'Dana W', 'email' => 'dana@x.com', 'segment' => 'residential'])->assertStatus(409);
        $alert = CrmDuplicateAlert::query()->firstOrFail();

        $this->actAs($this->admin());
        $queue = $this->getJson('/api/crm/queue/unassigned')->assertOk();
        $this->assertSame([$lead->id], collect($queue->json('leads'))->pluck('id')->all());
        $this->assertNotNull($queue->json('leads.0.suggestion.rep.id'));
        $this->assertSame($alert->id, $queue->json('duplicate_attempts.0.id'));

        $this->postJson("/api/crm/queue/duplicates/{$alert->id}/resolve", ['resolution' => 'reassigned'])->assertOk();
        $this->assertSame($rep->id, $existing->fresh()->assigned_rep_id);
        $this->assertDatabaseHas('crm_notices', ['user_id' => $rep->id, 'type' => 'duplicate_resolved']);
    }

    public function test_csv_import_preview_and_commit(): void
    {
        $this->contactFor($this->rep(), ['name' => 'Existing Person', 'email' => 'exist@x.com']);
        $rep = $this->actAs($this->rep());
        $csv = "Full Name,Email,Phone,Segment,State,City\n"
            ."Pat One,pat@x.com,301-555-1111,Residential,MD,Bethesda\n"
            ."Pat Two,exist@x.com,,Residential,MD,\n"
            ."Pat Three,pat@x.com,,Residential,MD,\n"
            .",nobody@x.com,,Residential,MD,\n"
            ."Existing Person,other@x.com,,Commercial,VA,\n";

        $preview = $this->postJson('/api/crm/imports/preview', ['csv' => $csv])->assertOk();
        $preview->assertJsonPath('counts.ready', 1)->assertJsonPath('counts.duplicate', 2)->assertJsonPath('counts.invalid', 1)->assertJsonPath('counts.name_match', 1)
            ->assertJsonPath('assign_locked', true);
        $this->assertArrayNotHasKey('contact', $preview->json('rows.1.match'));

        $this->postJson('/api/crm/imports/commit', ['csv' => $csv])->assertOk()->assertJsonPath('created', 1);
        $created = CrmContact::unscopedForSystem()->where('email_normalized', 'pat@x.com')->firstOrFail();
        $this->assertSame($rep->id, $created->assigned_rep_id);
        $this->assertSame('Bethesda', $created->market_city);
    }

    public function test_meta_reports_capabilities_per_role(): void
    {
        $this->actAs($this->rep());
        $this->getJson('/api/crm/meta')->assertOk()
            ->assertJsonPath('can.assign', false)->assertJsonPath('can.view_all', false)->assertJsonPath('can.recruiting', false)->assertJsonPath('can.approve_signatures', false);

        $this->actAs($this->admin());
        $this->getJson('/api/crm/meta')->assertOk()
            ->assertJsonPath('can.assign', true)->assertJsonPath('can.approve_signatures', false)->assertJsonPath('can.recruiting', false);

        $this->actAs($this->superAdmin());
        $this->getJson('/api/crm/meta')->assertOk()->assertJsonPath('can.approve_signatures', true)->assertJsonPath('can.recruiting', true);
    }
}
