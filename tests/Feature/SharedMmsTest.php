<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\SmsNumber;
use App\Models\SmsSharedGroup;
use App\Models\SmsSharedMessage;
use App\Models\User;
use App\Services\Messaging\OutboundDeliveryGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedMmsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private SmsNumber $number;
    private array $sent = [];
    private array $settings = [];
    private array $profile = [];
    private string $recipientCountry = 'US';
    private string $recipientType = 'mobile';
    private bool $timeout = false;
    private int $sendStatus = 200;
    private bool $revokeDuringLookup = false;
    private bool $pauseDuringLookup = false;
    private const PEOPLE = ['+12025550111', '+14165550112'];
    private const LINE = '+12025550100';
    private const BASE = '/api/messaging/sms/shared-groups';

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('https://example.test'); URL::forceScheme('https');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'shared_mms.enabled' => true, 'shared_mms.lock_store' => 'array',
            'services.telnyx.api_key' => 'fake-only', 'services.telnyx.public_key' => '', 'services.telnyx.sms_paused' => false]);
        Http::preventStrayRequests();
        // Override the default disabled test double only with HTTP interception.
        $this->app->instance(\App\Services\Messaging\TelnyxSharedMmsGateway::class, new \App\Services\Messaging\TelnyxSharedMmsGateway);
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        $this->number = SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => self::LINE, 'label' => 'Test business',
            'telnyx_phone_number_id' => 'number-1', 'messaging_profile_id' => 'profile-1', 'is_default' => true]);
        config(['shared_mms.sender_ids' => [$this->number->id]]);
        $this->actor = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($this->actor, 'sanctum');
        $this->settings = ['phone_number' => self::LINE, 'country_code' => 'US', 'type' => 'long-code',
            'messaging_profile_id' => 'profile-1', 'features' => ['mms' => ['domestic_two_way' => true, 'international_outbound' => true]]];
        $this->profile = ['enabled' => true, 'webhook_api_version' => '2', 'mms_fall_back_to_sms' => false,
            'webhook_url' => 'https://example.test/api/webhooks/telnyx/messaging', 'whitelisted_destinations' => ['US', 'CA']];
        Http::fake(function ($r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/v2/phone_numbers/number-1') {
                return Http::response(['data' => ['phone_number' => self::LINE, 'status' => 'active', 'country_iso_alpha2' => 'US', 'phone_number_type' => 'local']]);
            }
            if ($path === '/v2/phone_numbers/number-1/messaging') { return Http::response(['data' => $this->settings]); }
            if ($path === '/v2/messaging_profiles/profile-1') { return Http::response(['data' => $this->profile]); }
            if (str_starts_with($path, '/v2/number_lookup/')) {
                if ($this->revokeDuringLookup) { DB::table('sms_shared_group_members')->where('user_id', $this->actor->id)->delete(); }
                if ($this->pauseDuringLookup) { config(['services.telnyx.sms_paused' => true]); }
                return Http::response(['data' => ['phone_number' => rawurldecode(basename($path)), 'country_code' => $this->recipientCountry,
                    'valid_number' => true, 'carrier' => ['type' => $this->recipientType]]]);
            }
            if ($path === '/v2/messages/group_mms') {
                $this->sent[] = $r;
                if ($this->timeout) { throw new ConnectionException('Fake response loss'); }
                return Http::response(['data' => ['id' => 'provider-'.count($this->sent), 'group_message_id' => 'group-provider-'.count($this->sent),
                    'to' => array_map(fn ($p) => ['phone_number' => $p, 'status' => 'queued'], $r['to'])]], $this->sendStatus);
            }
            throw new \LogicException('Unexpected fake provider route');
        });
    }
    private function body(array $replace = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'sms_number_id' => $this->number->id, 'participants' => self::PEOPLE,
            'body_text' => 'Lunch at noon?', 'title' => 'Project team'], $replace);
    }
    private function create(array $replace = []): array { return $this->postJson(self::BASE, $this->body($replace))->assertCreated()->json(); }

    public function test_feature_flag_and_sender_capabilities_fail_closed_without_provider_send(): void
    {
        config(['shared_mms.enabled' => false]);
        $this->getJson(self::BASE.'/capabilities')->assertOk()->assertJsonPath('available', false)->assertJsonCount(0, 'senders');
        $this->postJson(self::BASE, $this->body())->assertStatus(503);
        Http::assertNothingSent();
        config(['shared_mms.enabled' => true]);
        $this->getJson(self::BASE.'/capabilities')->assertOk()->assertJsonPath('available', true)->assertJsonPath('max_recipients', 8)->assertJsonCount(1, 'senders');
        $this->settings['features']['mms'] = null;
        $this->getJson(self::BASE.'/capabilities')->assertOk()->assertJsonPath('available', false);
        $this->assertSame([], $this->sent);
    }
    public function test_ineligible_roles_and_impersonation_are_denied(): void
    {
        foreach (['client', 'editor', 'photographer'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson(self::BASE.'/capabilities')->assertForbidden();
            $this->postJson(self::BASE, $this->body())->assertForbidden();
        }
        $this->actingAs($this->actor, 'sanctum');
        $this->withHeader('X-Impersonate-User-Id', (string) $this->actor->id)->getJson(self::BASE.'/capabilities')->assertForbidden();
        $this->assertSame([], $this->sent);
    }
    public function test_secondary_staff_role_is_honored_but_explicit_membership_still_required(): void
    {
        $staff = User::factory()->create(['role' => 'photographer', 'secondary_roles' => ['editing_manager']]);
        $result = $this->create(['member_user_ids' => [$staff->id]]);
        $this->actingAs($staff, 'sanctum');
        $this->getJson(self::BASE.'/'.$result['data']['id'])->assertOk();
        $this->postJson(self::BASE.'/'.$result['data']['id'].'/messages', ['request_key' => (string) Str::uuid(), 'body_text' => 'Shared reply'])->assertOk();
        $this->assertCount(2, $this->sent);
    }
    public function test_one_group_request_preserves_all_participants_and_never_fans_out(): void
    {
        $result = $this->create();
        $this->assertSame('shared_mms', $result['data']['kind']);
        $this->assertSame('accepted', $result['message']['status']);
        $this->assertSame(['queued', 'queued'], array_column($result['message']['recipients'], 'status'));
        $this->assertCount(1, $this->sent);
        $this->assertEqualsCanonicalizing(self::PEOPLE, $this->sent[0]['to']);
        $this->assertSame(self::LINE, $this->sent[0]['from']);
        $this->assertSame([$result['message']['id']], $this->sent[0]->header('Idempotency-Key'));
        $this->assertSame('https://example.test/api/webhooks/telnyx/shared-mms/'.$result['message']['id'], $this->sent[0]['webhook_url']);
        $this->assertDatabaseCount('messages', 0); $this->assertDatabaseCount('message_threads', 0); $this->assertDatabaseCount('sms_groups', 0);
    }
    public function test_stable_key_replays_once_and_conflicting_payload_is_rejected(): void
    {
        $body = $this->body();
        $first = $this->postJson(self::BASE, $body)->assertCreated()->json();
        $body['participants'] = array_reverse($body['participants']);
        $this->postJson(self::BASE, $body)->assertCreated()->assertJsonPath('message.id', $first['message']['id']);
        $this->postJson(self::BASE, array_replace($body, ['body_text' => 'Different']))->assertConflict();
        $this->getJson(self::BASE.'/requests/'.$body['request_key'])->assertOk()->assertJsonPath('message.id', $first['message']['id']);
        $this->assertCount(1, $this->sent); $this->assertDatabaseCount('sms_shared_groups', 1); $this->assertDatabaseCount('sms_shared_messages', 1);
    }
    public function test_private_membership_cannot_be_guessed_by_admin_or_phone_set(): void
    {
        $body = $this->body(); $first = $this->postJson(self::BASE, $body)->assertCreated()->json();
        $other = User::factory()->create(['role' => 'superadmin']); $this->actingAs($other, 'sanctum');
        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::BASE.'/'.$first['data']['id'])->assertNotFound();
        $this->getJson(self::BASE.'/requests/'.$body['request_key'])->assertNotFound();
        $this->postJson(self::BASE, $this->body())->assertNotFound();
        $this->postJson(self::BASE.'/'.$first['data']['id'].'/messages', ['request_key' => (string) Str::uuid(), 'body_text' => 'Unauthorized'])->assertNotFound();
        $this->assertCount(1, $this->sent);
    }
    public function test_immutable_canonical_group_reuses_existing_membership_without_adding_staff(): void
    {
        $a = $this->create(); $other = User::factory()->create(['role' => 'admin']);
        $this->postJson(self::BASE, $this->body(['member_user_ids' => [$other->id]]))->assertConflict();
        $b = $this->create(['participants' => array_reverse(self::PEOPLE), 'title' => 'New title']);
        $this->assertSame($a['data']['id'], $b['data']['id']);
        $this->assertSame([$this->actor->id], $b['data']['member_user_ids']);
        $this->assertSame('Project team', $b['data']['title']);
        $this->assertDatabaseCount('sms_shared_groups', 1);
    }
    public function test_uncertain_timeout_is_reconciled_by_signed_webhook_without_second_send(): void
    {
        $this->timeout = true; $body = $this->body();
        $first = $this->postJson(self::BASE, $body)->assertAccepted()->assertJsonPath('message.status', 'uncertain')->json();
        $this->postJson(self::BASE, $body)->assertAccepted();
        $this->getJson(self::BASE.'/requests/'.$body['request_key'])->assertOk()->assertJsonPath('message.status', 'uncertain');
        $event = $this->delivery('status-1', 'unknown', self::PEOPLE[0]);
        $this->signedWebhook('/api/webhooks/telnyx/shared-mms/'.$first['message']['id'], $event)->assertOk();
        $result = $this->getJson(self::BASE.'/requests/'.$body['request_key'])->assertOk()->assertJsonPath('message.status', 'accepted')->json('message');
        $this->assertContains('unknown', array_column($result['recipients'], 'status'));
        $this->assertNotContains('delivered', array_column($result['recipients'], 'status'));
        $this->assertCount(1, $this->sent);
    }
    public function test_partial_duplicate_and_out_of_order_delivery_does_not_invent_all_delivered(): void
    {
        $result = $this->create(); $url = '/api/webhooks/telnyx/shared-mms/'.$result['message']['id'];
        $this->postJson($url, $this->delivery('first', 'delivered', self::PEOPLE[0], '2026-10-29T15:00:00Z'))->assertOk();
        $this->postJson($url, $this->delivery('second', 'unknown', self::PEOPLE[1], '2026-10-29T15:01:00Z'))->assertOk();
        $this->postJson($url, $this->delivery('first', 'failed', self::PEOPLE[0], '2026-10-29T15:02:00Z'))->assertJsonPath('status', 'duplicate');
        $this->postJson($url, $this->delivery('older', 'queued', self::PEOPLE[0], '2026-10-29T14:00:00Z'))->assertOk();
        $m = SmsSharedMessage::findOrFail($result['message']['id']);
        $this->assertSame(['delivered', 'unknown'], array_column($m->recipient_statuses, 'status'));
        $this->assertSame('accepted', $m->status);
    }
    public function test_wrong_business_line_or_nonparticipant_delivery_cannot_rebind_request(): void
    {
        $result = $this->create(); $url = '/api/webhooks/telnyx/shared-mms/'.$result['message']['id'];
        $event = $this->delivery('bad-line', 'delivered', self::PEOPLE[0]); $event['data']['payload']['from']['phone_number'] = '+12025550999';
        $this->postJson($url, $event)->assertJsonPath('status', 'ignored');
        $this->postJson($url, $this->delivery('stranger', 'delivered', '+12025550998'))->assertJsonPath('status', 'ignored');
        $this->assertSame(['queued', 'queued'], array_column(SmsSharedMessage::findOrFail($result['message']['id'])->recipient_statuses, 'status'));
    }
    public function test_shared_inbound_cc_is_correlated_without_individual_thread_or_ai_reply(): void
    {
        $result = $this->create(); $event = $this->incoming('reply-1', 'Yes, noon works');
        $this->postJson('/api/webhooks/telnyx/messaging', $event)->assertOk()->assertJsonPath('status', 'received');
        $this->postJson('/api/webhooks/telnyx/messaging', $event)->assertOk()->assertJsonPath('status', 'duplicate');
        $reply = SmsSharedMessage::where('direction', 'INBOUND')->sole();
        $this->assertSame($result['data']['id'], $reply->group_id); $this->assertSame(self::PEOPLE[0], $reply->from_number);
        $this->assertDatabaseCount('messages', 0); $this->assertDatabaseCount('message_threads', 0); $this->assertCount(1, $this->sent);
        $event = $this->incoming('wrong-participants', 'Separate group'); $event['data']['payload']['cc'][] = '+12025550199';
        $this->postJson('/api/webhooks/telnyx/messaging', $event)->assertJsonPath('status', 'unmatched_group');
        $this->assertDatabaseCount('sms_shared_messages', 2);
    }
    public function test_stop_suppresses_entire_shared_send_without_fanout_or_automatic_reply(): void
    {
        $result = $this->create();
        $this->postJson('/api/webhooks/telnyx/messaging', $this->incoming('stop-1', 'STOP'))->assertOk();
        $this->postJson(self::BASE.'/'.$result['data']['id'].'/messages', ['request_key' => (string) Str::uuid(), 'body_text' => 'Should not send'])->assertUnprocessable()->assertJsonValidationErrors('participants');
        $this->assertCount(1, $this->sent);
    }
    public function test_unsupported_recipients_and_sender_configuration_are_rejected_before_post(): void
    {
        foreach ([['+12025550111'], [self::LINE, ...self::PEOPLE], [self::PEOPLE[0], self::PEOPLE[0]], ['+442055501111', self::PEOPLE[0]]] as $people) {
            $this->postJson(self::BASE, $this->body(['participants' => $people]))->assertUnprocessable();
        }
        $this->recipientCountry = 'JM'; $this->postJson(self::BASE, $this->body())->assertUnprocessable();
        $this->recipientCountry = 'US'; $this->recipientType = 'fixed line'; $this->postJson(self::BASE, $this->body())->assertUnprocessable();
        $this->recipientType = 'mobile'; $this->profile['webhook_api_version'] = '1'; $this->postJson(self::BASE, $this->body())->assertUnprocessable();
        $this->profile['webhook_api_version'] = '2'; $this->profile['mms_fall_back_to_sms'] = true; $this->postJson(self::BASE, $this->body())->assertUnprocessable();
        $this->profile['mms_fall_back_to_sms'] = false; $this->settings['type'] = 'toll-free'; $this->postJson(self::BASE, $this->body())->assertUnprocessable();
        $this->assertSame([], $this->sent); $this->assertDatabaseCount('sms_shared_groups', 0);
    }
    public function test_direct_provider_failure_is_visible_and_never_retried(): void
    {
        $this->sendStatus = 422; $body = $this->body();
        $this->postJson(self::BASE, $body)->assertCreated()->assertJsonPath('message.status', 'failed');
        $this->postJson(self::BASE, $body)->assertCreated()->assertJsonPath('message.status', 'failed');
        $this->assertCount(1, $this->sent);
    }
    public function test_membership_and_account_loss_deny_read_send_and_request_reconciliation(): void
    {
        $body = $this->body(); $created = $this->postJson(self::BASE, $body)->assertCreated()->json();
        DB::table('sms_shared_group_members')->where('user_id', $this->actor->id)->delete();
        $this->getJson(self::BASE.'/'.$created['data']['id'])->assertNotFound();
        $this->getJson(self::BASE.'/requests/'.$body['request_key'])->assertNotFound();
        $this->postJson(self::BASE, $body)->assertNotFound();
        $this->assertCount(1, $this->sent);
    }
    public function test_pagination_is_bounded_and_reads_have_no_receipt_side_effect(): void
    {
        $created = $this->create();
        for ($i = 0; $i < 2; $i++) { $this->postJson(self::BASE.'/'.$created['data']['id'].'/messages', ['request_key' => (string) Str::uuid(), 'body_text' => 'Reply '.$i])->assertOk(); }
        $this->getJson(self::BASE.'/'.$created['data']['id'].'?per_page=2')->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('total', 3)->assertJsonPath('last_page', 2);
        $this->getJson(self::BASE.'/'.$created['data']['id'].'?per_page=2&page=2')->assertJsonCount(1, 'messages');
        $this->getJson(self::BASE.'?per_page=51')->assertUnprocessable(); $this->assertCount(3, $this->sent);
    }
    public function test_membership_revocation_during_provider_eligibility_reads_prevents_send(): void
    {
        $created = $this->create(); $this->revokeDuringLookup = true;
        $this->postJson(self::BASE.'/'.$created['data']['id'].'/messages', ['request_key' => (string) Str::uuid(), 'body_text' => 'No longer authorized'])->assertNotFound();
        $this->assertCount(1, $this->sent); $this->assertDatabaseCount('sms_shared_messages', 1);
    }
    public function test_existing_formatted_contact_optout_and_operations_pause_block_before_provider_send(): void
    {
        \App\Models\Contact::create(['name' => 'Opted out', 'phone' => '(202) 555-0111', 'sms_opt_out' => true, 'type' => 'other']);
        $this->postJson(self::BASE, $this->body())->assertUnprocessable();
        config(['services.telnyx.sms_paused' => true]);
        $this->postJson(self::BASE, $this->body())->assertStatus(503);
        $this->assertCount(0, $this->sent); Http::assertNothingSent();
    }
    public function test_operations_pause_during_provider_verification_prevents_reservation_and_send(): void
    {
        $this->pauseDuringLookup = true;
        $this->postJson(self::BASE, $this->body())->assertStatus(503);
        $this->assertSame([], $this->sent); $this->assertDatabaseCount('sms_shared_groups', 0); $this->assertDatabaseCount('sms_shared_messages', 0);
    }
    public function test_unsigned_webhook_is_rejected_when_verification_key_is_configured(): void
    {
        $keys = sodium_crypto_sign_keypair(); config(['services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keys))]);
        $this->postJson('/api/webhooks/telnyx/messaging', $this->incoming('unsigned', 'No'))->assertForbidden();
        $this->assertDatabaseCount('sms_shared_messages', 0);
    }
    public function test_shared_unread_counts_cursor_and_badge_never_clear_new_or_rewind_on_stale_read(): void
    {
        $result = $this->create(); $group = $result['data']['id'];
        $this->postJson('/api/webhooks/telnyx/messaging', $this->incoming('first-unread', 'First'))->assertOk();
        $loaded = $this->getJson(self::BASE.'/'.$group)->assertOk()->assertJsonPath('data.unread_count', 1)->json();
        $cursor = $loaded['read_cursor_message_id'];
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('sms', 1);
        $this->postJson('/api/webhooks/telnyx/messaging', $this->incoming('second-unread', 'Arrived after load'))->assertOk();
        $this->postJson(self::BASE.'/'.$group.'/read', ['last_message_id' => $cursor])->assertOk()->assertJsonPath('unread_count', 1);
        $loaded = $this->getJson(self::BASE.'/'.$group)->assertJsonPath('data.unread_count', 1)->json();
        $newCursor = $loaded['read_cursor_message_id'];
        $this->assertNotSame($cursor, $newCursor);
        $this->postJson(self::BASE.'/'.$group.'/read', ['last_message_id' => $newCursor])->assertJsonPath('unread_count', 0);
        $this->postJson(self::BASE.'/'.$group.'/read', ['last_message_id' => $cursor])->assertJsonPath('unread_count', 0);
        $this->postJson(self::BASE.'/'.$group.'/read', ['last_message_id' => $newCursor])->assertJsonPath('unread_count', 0);
        $this->getJson('/api/messaging/badge-counts')->assertJsonPath('sms', 0);
        $this->assertSame($newCursor, DB::table('sms_shared_group_members')->where('group_id', $group)->value('last_read_message_id'));
    }
    public function test_group_read_is_per_member_and_denies_foreign_invalid_or_nonmember_markers(): void
    {
        $member = User::factory()->create(['role' => 'admin']);
        $result = $this->create(['member_user_ids' => [$member->id]]); $group = $result['data']['id'];
        $this->postJson('/api/webhooks/telnyx/messaging', $this->incoming('private-read', 'Message'))->assertOk();
        $marker = $this->getJson(self::BASE.'/'.$group)->json('read_cursor_message_id');
        $this->postJson(self::BASE.'/'.$group.'/read', ['last_message_id' => (string) Str::uuid()])->assertNotFound();
        $other = $this->create(['participants' => ['+12025550121', '+12025550122']]);
        $this->postJson(self::BASE.'/'.$group.'/read', ['last_message_id' => $other['message']['id']])->assertNotFound();
        $this->postJson(self::BASE.'/'.$group.'/read', ['last_message_id' => $marker])->assertJsonPath('unread_count', 0);
        $this->actingAs($member, 'sanctum');
        $this->getJson(self::BASE.'/'.$group)->assertJsonPath('data.unread_count', 1);
        $this->getJson('/api/messaging/badge-counts')->assertJsonPath('sms', 1);
        $this->actingAs(User::factory()->create(['role' => 'superadmin']), 'sanctum');
        $this->postJson(self::BASE.'/'.$group.'/read', ['last_message_id' => $marker])->assertNotFound();
        $this->getJson('/api/messaging/badge-counts')->assertJsonPath('sms', 0);
    }
    public function test_eight_participants_are_one_mms_but_nine_are_rejected(): void
    {
        $people = array_map(fn ($n) => '+120255501'.sprintf('%02d', $n), range(11, 18));
        $this->create(['participants' => $people]);
        $this->assertCount(8, $this->sent[0]['to']);
        $this->postJson(self::BASE, $this->body(['participants' => [...$people, '+12025550119']]))->assertUnprocessable();
        $this->assertCount(1, $this->sent);
    }
    private function delivery(string $event, string $status, string $phone, string $at = '2026-10-29T15:00:00Z'): array
    {
        return ['data' => ['id' => $event, 'event_type' => 'message.finalized', 'occurred_at' => $at,
            'payload' => ['id' => 'individual-'.$event, 'group_message_id' => 'group-provider-1', 'from' => ['phone_number' => self::LINE],
                'to' => [['phone_number' => $phone, 'status' => $status]], 'type' => 'MMS']]];
    }
    private function incoming(string $id, string $text): array
    {
        return ['data' => ['id' => 'event-'.$id, 'event_type' => 'message.received', 'occurred_at' => now()->toIso8601String(),
            'payload' => ['id' => $id, 'from' => ['phone_number' => self::PEOPLE[0]], 'to' => [['phone_number' => self::LINE]],
                'cc' => [self::PEOPLE[1], self::LINE], 'text' => $text, 'type' => 'MMS', 'media' => []]]];
    }
    private function signedWebhook(string $uri, array $payload)
    {
        $keys = sodium_crypto_sign_keypair(); $timestamp = (string) time();
        config(['services.telnyx.public_key' => base64_encode(sodium_crypto_sign_publickey($keys))]);
        $body = json_encode($payload);
        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_TELNYX_TIMESTAMP' => $timestamp, 'HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, sodium_crypto_sign_secretkey($keys)))], $body);
    }
}
