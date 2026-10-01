<?php

namespace Tests\Feature;

use App\Events\EmailMessageReceived;
use App\Events\EmailMessageSent;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Messaging\DashboardMessagingPolicy;
use App\Services\Messaging\MessagingService;
use App\Services\SupportLegacyMessageImporter;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportDashboardRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Event::fake([EmailMessageReceived::class, EmailMessageSent::class]);
    }

    private function legacy(User $owner, array $overrides = []): Message
    {
        return Message::create([...[
            'channel' => 'EMAIL', 'provider' => 'INTERNAL', 'direction' => 'INBOUND', 'send_source' => 'MANUAL', 'status' => 'SENT',
            'from_address' => $owner->email, 'to_address' => 'contact@example.test', 'subject' => 'Legacy question',
            'body_text' => 'Original private dashboard message', 'body_html' => '<p>Original private dashboard message</p>',
            'sender_user_id' => $owner->id, 'created_by' => $owner->id, 'sender_role' => $owner->role,
        ], ...$overrides]);
    }

    public function test_all_nonstaff_submissions_reach_support_without_shoot_recipient_or_email_side_effects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $outsider = User::factory()->create(['role' => 'salesRep']);
        foreach (['client', 'salesRep', 'photographer', 'editor'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'secondary_roles' => ['admin']]);
            $payload = ['request_key' => (string) Str::uuid(), 'subject' => 'Please help me', 'body_text' => 'Private help for '.$role,
                'to' => 'outside@example.test', 'related_shoot_id' => 999999, 'related_account_id' => $admin->id, 'sender_user_id' => $admin->id];
            $response = $this->actingAs($actor, 'sanctum')->postJson('/api/messaging/email/compose', $payload)->assertCreated();
            $id = $response->json('support_ticket_id');
            $response->assertJsonPath('data.requester.id', $actor->id)->assertJsonPath('redirect_url', '/messaging/email/inbox?tab=support&ticket='.$id);
            $this->postJson('/api/messaging/email/compose', $payload)->assertCreated()->assertJsonPath('support_ticket_id', $id);
            $this->actingAs($outsider, 'sanctum')->getJson('/api/support/tickets/'.$id)->assertNotFound();
            $this->actingAs($admin, 'sanctum')->getJson('/api/support/tickets/'.$id)->assertOk()->assertJsonPath('messages.0.body', 'Private help for '.$role);
            $this->actingAs($manager, 'sanctum')->getJson('/api/support/tickets/'.$id)->assertOk()->assertJsonPath('data.can_manage', true);
        }
        $this->assertDatabaseCount('support_tickets', 4);
        $this->assertDatabaseCount('support_ticket_messages', 4);
        $this->assertDatabaseCount('messages', 0);
        $this->assertCount(4, app(SupportTicketService::class)->notifications($manager));
        $this->assertCount(0, app(SupportTicketService::class)->notifications($outsider));
        Queue::assertNothingPushed();
        Event::assertNotDispatched(EmailMessageReceived::class);
        Event::assertNotDispatched(EmailMessageSent::class);
    }

    public function test_nonstaff_cannot_bypass_email_routes_even_with_secondary_admin_or_explicit_email_grants(): void
    {
        $actor = User::factory()->create(['role' => 'photographer', 'secondary_roles' => ['admin'],
            'permission_overrides' => ['allow' => ['messaging-email-view', 'messaging-compose-create'], 'deny' => []]]);
        $message = $this->legacy($actor);
        $this->actingAs($actor, 'sanctum');
        foreach (['messages', 'threads', 'recipients', 'messages/'.$message->id] as $path) {
            $this->getJson('/api/messaging/email/'.$path)->assertForbidden();
        }
        foreach (['schedule', 'assist', 'messages/'.$message->id.'/retry', 'messages/'.$message->id.'/cancel'] as $path) {
            $this->postJson('/api/messaging/email/'.$path, [])->assertForbidden();
        }
        foreach (['overview', 'templates', 'settings/email', 'email/ops-summary', 'email/recovery/client-confirmations', 'automations'] as $path) {
            $this->getJson('/api/messaging/'.$path)->assertForbidden();
        }
        $this->postJson('/api/messaging/templates/preview', [])->assertForbidden();
        $this->getJson('/api/messaging/badge-counts')->assertOk()->assertJsonPath('email', 0);
        $actor->update(['permission_overrides' => ['allow' => [], 'deny' => ['support-view']]]);
        $this->actingAs($actor->fresh(), 'sanctum')->postJson('/api/messaging/email/compose', ['body_text' => 'No support permission'])->assertForbidden();
    }

    public function test_editing_manager_can_use_email_and_triage_but_permission_denials_still_apply(): void
    {
        $manager = User::factory()->create(['role' => 'editing_manager']);
        $sent = new Message(['id' => 900, 'channel' => 'EMAIL', 'status' => 'SENT']);
        $this->mock(MessagingService::class)->shouldReceive('sendEmail')->once()->withArgs(fn ($data) => $data['to'] === 'recipient@example.test' && $data['sender_user_id'] === $manager->id)->andReturn($sent);
        $this->actingAs($manager, 'sanctum')->postJson('/api/messaging/email/compose', ['to' => 'recipient@example.test', 'subject' => 'Staff email', 'body_text' => 'An explicit staff email'])->assertOk();
        $this->getJson('/api/support/tickets/assignees')->assertOk()->assertJsonFragment(['id' => $manager->id, 'name' => $manager->name]);
        $manager->update(['permission_overrides' => ['allow' => [], 'deny' => ['messaging-compose-create', 'support-manage']]]);
        $this->actingAs($manager->fresh(), 'sanctum')->postJson('/api/messaging/email/compose', ['to' => 'recipient@example.test', 'body_text' => 'Denied'])->assertForbidden();
        $this->getJson('/api/support/tickets/assignees')->assertForbidden();
        $this->assertFalse(app(DashboardMessagingPolicy::class)->canEmail(User::factory()->create(['role' => 'admin', 'account_status' => 'inactive'])));
    }

    public function test_import_preserves_no_shoot_inactive_requester_original_body_files_and_only_explicit_owned_replies(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('messaging-attachments/proof.txt', 'Original attachment');
        $owner = User::factory()->create(['role' => 'photographer', 'account_status' => 'inactive']);
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'client']);
        $source = $this->legacy($owner, ['attachments_json' => [['disk' => 'local', 'storage_path' => 'messaging-attachments/proof.txt', 'name' => 'proof.txt', 'size' => 19]], 'created_at' => now()->subDays(3)]);
        $source->forceFill(['created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)])->save();
        $before = $source->fresh()->getRawOriginal();
        $reply = $this->legacy($admin, ['direction' => 'OUTBOUND', 'to_address' => $owner->email, 'body_text' => 'A staff answer', 'metadata' => ['internal_reply_to_message_id' => $source->id]]);
        $wrongRecipient = $this->legacy($admin, ['direction' => 'OUTBOUND', 'to_address' => $other->email, 'metadata' => ['internal_reply_to_message_id' => $source->id]]);
        $unrelated = $this->legacy($admin, ['direction' => 'OUTBOUND', 'to_address' => $owner->email]);
        $forged = $this->legacy($other, ['metadata' => ['internal_reply_to_message_id' => $source->id]]);
        $this->artisan('support:import-dashboard-messages')->assertSuccessful();
        $this->assertDatabaseCount('support_tickets', 0);
        $this->artisan('support:import-dashboard-messages', ['--apply' => true])->assertSuccessful();
        $this->artisan('support:import-dashboard-messages', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_ticket_messages', 2);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertDatabaseCount('messages', 5);
        $ticket = SupportTicket::firstOrFail();
        $this->assertSame($owner->id, $ticket->requester_id);
        $owner->update(['account_status' => 'active']);
        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/support/tickets/'.$ticket->id)->assertOk()->assertJsonCount(2, 'messages');
        $download = $response->json('messages.0.attachments.0.download_url');
        $this->get($download)->assertOk()->assertDownload('proof.txt')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/support/tickets/legacy-message/'.$reply->id)->assertOk()->assertJsonPath('support_ticket_id', $ticket->id);
        foreach ([$wrongRecipient, $unrelated, $forged] as $notImported) {
            $this->getJson('/api/support/tickets/legacy-message/'.$notImported->id)->assertNotFound();
        }
        $this->actingAs($other, 'sanctum')->get($download)->assertNotFound();
        $this->actingAs($admin, 'sanctum')->getJson('/api/messaging/email/messages')->assertOk()->assertJsonMissing(['id' => $source->id]);
        $this->getJson('/api/messaging/email/messages/'.$source->id)->assertNotFound();
        $this->assertFalse((new EmailMessageReceived($source))->broadcastWhen());
        $this->assertFalse((new EmailMessageSent($reply))->broadcastWhen());
        Queue::assertNothingPushed();
    }

    public function test_legacy_reply_is_bound_to_requester_not_shoot_client_and_denies_assigned_rep(): void
    {
        $owner = User::factory()->create(['role' => 'photographer']);
        $client = User::factory()->create(['role' => 'client']);
        $rep = User::factory()->create(['role' => 'salesRep', 'secondary_roles' => ['admin']]);
        $staff = User::factory()->create(['role' => 'editing_manager']);
        $shoot = \App\Models\Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $rep->id, 'photographer_id' => $owner->id]);
        $source = $this->legacy($owner, ['related_shoot_id' => $shoot->id, 'related_account_id' => $client->id]);
        $payload = ['request_key' => (string) Str::uuid(), 'body_text' => 'Answer for the photographer', 'in_reply_to_message_id' => $source->id];
        $this->actingAs($rep, 'sanctum')->postJson('/api/messaging/email/compose', $payload)->assertNotFound();
        $this->assertDatabaseCount('support_tickets', 0);
        $response = $this->actingAs($staff, 'sanctum')->postJson('/api/messaging/email/compose', $payload)->assertOk();
        $id = $response->json('support_ticket_id');
        $this->postJson('/api/messaging/email/compose', $payload)->assertOk();
        $this->assertDatabaseCount('support_ticket_messages', 2);
        $this->actingAs($owner, 'sanctum')->getJson('/api/support/tickets/'.$id)->assertOk()->assertJsonPath('messages.1.body', $payload['body_text']);
        $this->actingAs($client, 'sanctum')->getJson('/api/support/tickets/'.$id)->assertNotFound();
        $this->assertDatabaseCount('messages', 1);
        Queue::assertNothingPushed();
    }

    public function test_native_attachment_survives_save_retries_downloads_and_private_note_boundaries(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'editor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $key = (string) Str::uuid();
        $data = ['request_key' => $key, 'subject' => 'Upload trouble', 'body' => 'The file upload reports an error.', 'category' => 'uploads'];
        $upload = fn () => UploadedFile::fake()->createWithContent('error.txt', 'Actual evidence');
        $response = $this->actingAs($owner, 'sanctum')->post('/api/support/tickets', [...$data, 'attachments' => [$upload()]], ['Accept' => 'application/json'])->assertCreated();
        $id = $response->json('data.id');
        $this->post('/api/support/tickets', [...$data, 'attachments' => [$upload()]], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertCount(1, Storage::disk('local')->allFiles('support-attachments'));
        $detail = $this->getJson('/api/support/tickets/'.$id)->assertOk();
        $detail->assertDontSee('storage_path')->assertDontSee('sha256');
        $download = $detail->json('messages.0.attachments.0.download_url');
        $this->get($download)->assertOk()->assertDownload('error.txt');
        $this->post('/api/support/tickets', [...$data, 'attachments' => [UploadedFile::fake()->createWithContent('error.txt', 'Changed evidence')]], ['Accept' => 'application/json'])->assertConflict();
        $this->assertCount(1, Storage::disk('local')->allFiles('support-attachments'));
        $this->actingAs($admin, 'sanctum')->post('/api/support/tickets/'.$id.'/replies', [
            'request_key' => (string) Str::uuid(), 'body' => 'Staff evidence', 'internal' => true, 'attachments' => [$upload()],
        ], ['Accept' => 'application/json'])->assertOk();
        $detail = $this->getJson('/api/support/tickets/'.$id)->assertOk();
        $private = $detail->json('messages.1.attachments.0.download_url');
        $this->actingAs($owner, 'sanctum')->get($private)->assertNotFound();
        $this->getJson('/api/support/tickets/'.$id)->assertJsonCount(1, 'messages');
    }

    public function test_cached_email_notification_and_email_overview_cannot_bypass_converted_support_privacy(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $admin = User::factory()->create(['role' => 'admin', 'permission_overrides' => ['allow' => [], 'deny' => ['support-manage']]]);
        $source = $this->legacy($owner);
        $this->actingAs($admin, 'sanctum')->getJson('/api/notifications')->assertOk()->assertJsonFragment(['id' => 'email-'.$source->id]);
        $ticket = app(SupportLegacyMessageImporter::class)->import($source);
        $feed = $this->getJson('/api/notifications')->assertOk()->json('data.activity_log');
        $this->assertNotContains('email-'.$source->id, array_column($feed, 'id'));
        $this->assertNotContains('support-'.$ticket->messages()->first()->id, array_column($feed, 'id'));
        $this->getJson('/api/messaging/overview')->assertOk()->assertJsonCount(0, 'recent_activity');
        $this->getJson('/api/support/tickets/legacy-message/'.$source->id)->assertNotFound();
        $this->getJson('/api/support/tickets/'.$ticket->id)->assertNotFound();
    }

    public function test_email_channel_role_gates_preserve_existing_sales_rep_shoot_notifications(): void
    {
        require base_path('routes/channels.php');
        $channels = \Illuminate\Support\Facades\Broadcast::getChannels();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($channels['admin.notifications']($rep));
        $this->assertFalse($channels['email.inbox']($rep));
        $this->assertFalse($channels['email.user.{userId}']($rep, $rep->id));
        $this->assertTrue($channels['email.inbox']($admin));
        $this->assertTrue($channels['email.user.{userId}']($admin, $admin->id));
        $this->assertFalse($channels['email.user.{userId}']($admin, $rep->id));
        $admin->update(['permission_overrides' => ['allow' => [], 'deny' => ['messaging-email-view']]]);
        $this->assertFalse($channels['email.inbox']($admin->fresh()));
        $this->assertTrue($channels['admin.notifications']($admin->fresh()));
    }

    public function test_attachment_limits_and_imported_traversal_urls_are_not_downloadable(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'client']);
        $this->actingAs($owner, 'sanctum')->post('/api/support/tickets', ['request_key' => (string) Str::uuid(), 'subject' => 'Too large', 'body' => 'A large upload report', 'category' => 'other',
            'attachments' => [UploadedFile::fake()->create('big.bin', 10241)]], ['Accept' => 'application/json'])->assertUnprocessable();
        $source = $this->legacy($owner, ['attachments_json' => [['name' => 'secret', 'disk' => 'local', 'storage_path' => 'messaging-attachments/../../.env', 'url' => 'https://external.invalid/private']]]);
        $ticket = app(SupportLegacyMessageImporter::class)->import($source);
        $url = $this->getJson('/api/support/tickets/'.$ticket->id)->assertOk()->json('messages.0.attachments.0.download_url');
        $this->get($url)->assertNotFound();
    }
}
