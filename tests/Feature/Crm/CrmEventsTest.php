<?php

namespace Tests\Feature\Crm;

use App\Models\Crm\CrmContact;
use App\Models\Crm\CrmEvent;
use App\Models\Crm\CrmEventType;

class CrmEventsTest extends CrmTestCase
{
    private function typeId(string $key): int
    {
        return (int) CrmEventType::query()->where('key', $key)->value('id');
    }

    public function test_seeded_event_types(): void
    {
        $this->actAs($this->rep());
        $keys = collect($this->getJson('/api/crm/event-types')->assertOk()->json('data'))->pluck('key')->all();
        $this->assertSame(['call', 'email', 'checkin_call', 'checkin_email', 'meeting', 'shoot', 'interview', 'other'], $keys);
    }

    public function test_schedule_and_follow_up_groups(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep);
        $this->actAs($rep);

        $this->postJson("/api/crm/contacts/{$contact->id}/events", ['type_id' => $this->typeId('call'), 'starts_at' => now()->subDays(2)->setTime(15, 0)->toIso8601String()])->assertCreated();
        $this->postJson("/api/crm/contacts/{$contact->id}/events", ['type_id' => $this->typeId('email'), 'starts_at' => now()->setTime(23, 30)->toIso8601String()])->assertCreated();
        $this->postJson("/api/crm/contacts/{$contact->id}/events", ['type' => 'meeting', 'starts_at' => now()->addDays(3)->toIso8601String(), 'duration_min' => 45])->assertCreated();

        $groups = $this->getJson('/api/crm/follow-ups')->assertOk()->json('groups');
        $this->assertCount(1, $groups['overdue']);
        $this->assertSame('2 days', $groups['overdue'][0]['late']);
        $this->assertCount(1, $groups['today']);
        $this->assertCount(1, $groups['upcoming']);

        $card = collect($this->getJson('/api/crm/pipeline')->json('columns.0.contacts'))->firstWhere('id', $contact->id);
        $this->assertSame('overdue', $card['footer']['kind']);
        $this->assertSame(1, collect($this->getJson('/api/crm/pipeline')->json('stats'))->firstWhere('key', 'overdue')['value']);
    }

    public function test_rep_cannot_schedule_for_someone_else_or_touch_others_events(): void
    {
        $rep = $this->rep();
        $other = $this->rep();
        $contact = $this->contactFor($rep);
        $theirs = $this->contactFor($other);
        $theirEvent = app(\App\Services\Crm\CrmEventService::class)->schedule($theirs, ['type' => 'call', 'starts_at' => now()->addDay()->toIso8601String()], $other);
        $this->actAs($rep);

        $this->postJson("/api/crm/contacts/{$contact->id}/events", ['type' => 'call', 'starts_at' => now()->toIso8601String(), 'assigned_user_id' => $other->id])->assertUnprocessable();
        $this->postJson("/api/crm/events/{$theirEvent->id}/complete", ['status' => 'done', 'outcome_note' => 'x'])->assertNotFound();
        $this->getJson("/api/crm/follow-ups?user_id={$other->id}")->assertForbidden();
    }

    public function test_completing_needs_an_outcome_and_can_schedule_the_next_event(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['lead_source' => 'website']);
        $this->actAs($rep);
        $eventId = $this->postJson("/api/crm/contacts/{$contact->id}/events", ['type' => 'call', 'starts_at' => now()->toIso8601String()])->json('event.id');

        $this->postJson("/api/crm/events/{$eventId}/complete", ['status' => 'done'])->assertUnprocessable();
        $response = $this->postJson("/api/crm/events/{$eventId}/complete", [
            'status' => 'done',
            'outcome_note' => 'Wants a quote for 3 listings',
            'next' => ['type_id' => $this->typeId('email'), 'starts_at' => now()->addDay()->toIso8601String(), 'note' => 'Send quote'],
        ])->assertOk();

        $this->assertSame('done', $response->json('event.status'));
        $this->assertSame('Send quote', $response->json('next.note'));
        $this->assertNotNull($contact->fresh()->last_contacted_at);
        $this->assertDatabaseHas('crm_activities', ['contact_id' => $contact->id, 'type' => 'event_done']);
    }

    public function test_marking_lost_cancels_planned_events(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep);
        $this->actAs($rep);
        $this->postJson("/api/crm/contacts/{$contact->id}/events", ['type' => 'call', 'starts_at' => now()->addDay()->toIso8601String()])->assertCreated();

        $this->postJson("/api/crm/contacts/{$contact->id}/lost", ['reason' => 'no_response'])->assertOk();
        $this->assertSame('cancelled', CrmEvent::query()->firstOrFail()->status);
    }

    public function test_only_admins_manage_event_types(): void
    {
        $this->actAs($this->rep());
        $this->postJson('/api/crm/event-types', ['label' => 'Site visit'])->assertForbidden();

        $this->actAs($this->admin());
        $this->postJson('/api/crm/event-types', ['label' => 'Site visit', 'icon' => 'map-pin'])->assertCreated()->assertJsonPath('type.key', 'site_visit');
    }

    public function test_contact_view_shows_next_event(): void
    {
        $rep = $this->rep();
        $contact = $this->contactFor($rep, ['stage' => CrmContact::STAGE_INQUIRY]);
        $this->actAs($rep);
        $this->postJson("/api/crm/contacts/{$contact->id}/events", ['type' => 'checkin_call', 'starts_at' => now()->addDays(2)->toIso8601String()]);

        $this->getJson("/api/crm/contacts/{$contact->id}")->assertOk()->assertJsonPath('contact.next_event.type.key', 'checkin_call');
    }
}
