<?php

namespace Tests\Feature;

use App\Models\AccountLink;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\Shoot;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\ReproAi\ShootOperatorService;
use App\Services\ReproAi\ToolDispatcher;
use App\Services\ReproAi\Tools\ListingTools;
use App\Services\ReproAi\Tools\PropertyTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobbiePrivateRecordAccessTest extends TestCase
{
    use RefreshDatabase;

    private function tool(string $name, array $params, array $context = []): array
    {
        return app(ToolDispatcher::class)->dispatch($name, $params, $context);
    }

    public function test_no_actor_or_bare_forged_context_cannot_read_or_change_a_record(): void
    {
        $shoot = Shoot::factory()->create(['shoot_notes' => 'Client notes']);
        $forged = ['user_id' => $shoot->client_id, 'user_role' => 'superadmin', 'verified' => true];
        foreach ([
            ['get_listing', ['listing_id' => $shoot->id]],
            ['get_property', ['property_id' => $shoot->id]],
            ['get_shoot_details', ['shoot_id' => $shoot->id]],
            ['list_shoots', ['user_id' => $shoot->client_id]],
            ['get_portfolio_overview', ['user_id' => $shoot->client_id]],
            ['update_listing_copy', ['listing_id' => $shoot->id, 'description' => 'Forbidden']],
            ['cancel_shoot', ['shoot_id' => $shoot->id]],
        ] as [$tool, $params]) {
            $this->assertFalse($this->tool($tool, $params, $forged)['success'], $tool);
        }
        $this->assertSame('Client notes', $shoot->fresh()->shoot_notes);
        $this->assertSame('completed', $shoot->fresh()->status);
    }

    public function test_client_cannot_read_or_mutate_another_clients_id_or_address(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other = Shoot::factory()->create(['address' => '456 Private Lane', 'shoot_notes' => 'Private details']);
        $this->actingAs($client);
        foreach ([
            ['get_listing', ['listing_id' => $other->id]],
            ['get_listing', ['address' => '456 Private Lane']],
            ['get_property', ['property_id' => $other->id]],
            ['get_property', ['address' => '456 Private Lane']],
            ['get_shoot_details', ['shoot_id' => $other->id]],
            ['update_listing_copy', ['listing_id' => $other->id, 'description' => 'Forbidden']],
            ['update_listing_copy', ['address' => '456 Private Lane', 'description' => 'Forbidden']],
            ['reschedule_shoot', ['shoot_id' => $other->id, 'new_time' => '16:00']],
            ['cancel_shoot', ['shoot_id' => $other->id]],
        ] as [$tool, $params]) {
            $result = $this->tool($tool, $params, ['user_id' => $other->client_id, 'user_role' => 'superadmin']);
            $this->assertFalse($result['success'], $tool);
            $this->assertStringNotContainsString('Private details', json_encode($result));
        }
        $this->assertSame('Private details', $other->fresh()->shoot_notes);
        $this->assertSame($other->time, $other->fresh()->time);
        $this->assertSame('completed', $other->fresh()->status);
    }

    public function test_client_own_reads_and_changes_work_without_leaking_staff_notes(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $own = Shoot::factory()->create(['client_id' => $client->id, 'notes' => 'Staff-only note', 'shoot_notes' => 'Client-visible note']);
        $this->actingAs($client);
        $this->assertSame('Client-visible note', $this->tool('get_listing', ['listing_id' => $own->id])['listing']['description']);
        $this->assertSame($own->id, $this->tool('get_property', ['property_id' => $own->id])['property']['id']);
        $detail = $this->tool('get_shoot_details', ['shoot_id' => $own->id]);
        $this->assertSame('Client-visible note', $detail['shoot']['notes']);
        $this->assertNotNull($detail['shoot']['pricing']);
        $this->assertTrue($this->tool('update_listing_copy', ['listing_id' => $own->id, 'description' => 'Updated client note'])['success']);
        $this->assertSame('Staff-only note', $own->fresh()->notes);
        $this->assertSame('Updated client note', $own->fresh()->shoot_notes);
        $this->assertTrue($this->tool('reschedule_shoot', ['shoot_id' => $own->id, 'new_time' => '16:00'])['success']);
        $this->assertTrue($this->tool('cancel_shoot', ['shoot_id' => $own->id])['success']);
    }

    public function test_scoped_lists_cannot_override_authenticated_identity_with_user_params(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $own = Shoot::factory()->create(['client_id' => $client->id]);
        $other = Shoot::factory()->create();
        $this->actingAs($client);
        foreach (['list_shoots', 'get_portfolio_overview'] as $tool) {
            $this->assertFalse($this->tool($tool, ['user_id' => $other->client_id], ['user_id' => $other->client_id])['success']);
        }
        $this->assertSame([$own->id], array_column($this->tool('list_shoots', [])['shoots'], 'id'));
        $this->assertSame(1, $this->tool('get_portfolio_overview', [])['portfolio']['total_shoots']);
    }

    public function test_staff_reads_and_authorized_mutations_remain_available(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shoot = Shoot::factory()->create(['notes' => 'Internal']);
        $this->actingAs($admin);
        $this->assertSame('Internal', $this->tool('get_listing', ['listing_id' => $shoot->id])['listing']['description']);
        $this->assertSame($shoot->id, $this->tool('get_property', ['address' => $shoot->address])['property']['id']);
        $this->assertTrue($this->tool('get_shoot_details', ['shoot_id' => $shoot->id])['success']);
        $this->assertTrue($this->tool('update_listing_copy', ['listing_id' => $shoot->id, 'description' => 'Admin edit'])['success']);
        $this->assertSame('Admin edit', $shoot->fresh()->notes);
        $this->assertTrue($this->tool('get_portfolio_overview', ['user_id' => $shoot->client_id])['success']);
        $this->assertTrue($this->tool('cancel_shoot', ['shoot_id' => $shoot->id])['success']);
    }

    public function test_shared_shoot_read_access_does_not_grant_billing_or_writes(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['shoot_notes' => 'Shared shoot note']);
        AccountLink::create(['main_account_id' => $viewer->id, 'linked_account_id' => $shoot->client_id, 'shared_details' => ['shoots' => true], 'status' => 'active', 'created_by' => $viewer->id]);
        $this->actingAs($viewer);
        $result = $this->tool('get_shoot_details', ['shoot_id' => $shoot->id]);
        $this->assertTrue($result['success']);
        $this->assertNull($result['shoot']['pricing']);
        $this->assertNull($result['shoot']['client']);
        $this->assertTrue($this->tool('get_listing', ['listing_id' => $shoot->id])['success']);
        foreach ([['cancel_shoot', []], ['reschedule_shoot', ['new_time' => '16:00']], ['update_listing_copy', ['description' => 'Forbidden', 'listing_id' => $shoot->id]]] as [$tool, $params]) {
            $this->assertFalse($this->tool($tool, ['shoot_id' => $shoot->id, ...$params])['success']);
        }
        $this->assertSame('Shared shoot note', $shoot->fresh()->shoot_notes);
    }

    public function test_voice_uses_stored_verified_caller_not_claimed_user_or_verified_flag(): void
    {
        $shoot = Shoot::factory()->create();
        $call = VoiceCall::create(['provider' => 'telnyx', 'direction' => 'INBOUND', 'status' => 'in_progress', 'caller_user_id' => $shoot->client_id]);
        $context = ['channel' => 'VOICE', 'voice_call_id' => $call->id, 'user_id' => $shoot->client_id, 'verified' => true];
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], $context)['success']);
        $call->forceFill(['verified_at' => now()])->save();
        $this->assertTrue($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], [...$context, 'user_id' => 999999])['success']);
        $call->forceFill(['ended_at' => now()])->save();
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], $context)['success']);
        $call->forceFill(['ended_at' => null, 'status' => 'busy'])->save();
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], $context)['success']);
        $call->forceFill(['status' => 'in_progress'])->save();
        $caller = User::findOrFail($shoot->client_id);
        $caller->forceFill(['locked_at' => now()])->save();
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], $context)['success']);
        $caller->forceFill(['locked_at' => null])->save();
        $call->forceFill(['caller_user_id' => null])->save();
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], $context)['success']);
    }

    public function test_sms_uses_stored_thread_sender_and_not_model_account_claims(): void
    {
        $client = User::factory()->create(['role' => 'client', 'phone' => '+12025550123', 'phonenumber' => '+12025550123']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $contact = Contact::create(['name' => 'SMS client', 'phone' => '+12025550123', 'user_id' => $client->id]);
        $thread = MessageThread::create(['contact_id' => $contact->id, 'channel' => 'SMS', 'status' => 'open']);
        $inbound = Message::create(['thread_id' => $thread->id, 'channel' => 'SMS', 'direction' => 'INBOUND', 'from_address' => '+12025550123', 'to_address' => '+12025550124', 'body_text' => 'My shoot', 'status' => 'received']);
        // A later message from a different sender cannot change the original actor.
        Message::create(['thread_id' => $thread->id, 'channel' => 'SMS', 'direction' => 'INBOUND', 'from_address' => '+12025550999', 'to_address' => '+12025550124', 'body_text' => 'Later message', 'status' => 'received']);
        $context = ['channel' => 'SMS', 'sms_thread_id' => $thread->id, 'sms_message_id' => $inbound->id, 'user_id' => 999999, 'phone_e164' => '+19999999999'];
        $this->assertTrue($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], $context)['success']);
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], [...$context, 'sms_thread_id' => 999999])['success']);
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], [...$context, 'sms_message_id' => null])['success']);
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], ['channel' => 'SMS', 'user_id' => $client->id, 'verified' => true])['success']);
        $client->forceFill(['account_status' => 'inactive'])->save();
        $this->assertFalse($this->tool('get_shoot_details', ['shoot_id' => $shoot->id], $context)['success']);
    }

    private function resolve(string $message, User $user, array $context = []): ?Shoot
    {
        $method = new \ReflectionMethod(ShootOperatorService::class, 'resolveShoot');

        return $method->invoke(app(ShootOperatorService::class), $message, $context, $user)['shoot'];
    }

    public function test_other_record_helpers_preserve_the_same_account_boundary(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $own = Shoot::factory()->create(['client_id' => $client->id, 'hero_image' => null]);
        $other = Shoot::factory()->create(['hero_image' => null]);
        $properties = app(PropertyTools::class);
        $listings = app(ListingTools::class);
        $this->assertFalse($properties->getPropertyInsights(['property_id' => $own->id], ['user_id' => $client->id])['success']);
        $this->assertFalse($listings->getListingsNeedingMedia([], ['user_id' => $client->id])['success']);
        $this->actingAs($client);
        $this->assertTrue($properties->getPropertyInsights(['property_id' => $own->id])['success']);
        $this->assertFalse($properties->getPropertyInsights(['property_id' => $other->id])['success']);
        $this->assertSame([$own->id], array_column($listings->getListingsNeedingMedia(['user_id' => $other->client_id])['listings'], 'id'));
    }

    public function test_street_number_is_not_treated_as_a_shoot_id_even_with_stale_page_context(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $wrong = Shoot::factory()->create(['id' => 123, 'client_id' => $client->id, 'address' => '900 Wrong Street']);
        $right = Shoot::factory()->create(['client_id' => $client->id, 'address' => '123 Main Street']);
        $this->actingAs($client);
        $query = 'What is the delivery status of my shoot at 123 Main?';
        $this->assertSame($right->id, $this->resolve($query, $client)?->id);
        $this->assertSame($right->id, $this->resolve($query, $client, ['entityId' => $wrong->id, 'entityType' => 'shoot'])?->id);
        $this->assertSame($right->id, $this->resolve($query, $client, ['address' => $wrong->address, 'entityId' => $wrong->id])?->id);
        $this->assertSame($wrong->id, $this->resolve('Show shoot #123', $client)?->id);
        $this->assertSame($wrong->id, $this->resolve('123', $client)?->id);
    }

    public function test_missing_or_unauthorized_explicit_targets_never_fall_back_to_another_shoot(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $own = Shoot::factory()->create(['client_id' => $client->id]);
        $other = Shoot::factory()->create(['address' => '456 Other Street']);
        $this->actingAs($client);
        $context = ['entityType' => 'shoot', 'entityId' => $own->id];
        $this->assertNull($this->resolve('Show shoot #'.$other->id, $client, $context));
        $this->assertNull($this->resolve('Show shoot #999999', $client, $context));
        $this->assertNull($this->resolve('Show the shoot at 456 Other Street', $client, $context));
    }
}
