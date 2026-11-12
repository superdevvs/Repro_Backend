<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\MobileAssistantAction;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MobileAssistant\AssistantCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile-assistant.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('s', 32))]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    public function test_every_role_has_a_scoped_briefing_and_no_implicit_privilege_expansion(): void
    {
        foreach (AssistantCapabilities::ROLES as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $payload = $this->actingAs($actor)->getJson('/api/mobile/assistant/capabilities')->assertOk()->json();
            $actions = array_column($payload['actions'], 'id');
            $this->assertContains('briefing', $actions, $role);
            $this->assertContains('robbie.ask', $actions, $role);
            $this->assertContains('support.create', $actions, $role);
            $this->assertSame(in_array($role, ['admin', 'superadmin'], true), in_array('call.start', $actions, true), $role);
            $this->assertSame($role === 'superadmin', in_array('reports.read', $actions, true), $role);
            $this->assertSame($role === 'photographer', in_array('earnings.read', $actions, true), $role);
            if (in_array($role, ['client', 'editor'], true)) {
                $this->assertNotContains('availability.create', $actions);
            }
            if (in_array($role, ['editor', 'editing_manager'], true)) {
                $this->assertNotContains('note.create', $actions);
            }
        }
    }

    public function test_feature_gate_and_authentication_are_enforced(): void
    {
        $this->getJson('/api/mobile/assistant/capabilities')->assertUnauthorized();
        $actor = User::factory()->create(['role' => 'admin']);
        config(['mobile-assistant.enabled' => false]);
        $this->actingAs($actor)->getJson('/api/mobile/assistant/capabilities')->assertStatus(503);
        config(['mobile-assistant.enabled' => true, 'mobile-assistant.pilot_user_ids' => [$actor->id + 1]]);
        $this->getJson('/api/mobile/assistant/capabilities')->assertForbidden();
    }

    public function test_client_search_and_detail_do_not_disclose_other_clients_or_staff_notes(): void
    {
        $actor = User::factory()->create(['role' => 'client']);
        $own = Shoot::factory()->create(['client_id' => $actor->id, 'address' => '10 Own Street']);
        $other = Shoot::factory()->create(['address' => '99 Private Street']);
        $own->notes()->create(['author_id' => $actor->id, 'type' => 'company', 'visibility' => 'internal', 'content' => 'Confidential margin']);
        $own->notes()->create(['author_id' => $actor->id, 'type' => 'shoot', 'visibility' => 'client_visible', 'content' => 'Meet at the door']);
        $this->actingAs($actor)->postJson('/api/mobile/assistant/query', ['action' => 'shoot.search'])
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('cards.0.record_id', (string) $own->id);
        $this->postJson('/api/mobile/assistant/query', ['action' => 'shoot.details', 'shoot_id' => $other->id])->assertNotFound();
        $response = $this->postJson('/api/mobile/assistant/query', ['action' => 'shoot.details', 'shoot_id' => $own->id])->assertOk();
        $this->assertStringNotContainsString('Confidential margin', $response->getContent());
        $response->assertJsonPath('details.notes.0.content', 'Meet at the door');
    }

    public function test_note_is_only_saved_after_confirmation_and_replays_do_not_duplicate_it(): void
    {
        $actor = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $actor->id]);
        $key = (string) Str::uuid();
        $body = ['request_id' => $key, 'action' => 'note.create', 'params' => ['shoot_id' => $shoot->id, 'content' => 'Please meet me outside.']];
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', $body)->assertOk()->json();
        $this->assertSame(0, $shoot->notes()->count());
        $this->assertStringNotContainsString('Please meet me outside.', MobileAssistantAction::findOrFail($proposal['id'])->getRawOriginal('proposal'));
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => false])->assertUnprocessable();
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'completed');
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'completed');
        $this->postJson('/api/mobile/assistant/actions/prepare', $body)->assertOk()->assertJsonPath('id', $proposal['id']);
        $this->assertSame(1, $shoot->notes()->count());
        $this->assertSame('client_visible', $shoot->notes()->first()->visibility);
    }

    public function test_cancel_expiry_and_conflicting_replay_never_write(): void
    {
        $actor = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $actor->id]);
        $body = ['request_id' => (string) Str::uuid(), 'action' => 'note.create', 'params' => ['shoot_id' => $shoot->id, 'content' => 'Original note']];
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', $body)->assertOk()->json();
        $body['params']['content'] = 'Changed note';
        $this->postJson('/api/mobile/assistant/actions/prepare', $body)->assertConflict();
        $this->deleteJson('/api/mobile/assistant/actions/'.$proposal['id'])->assertOk()->assertJsonPath('state', 'cancelled');
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'cancelled');
        $body['request_id'] = (string) Str::uuid();
        $proposal = $this->postJson('/api/mobile/assistant/actions/prepare', $body)->assertOk()->json();
        $this->travel(6)->minutes();
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertConflict();
        $this->assertSame(0, $shoot->notes()->count());
    }

    public function test_stale_record_and_removed_permission_require_new_confirmation(): void
    {
        $actor = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $actor->id]);
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', [
            'request_id' => (string) Str::uuid(), 'action' => 'note.create', 'params' => ['shoot_id' => $shoot->id, 'content' => 'Test note'],
        ])->assertOk()->json();
        $shoot->update(['address' => 'A different property']);
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertConflict();
        $actor->update(['permission_overrides' => ['allow' => [], 'deny' => ['shoots-view']]]);
        $this->actingAs($actor->fresh())->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertForbidden();
        $this->assertSame(0, $shoot->notes()->count());
    }

    public function test_receipts_are_owned_and_client_cannot_create_internal_notes(): void
    {
        $actor = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $actor->id]);
        $body = ['request_id' => (string) Str::uuid(), 'action' => 'note.create', 'params' => ['shoot_id' => $shoot->id, 'content' => 'My note']];
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', $body)->assertOk()->json();
        $body['request_id'] = (string) Str::uuid();
        $body['params']['visibility'] = 'internal';
        $this->postJson('/api/mobile/assistant/actions/prepare', $body)->assertForbidden();
        $this->actingAs($other)->getJson('/api/mobile/assistant/actions/'.$proposal['id'])->assertNotFound();
    }

    public function test_lock_safe_request_never_returns_private_shoot_details(): void
    {
        $actor = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $actor->id, 'status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_date' => '2026-11-13', 'scheduled_at' => '2026-11-13 15:00:00', 'timezone' => 'America/New_York', 'notes' => 'Door code 4321']);
        $response = $this->actingAs($actor)->postJson('/api/mobile/assistant/query', ['action' => 'briefing', 'date' => '2026-11-13', 'lock_safe' => true])->assertOk();
        $this->assertStringNotContainsString('4321', $response->getContent());
        $this->assertStringNotContainsString($actor->email, $response->getContent());
        $this->postJson('/api/mobile/assistant/query', ['action' => 'shoot.details', 'shoot_id' => $shoot->id, 'lock_safe' => true])->assertForbidden();
    }

    public function test_assignment_revocation_and_secondary_roles_do_not_expand_primary_role_gates(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $own = Shoot::factory()->create(['editor_id' => $editor->id]);
        $other = Shoot::factory()->create();
        $this->actingAs($editor)->postJson('/api/mobile/assistant/query', ['action' => 'shoot.details', 'shoot_id' => $own->id])->assertOk();
        $this->postJson('/api/mobile/assistant/query', ['action' => 'shoot.details', 'shoot_id' => $other->id])->assertNotFound();
        $own->update(['editor_id' => null]);
        $this->postJson('/api/mobile/assistant/query', ['action' => 'shoot.details', 'shoot_id' => $own->id])->assertNotFound();
        $client = User::factory()->create(['role' => 'client', 'secondary_roles' => ['superadmin']]);
        $this->actingAs($client)->postJson('/api/mobile/assistant/query', ['action' => 'reports.read'])->assertForbidden();
    }

    public function test_linked_client_can_read_shared_shoot_but_cannot_write_owners_note(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $linked = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $owner->id]);
        AccountLink::create(['main_account_id' => $linked->id, 'linked_account_id' => $owner->id,
            'shared_details' => ['shoots' => true], 'status' => 'active', 'linked_at' => now(), 'created_by' => $owner->id]);
        $this->actingAs($linked)->postJson('/api/mobile/assistant/query', ['action' => 'shoot.details', 'shoot_id' => $shoot->id])->assertOk();
        $this->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'note.create',
            'params' => ['shoot_id' => $shoot->id, 'content' => 'Not my booking']])->assertForbidden();
    }

    public function test_availability_counts_only_people_free_for_the_entire_requested_window(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $one = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        $two = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        foreach ([$one, $two] as $person) {
            \App\Models\PhotographerAvailability::create(['photographer_id' => $person->id,
                'date' => '2026-11-13', 'day_of_week' => 'friday', 'start_time' => '09:00', 'end_time' => '17:00', 'status' => 'available']);
        }
        \App\Models\PhotographerAvailability::create(['photographer_id' => $two->id, 'date' => '2026-11-13',
            'day_of_week' => 'friday', 'start_time' => '11:00', 'end_time' => '12:00', 'status' => 'unavailable']);
        $query = ['action' => 'availability.read', 'date' => '2026-11-13', 'start_time' => '10:00', 'end_time' => '12:00'];
        $this->actingAs($admin)->postJson('/api/mobile/assistant/query', $query)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('cards.0.record_id', (string) $one->id);
        $this->actingAs($two)->postJson('/api/mobile/assistant/query', $query)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_availability_confirm_is_atomic_and_other_photographers_are_denied(): void
    {
        $actor = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        $other = User::factory()->create(['role' => 'photographer']);
        $body = ['request_id' => (string) Str::uuid(), 'action' => 'availability.create', 'params' => ['photographer_id' => $actor->id,
            'date' => '2026-11-13', 'start_time' => '10:00', 'end_time' => '12:00', 'status' => 'available', 'timezone' => 'America/New_York']];
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', $body)->assertOk()->json();
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'completed');
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'completed');
        $this->assertDatabaseCount('photographer_availabilities', 1);
        $body['request_id'] = (string) Str::uuid();
        $body['params']['photographer_id'] = $other->id;
        $this->postJson('/api/mobile/assistant/actions/prepare', $body)->assertForbidden();
    }

    public function test_dst_gap_and_ambiguous_time_cannot_be_silently_shifted(): void
    {
        $actor = User::factory()->create(['role' => 'photographer', 'timezone' => 'America/New_York']);
        foreach ([['2027-03-14', '02:15', '03:30'], ['2027-11-07', '01:15', '02:30']] as [$date, $start, $end]) {
            $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'availability.create',
                'params' => ['date' => $date, 'start_time' => $start, 'end_time' => $end, 'status' => 'unavailable', 'timezone' => 'America/New_York']])->assertUnprocessable();
        }
    }

    public function test_robbie_can_read_but_never_executes_unconfirmed_changes(): void
    {
        config(['services.openai.api_key' => null]);
        $actor = User::factory()->create(['role' => 'client']);
        $response = $this->actingAs($actor)->postJson('/api/mobile/assistant/query', ['action' => 'robbie.ask',
            'prompt' => 'Change all my bookings and send a message', 'request_id' => (string) Str::uuid()])->assertOk();
        $response->assertJsonPath('requires_app', true);
        $this->assertDatabaseCount('mobile_assistant_actions', 0);
        $other = User::factory()->create(['role' => 'client']);
        $this->actingAs($other)->postJson('/api/mobile/assistant/query', ['action' => 'robbie.ask', 'prompt' => 'my day',
            'request_id' => (string) Str::uuid(), 'session_id' => $response->json('session_id')])->assertNotFound();
    }

    public function test_call_directory_disambiguates_and_a_confirmed_handoff_is_single_use(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $one = User::factory()->create(['role' => 'client', 'name' => 'Jamie Smith', 'phonenumber' => '+12025550112']);
        User::factory()->create(['role' => 'client', 'name' => 'Jamie Smith', 'phonenumber' => '+12025550113']);
        $number = \App\Models\SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550100', 'label' => 'Office', 'owner_type' => 'GLOBAL']);
        $this->actingAs($actor)->postJson('/api/mobile/assistant/query', ['action' => 'call.contacts', 'query' => 'Jamie Smith'])
            ->assertOk()->assertJsonCount(2, 'cards');
        $proposal = $this->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'call.start',
            'params' => ['recipient' => 'user:'.$one->id, 'caller_id' => (string) $number->id]])->assertOk()->json();
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/claim-handoff')->assertConflict();
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'handoff');
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/claim-handoff')->assertOk()->assertJsonPath('state', 'consumed')
            ->assertJsonPath('result.draft.phone', '+12025550112');
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/claim-handoff')->assertConflict();
        $this->assertDatabaseCount('voice_calls', 0); // Calling starts through the existing native coordinator only.
    }

    public function test_sms_confirmation_uses_actual_sender_and_receipt_never_sends_twice(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-13T16:00:00Z'));
        $actor = User::factory()->create(['role' => 'admin']);
        \App\Services\Messaging\OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $provider = \Mockery::mock(\App\Services\Messaging\Providers\TelnyxSmsProvider::class);
        $provider->shouldReceive('send')->once()->andReturn('siri-test-message');
        $this->app->instance(\App\Services\Messaging\Providers\TelnyxSmsProvider::class, $provider);
        \App\Models\SmsNumber::create(['phone_number' => '+12025550100', 'provider' => 'TELNYX', 'owner_type' => 'GLOBAL', 'is_default' => true]);
        $contact = \App\Models\Contact::create(['name' => 'Sam', 'phone' => '+12025550112', 'type' => 'client']);
        $thread = \App\Models\MessageThread::create(['channel' => 'SMS', 'contact_id' => $contact->id]);
        \App\Models\Message::create(['thread_id' => $thread->id, 'channel' => 'SMS', 'direction' => 'INBOUND', 'provider' => 'TEST',
            'from_address' => '+12025550112', 'to_address' => '+12025550100', 'body_text' => 'Question', 'send_source' => 'MANUAL', 'status' => 'DELIVERED']);
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'sms.send',
            'params' => ['thread_id' => $thread->id, 'body' => 'The shoot starts at noon.']])->assertOk()->json();
        $this->assertStringContainsString('Send from +12025550100 to Sam (+12025550112)', $proposal['summary']);
        $this->assertDatabaseCount('messages', 1);
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'completed');
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'completed');
        $this->assertSame(1, \App\Models\Message::where('metadata->assistant_action_id', $proposal['id'])->count());
    }

    public function test_sms_contact_change_requires_a_fresh_confirmation(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $number = \App\Models\SmsNumber::create(['phone_number' => '+12025550100', 'provider' => 'TELNYX', 'owner_type' => 'GLOBAL']);
        $contact = \App\Models\Contact::create(['name' => 'Sam', 'phone' => '+12025550112', 'type' => 'client']);
        $thread = \App\Models\MessageThread::create(['channel' => 'SMS', 'contact_id' => $contact->id]);
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'sms.send',
            'params' => ['thread_id' => $thread->id, 'sms_number_id' => $number->id, 'body' => 'Hello']])->assertOk()->json();
        $contact->update(['phone' => '+12025550113']);
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_impersonation_and_revoked_accounts_cannot_use_siri(): void
    {
        $actor = User::factory()->create(['role' => 'superadmin']);
        $client = User::factory()->create(['role' => 'client']);
        $this->actingAs($actor)->withHeader('X-Impersonate-User-Id', (string) $client->id)->getJson('/api/mobile/assistant/capabilities')->assertForbidden();
        $this->flushHeaders();
        $client->update(['account_status' => 'disabled']);
        $this->actingAs($client->fresh())->getJson('/api/mobile/assistant/capabilities')->assertUnauthorized();
    }

    public function test_read_answers_exclude_channel_secrets_and_provider_metadata(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $channel = \App\Models\MessageChannel::create(['type' => 'EMAIL', 'provider' => 'TEST', 'display_name' => 'Office',
            'from_email' => 'office@example.test', 'owner_scope' => 'GLOBAL', 'config_json' => ['password' => 'private-channel-credential']]);
        \App\Models\Message::create(['channel' => 'EMAIL', 'direction' => 'INBOUND', 'provider' => 'TEST',
            'message_channel_id' => $channel->id, 'from_address' => 'client@example.test', 'to_address' => 'office@example.test',
            'subject' => 'Tomorrow appointment', 'body_text' => 'Please call when you arrive.', 'status' => 'DELIVERED', 'send_source' => 'MANUAL',
            'metadata' => ['provider_internal' => 'private-provider-value'], 'attachments_json' => [['url' => 'private-attachment-url']]]);
        $response = $this->actingAs($actor)->postJson('/api/mobile/assistant/query', ['action' => 'email.read'])->assertOk()
            ->assertJsonPath('cards.0.title', 'Tomorrow appointment')->assertJsonPath('total', 1);
        foreach (['private-channel-credential', 'private-provider-value', 'private-attachment-url', 'config_json', 'metadata'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->postJson('/api/mobile/assistant/query', ['action' => 'email.read', 'lock_safe' => true])->assertForbidden();
    }

    public function test_uncertain_message_recovers_from_receipt_without_dispatching_again(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $number = \App\Models\SmsNumber::create(['phone_number' => '+12025550100', 'provider' => 'TELNYX', 'owner_type' => 'GLOBAL']);
        $contact = \App\Models\Contact::create(['name' => 'Sam', 'phone' => '+12025550112', 'type' => 'client']);
        $thread = \App\Models\MessageThread::create(['channel' => 'SMS', 'contact_id' => $contact->id]);
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'sms.send',
            'params' => ['thread_id' => $thread->id, 'sms_number_id' => $number->id, 'body' => 'Hello Sam']])->assertOk()->json();
        // Simulate a provider request already persisted when its HTTP response was lost.
        MobileAssistantAction::findOrFail($proposal['id'])->update(['state' => 'uncertain']);
        $message = \App\Models\Message::create(['thread_id' => $thread->id, 'channel' => 'SMS', 'direction' => 'OUTBOUND', 'provider' => 'TEST',
            'from_address' => $number->phone_number, 'to_address' => $contact->phone, 'body_text' => 'Hello Sam', 'status' => 'QUEUED', 'send_source' => 'MANUAL',
            'metadata' => ['assistant_action_id' => $proposal['id']]]);
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()
            ->assertJsonPath('state', 'pending')->assertJsonPath('result.spoken', 'The message is queued. Delivery is pending.');
        $message->update(['status' => 'DELIVERED']);
        $this->getJson('/api/mobile/assistant/actions/'.$proposal['id'])->assertOk()
            ->assertJsonPath('state', 'completed')->assertJsonPath('result.spoken', 'Message delivered.');
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_support_confirmation_reuses_existing_idempotent_workflow_and_search(): void
    {
        $actor = User::factory()->create(['role' => 'editor']);
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'support.create',
            'params' => ['subject' => 'Download problem', 'body' => 'My assigned download does not start.', 'category' => 'downloads']])->assertUnprocessable();
        $proposal = $this->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'support.create',
            'params' => ['subject' => 'Download problem', 'body' => 'My assigned download does not start.', 'category' => 'delivery']])->assertOk()->json();
        $this->assertDatabaseCount('support_tickets', 0);
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'completed');
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'completed');
        $this->assertDatabaseCount('support_tickets', 1);
        $this->postJson('/api/mobile/assistant/query', ['action' => 'support.read', 'query' => 'Download'])->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/mobile/assistant/query', ['action' => 'support.read', 'query' => 'Different topic'])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_note_receipt_keeps_shoot_destination_and_rechecks_note_visibility(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        Shoot::factory()->count(2)->create();
        $shoot = Shoot::factory()->create(['client_id' => $actor->id]);
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'note.create',
            'params' => ['shoot_id' => $shoot->id, 'content' => 'Staff-only instructions']])->assertOk()->json();
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()
            ->assertJsonPath('result.record_id', (string) $shoot->id);
        $actor->update(['role' => 'client']);
        $this->actingAs($actor->fresh())->getJson('/api/mobile/assistant/actions/'.$proposal['id'])->assertNotFound();
        $this->deleteJson('/api/mobile/assistant/actions/'.$proposal['id'])->assertNotFound();
    }

    public function test_editor_queue_retains_open_assigned_work_after_an_earlier_delivery(): void
    {
        $actor = User::factory()->create(['role' => 'editor']);
        $shoot = Shoot::factory()->create(['editor_id' => $actor->id, 'status' => 'delivered', 'workflow_status' => 'delivered']);
        $dispatch = \App\Models\ShootEditingDispatch::create(['request_id' => (string) Str::uuid(), 'shoot_id' => $shoot->id, 'created_by' => $actor->id,
            'scope' => 'selection', 'destination' => 'human', 'workflow' => 'retouch', 'input_hash' => str_repeat('a', 64), 'plan' => [], 'status' => 'assigned']);
        $item = $dispatch->items()->create(['input_key' => 'source:1', 'workflow' => 'retouch', 'sources' => [], 'lane' => 'photo',
            'destination' => 'human', 'editor_id' => $actor->id, 'status' => 'assigned']);
        $this->actingAs($actor)->postJson('/api/mobile/assistant/query', ['action' => 'briefing'])->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('cards.0.record_id', (string) $shoot->id);
        $item->update(['status' => 'completed']);
        $this->postJson('/api/mobile/assistant/query', ['action' => 'work.queue'])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_group_sms_freezes_live_members_and_reports_partial_delivery_without_resending(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-13T16:00:00Z'));
        $actor = User::factory()->create(['role' => 'admin']);
        $person = User::factory()->create(['role' => 'client', 'name' => 'Taylor', 'phonenumber' => '+12025550114']);
        $number = \App\Models\SmsNumber::create(['phone_number' => '+12025550100', 'provider' => 'TELNYX', 'owner_type' => 'GLOBAL']);
        $group = \App\Models\SmsGroup::create(['name' => 'Team', 'created_by' => $actor->id]);
        $group->members()->create(['name' => 'Taylor', 'user_id' => $person->id, 'phone' => '+12025550112']);
        $group->members()->create(['name' => 'Sam', 'phone' => '+12025550113']);
        $contact = \App\Models\Contact::create(['name' => 'Team', 'phone' => 'group:'.$group->id, 'type' => 'group']);
        $thread = \App\Models\MessageThread::create(['channel' => 'SMS', 'contact_id' => $contact->id, 'sms_group_id' => $group->id]);
        $body = ['request_id' => (string) Str::uuid(), 'action' => 'sms.send',
            'params' => ['thread_id' => $thread->id, 'sms_number_id' => $number->id, 'body' => 'Meet at the property.']];
        $proposal = $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', $body)->assertOk()->json();
        $this->assertStringContainsString('+12025550114', $proposal['summary']);
        $this->assertStringNotContainsString('+12025550112', $proposal['summary']);
        $person->update(['phonenumber' => '+12025550115']);
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('messages', 0);

        \App\Services\Messaging\OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $attempt = 0;
        $provider = \Mockery::mock(\App\Services\Messaging\Providers\TelnyxSmsProvider::class);
        $provider->shouldReceive('send')->twice()->andReturnUsing(function () use (&$attempt) {
            if (++$attempt === 2) {
                throw new \RuntimeException('TEST provider rejected recipient');
            }

            return 'siri-test-group-message';
        });
        $this->app->instance(\App\Services\Messaging\Providers\TelnyxSmsProvider::class, $provider);
        $body['request_id'] = (string) Str::uuid();
        $proposal = $this->postJson('/api/mobile/assistant/actions/prepare', $body)->assertOk()->json();
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()
            ->assertJsonPath('state', 'partial')->assertJsonPath('result.sent', 1)->assertJsonPath('result.failed', 1);
        $this->postJson('/api/mobile/assistant/actions/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('state', 'partial');
        $this->assertSame(2, $attempt);
    }

    public function test_schedule_proposal_cannot_bypass_the_existing_conflict_guard(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['status' => 'scheduled', 'workflow_status' => 'scheduled',
            'scheduled_date' => '2031-10-10', 'scheduled_at' => '2031-10-10 14:00:00', 'timezone' => 'America/New_York']);
        $guard = \Mockery::mock(\App\Services\Scheduling\ScheduleCommitGuard::class);
        $guard->shouldReceive('prepare')->once()->withArgs(fn ($params) => ! isset($params['force_save']) && $params['scheduled_date'] === '2031-10-11')
            ->andThrow(new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('The photographer schedule changed.'));
        $this->app->instance(\App\Services\Scheduling\ScheduleCommitGuard::class, $guard);
        $this->actingAs($actor)->postJson('/api/mobile/assistant/actions/prepare', ['request_id' => (string) Str::uuid(), 'action' => 'shoot.reschedule',
            'params' => ['shoot_id' => $shoot->id, 'scheduled_at' => '2031-10-11T15:00:00Z', 'force_save' => true]])->assertConflict();
        $this->assertDatabaseCount('mobile_assistant_actions', 0);
        $this->assertSame('2031-10-10', $shoot->fresh()->scheduled_date->toDateString());
    }

    public function test_robbie_retry_keeps_one_owned_conversation_and_conflicting_request_is_rejected(): void
    {
        config(['services.openai.api_key' => null]);
        $actor = User::factory()->create(['role' => 'client']);
        $input = ['action' => 'robbie.ask', 'prompt' => 'Help me plan a complex edit', 'request_id' => (string) Str::uuid()];
        $first = $this->actingAs($actor)->postJson('/api/mobile/assistant/query', $input)->assertOk()->json('session_id');
        $this->postJson('/api/mobile/assistant/query', $input)->assertOk()->assertJsonPath('session_id', $first);
        $this->assertDatabaseCount('ai_chat_sessions', 1);
        $this->assertDatabaseCount('ai_messages', 2);
        $input['prompt'] = 'A different question';
        $this->postJson('/api/mobile/assistant/query', $input)->assertConflict();
    }
}
