<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Shoot;
use App\Models\User;

class CrmClientLinkTest extends CrmTestCase
{
    private function shootFor(User $client, array $attributes = []): Shoot
    {
        return Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'completed_at' => null,
        ], $attributes));
    }

    public function test_booking_moves_a_matching_lead_to_booked_and_completion_to_client_pool(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['name' => 'Naomi Castellano', 'email' => 'naomi@kw.com', 'stage' => CrmContact::STAGE_INQUIRY]);
        $client = User::factory()->create(['role' => 'client', 'email' => 'Naomi@KW.com']);

        $shoot = $this->shootFor($client);
        $contact->refresh();
        $this->assertSame(CrmContact::STAGE_BOOKED, $contact->stage);
        $this->assertSame($client->id, $contact->client_user_id);
        $this->assertDatabaseHas('crm_stage_changes', ['contact_id' => $contact->id, 'to_stage' => 'booked', 'source' => 'system']);

        $shoot->forceFill(['completed_at' => now()->subHour(), 'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED])->save();
        $contact->refresh();
        $this->assertSame(CrmContact::STAGE_CLIENT_POOL, $contact->stage);
        $this->assertSame('one_off', $contact->client_type);
        $this->assertNotNull($contact->first_shoot_date);
    }

    public function test_test_shoots_and_import_drafts_are_ignored(): void
    {
        $contact = $this->contactFor($this->rep(), ['email' => 'quiet@x.com']);
        $client = User::factory()->create(['role' => 'client', 'email' => 'quiet@x.com']);

        $this->shootFor($client, ['shoot_type' => Shoot::SHOOT_TYPE_INTERNAL_TEST]);
        $this->assertSame(CrmContact::STAGE_OUTREACH, $contact->fresh()->stage);
    }

    public function test_crm_off_means_no_changes(): void
    {
        config(['crm.enabled' => false]);
        $contact = $this->contactFor($this->rep(), ['email' => 'off@x.com']);
        $client = User::factory()->create(['role' => 'client', 'email' => 'off@x.com']);
        $this->shootFor($client);

        $this->assertSame(CrmContact::STAGE_OUTREACH, $contact->fresh()->stage);
    }

    public function test_lost_contact_who_books_again_is_reopened(): void
    {
        $contact = $this->contactFor($this->rep(), ['email' => 'back@x.com', 'stage' => CrmContact::STAGE_ESTIMATE]);
        app(\App\Services\Crm\CrmStageService::class)->markLost($contact, 'price', null, null);
        $client = User::factory()->create(['role' => 'client', 'email' => 'back@x.com']);
        $this->shootFor($client);

        $this->assertSame(CrmContact::STAGE_BOOKED, $contact->fresh()->stage);
    }

    public function test_new_client_without_a_contact_gets_one_owned_by_their_account_rep(): void
    {
        $rep = $this->rep();
        $client = User::factory()->create(['role' => 'client', 'email' => 'fresh@x.com', 'metadata' => ['accountRepId' => (string) $rep->id]]);
        $this->shootFor($client);

        $contact = CrmContact::unscopedForSystem()->where('client_user_id', $client->id)->firstOrFail();
        $this->assertSame($rep->id, $contact->assigned_rep_id);
        $this->assertSame(CrmContact::STAGE_BOOKED, $contact->stage);
    }

    public function test_import_clients_is_idempotent_and_flags_for_review(): void
    {
        $rep = $this->rep();
        $withRep = User::factory()->create(['role' => 'client', 'email' => 'a@x.com', 'metadata' => ['accountRepId' => $rep->id]]);
        $noRep = User::factory()->create(['role' => 'client', 'email' => 'b@x.com']);
        $ineligible = User::factory()->create(['role' => 'photographer']);
        $formerRep = User::factory()->create(['role' => 'client', 'email' => 'c@x.com', 'metadata' => ['accountRepId' => $ineligible->id]]);
        config(['crm.enabled' => false]); // build history without the live hook
        $this->shootFor($withRep, ['completed_at' => now()->subMonths(2), 'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        $this->shootFor($withRep, ['completed_at' => now()->subMonth(), 'status' => Shoot::STATUS_DELIVERED, 'workflow_status' => Shoot::STATUS_DELIVERED]);
        config(['crm.enabled' => true]);

        $this->artisan('crm:import-clients', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, CrmContact::unscopedForSystem()->count());

        $this->artisan('crm:import-clients')->assertSuccessful();
        $this->artisan('crm:import-clients')->assertSuccessful();

        $a = CrmContact::unscopedForSystem()->where('client_user_id', $withRep->id)->firstOrFail();
        $b = CrmContact::unscopedForSystem()->where('client_user_id', $noRep->id)->firstOrFail();
        $this->assertSame(3, CrmContact::unscopedForSystem()->whereNotNull('client_user_id')->count());
        $this->assertSame([CrmContact::STAGE_CLIENT_POOL, 'recurring', $rep->id], [$a->stage, $a->client_type, $a->assigned_rep_id]);
        $this->assertContains('segment_not_confirmed', $a->review_reasons);
        $this->assertNull($b->assigned_rep_id);
        $this->assertContains('no_rep', $b->review_reasons);
        $c = CrmContact::unscopedForSystem()->where('client_user_id', $formerRep->id)->firstOrFail();
        $this->assertNull($c->assigned_rep_id);
        $this->assertContains('account_rep_lacks_crm_access', $c->review_reasons);
    }
}
