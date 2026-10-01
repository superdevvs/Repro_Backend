<?php

namespace Tests\Feature;

use App\Jobs\SendInternalMessageNotificationEmail;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Shoot;
use App\Models\SupportTicket;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use App\Services\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternalMessageNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
    }

    public function test_client_submission_creates_private_support_with_authorized_staff_notifications_only(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $superAdmin = User::factory()->create(['role' => 'superadmin']);
        $editingManager = User::factory()->create(['role' => 'editing_manager', 'permission_overrides' => ['allow' => ['support-view', 'support-manage']]]);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'account_status' => 'inactive']);
        $emailOptOutAdmin = User::factory()->create([
            'role' => 'admin',
            'metadata' => ['preferences' => ['notificationEmail' => false]],
        ]);
        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $salesRep->id]);

        Sanctum::actingAs($client);
        $response = $this->postJson('/api/messaging/email/compose', [
            'subject' => 'Need an update',
            'body_text' => 'Can someone check the delivery status?',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertCreated()->assertJsonPath('data.requester.id', $client->id);

        $ticket = SupportTicket::findOrFail($response->json('support_ticket_id'));
        $url = '/messaging/email/inbox?tab=support&ticket='.$ticket->id;
        $response->assertJsonPath('redirect_url', $url);
        $this->assertSame($client->id, $ticket->requester_id);
        $this->assertSame('Can someone check the delivery status?', $ticket->messages()->firstOrFail()->body);
        foreach ([$admin, $superAdmin, $editingManager, $emailOptOutAdmin] as $staff) {
            $this->assertSame([$url], app(SupportTicketService::class)->notifications($staff)->pluck('actionUrl')->all());
        }
        foreach ([$salesRep, $otherRep, $inactiveAdmin] as $other) {
            $this->assertCount(0, app(SupportTicketService::class)->notifications($other));
            $this->assertSame(0, app(SupportTicketService::class)->visible($other)->count());
        }
        $this->assertDatabaseCount('messages', 0);
        Queue::assertNotPushed(SendInternalMessageNotificationEmail::class);
    }

    public function test_general_dashboard_request_needs_no_shoot_or_email_template(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'created_by_id' => null]);

        Sanctum::actingAs($client);
        $response = $this->postJson('/api/messaging/email/compose', [
            'body_html' => '<p>General dashboard &amp; profile question</p>',
        ])->assertCreated()->assertJsonPath('data.subject', 'Dashboard support request');

        $ticket = SupportTicket::findOrFail($response->json('support_ticket_id'));
        $this->assertSame('General dashboard & profile question', $ticket->messages()->firstOrFail()->body);
        $this->assertCount(1, app(SupportTicketService::class)->notifications($admin));
        $this->assertCount(0, app(SupportTicketService::class)->notifications($salesRep));
        $this->assertDatabaseCount('shoots', 0);
        $this->assertDatabaseCount('messages', 0);
        Queue::assertNotPushed(SendInternalMessageNotificationEmail::class);
    }

    public function test_a_primary_admin_also_assigned_as_rep_sees_one_support_notification(): void
    {
        Queue::fake();

        $adminRep = User::factory()->create([
            'role' => 'admin',
            'secondary_roles' => ['salesRep'],
        ]);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $adminRep->id]);

        Sanctum::actingAs($client);
        $this->postJson('/api/messaging/email/compose', [
            'body_text' => 'Please take a look',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertCreated();

        $notifications = app(SupportTicketService::class)->notifications($adminRep);
        $this->assertCount(1, $notifications);
        $this->assertSame('support_updated', $notifications->first()['action']);
        Queue::assertNotPushed(SendInternalMessageNotificationEmail::class);
    }

    public function test_staff_reply_to_legacy_contact_imports_private_support_and_notifies_the_requester_in_app(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com']);
        $client = User::factory()->create(['role' => 'client', 'email' => 'client@example.com']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $original = $this->internalClientMessage($client, $shoot);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/messaging/email/compose', [
            'in_reply_to_message_id' => $original->id,
            'body_text' => 'We are checking this now.',
        ])->assertOk();

        $ticket = SupportTicket::findOrFail($response->json('support_ticket_id'));
        $this->assertSame($client->id, $ticket->requester_id);
        $this->assertSame([$original->body_text, 'We are checking this now.'], $ticket->messages()->orderBy('id')->pluck('body')->all());
        $this->assertSame($original->id, $ticket->messages()->oldest('id')->firstOrFail()->source_message_id);
        $this->assertSame($admin->id, $ticket->messages()->latest('id')->firstOrFail()->author_id);
        $this->assertDatabaseCount('messages', 1);
        Queue::assertNotPushed(SendInternalMessageNotificationEmail::class);

        Sanctum::actingAs($client);
        $this->getJson('/api/support/tickets/'.$ticket->id)->assertOk()->assertJsonFragment(['body' => 'We are checking this now.']);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment([
                'action' => 'support_updated',
                'actionUrl' => '/messaging/email/inbox?tab=support&ticket='.$ticket->id,
            ]);
    }

    public function test_assigned_rep_cannot_reply_to_client_support_but_requester_reply_notifies_staff(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $salesRep->id]);
        $original = $this->internalClientMessage($client, $shoot);

        Sanctum::actingAs($salesRep);
        $this->postJson('/api/messaging/email/compose', [
            'in_reply_to_message_id' => $original->id,
            'body_text' => 'Your sales rep is following up.',
        ])->assertNotFound();
        $this->assertDatabaseCount('support_tickets', 0);

        Queue::fake();
        Sanctum::actingAs($client);
        $clientResponse = $this->postJson('/api/messaging/email/compose', [
            'in_reply_to_message_id' => $original->id,
            'body_text' => 'Thanks, I have one more question.',
        ])->assertOk();
        $ticket = SupportTicket::findOrFail($clientResponse->json('support_ticket_id'));
        $this->assertSame($client->id, $ticket->requester_id);
        $this->assertSame('Thanks, I have one more question.', $ticket->messages()->latest('id')->firstOrFail()->body);
        $this->assertCount(2, app(SupportTicketService::class)->notifications($admin));
        $this->assertCount(0, app(SupportTicketService::class)->notifications($salesRep));
        $this->assertDatabaseCount('messages', 1);
        Queue::assertNotPushed(SendInternalMessageNotificationEmail::class);
    }

    public function test_secondary_admin_role_does_not_grant_staff_access_to_another_requester(): void
    {
        Queue::fake();

        $staff = User::factory()->create([
            'role' => 'photographer',
            'secondary_roles' => ['admin'],
        ]);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $original = $this->internalClientMessage($client, $shoot);

        Sanctum::actingAs($staff);
        $this->postJson('/api/messaging/email/compose', [
            'in_reply_to_message_id' => $original->id,
            'body_text' => 'Replying while using the admin role.',
        ])->assertNotFound();

        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('messages', 1);
        Queue::assertNotPushed(SendInternalMessageNotificationEmail::class);
    }

    public function test_rep_notifications_hide_other_clients_legacy_contact_but_show_own_support_replies(): void
    {
        $assignedRep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $assignedRep->id]);
        $message = $this->internalClientMessage($client, $shoot);

        foreach ([$assignedRep, $otherRep] as $rep) {
            Sanctum::actingAs($rep);
            $payload = $this->getJson('/api/notifications')->assertOk()
                ->assertJsonPath('data.unread_counts.email', 0)->json('data.activity_log');
            $this->assertFalse(collect($payload)->contains('id', 'email-'.$message->id));
        }

        $admin = User::factory()->create(['role' => 'admin']);
        $tickets = app(SupportTicketService::class);
        $ownRequest = $tickets->create($assignedRep, ['request_key' => (string) Str::uuid(),
            'subject' => 'My dashboard question', 'category' => 'account', 'body' => 'I need help with my profile.']);
        $tickets->reply($admin, $ownRequest->id, ['request_key' => (string) Str::uuid(), 'body' => 'Here are the steps for your profile.']);

        Sanctum::actingAs($assignedRep);
        $this->getJson('/api/notifications')->assertOk()->assertJsonFragment([
            'action' => 'support_updated',
            'actionUrl' => '/messaging/email/inbox?tab=support&ticket='.$ownRequest->id,
        ])->assertJsonMissing(['id' => 'email-'.$message->id]);
        Sanctum::actingAs($otherRep);
        $this->getJson('/api/notifications')->assertOk()->assertJsonMissing([
            'actionUrl' => '/messaging/email/inbox?tab=support&ticket='.$ownRequest->id,
        ]);
    }

    public function test_staff_reply_is_saved_without_email_for_opted_out_or_inactive_requesters(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $clients = [
            User::factory()->create([
                'role' => 'client',
                'metadata' => ['preferences' => ['notificationEmail' => false]],
            ]),
            User::factory()->create([
                'role' => 'client',
                'account_status' => 'inactive',
            ]),
        ];

        foreach ($clients as $client) {
            $original = $this->internalClientMessage(
                $client,
                Shoot::factory()->create(['client_id' => $client->id]),
            );
            Sanctum::actingAs($admin);
            $response = $this->postJson('/api/messaging/email/compose', [
                'in_reply_to_message_id' => $original->id,
                'body_text' => 'Staff response',
            ])->assertOk();
            $ticket = SupportTicket::findOrFail($response->json('support_ticket_id'));
            $this->assertSame($client->id, $ticket->requester_id);
            $this->assertSame('Staff response', $ticket->messages()->latest('id')->firstOrFail()->body);
        }

        $this->assertDatabaseCount('messages', 2);
        Queue::assertNotPushed(SendInternalMessageNotificationEmail::class);
    }

    public function test_notification_email_is_a_safe_preview_with_direct_link_and_idempotent_audit(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.com']);
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Taylor Client',
            'company_name' => 'Taylor Realty',
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'address' => '123 Main Street',
            'city' => 'Baltimore',
            'state' => 'MD',
        ]);
        $source = $this->internalClientMessage(
            $client,
            $shoot,
            'Hello <script>alert("x")</script> '.str_repeat('private detail ', 40),
        );

        $job = new SendInternalMessageNotificationEmail($source->id, $admin->id);
        $job->handle(app(\App\Services\Messaging\InternalMessageNotificationService::class));
        $job->handle(app(\App\Services\Messaging\InternalMessageNotificationService::class));

        $dispatch = SystemEmailDispatch::query()
            ->where('email_alias', 'INTERNAL_MESSAGE_NOTIFICATION')
            ->firstOrFail();
        $notification = Message::query()->findOrFail($dispatch->message_id);

        $this->assertSame('sent', $dispatch->status);
        $this->assertSame(1, $dispatch->attempt_count);
        $this->assertSame(1, SystemEmailDispatch::query()->where('email_alias', 'INTERNAL_MESSAGE_NOTIFICATION')->count());
        $this->assertSame(1, Message::query()->where('send_source', 'INTERNAL_MESSAGE_NOTIFICATION')->count());
        $this->assertStringContainsString('Taylor Client', (string) $notification->body_html);
        $this->assertStringContainsString('Client', (string) $notification->body_html);
        $this->assertStringContainsString('Taylor Realty', (string) $notification->body_html);
        $this->assertStringContainsString('123 Main Street', (string) $notification->body_html);
        $this->assertStringContainsString("/messaging/email/inbox?message={$source->id}", (string) $notification->body_html);
        $this->assertStringContainsString('View Message', (string) $notification->body_html);
        $this->assertStringNotContainsString('<script>', (string) $notification->body_html);
        $this->assertStringNotContainsString(str_repeat('private detail ', 20), (string) $notification->body_html);
    }

    public function test_temporary_provider_failure_reuses_audit_row_and_succeeds_on_retry(): void
    {
        $this->createDefaultEmailChannel();
        $provider = new class extends LocalSmtpProvider
        {
            public int $attempts = 0;

            public function send(MessageChannel $channel, array $payload): string
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new \RuntimeException('Temporary SMTP outage');
                }

                return 'provider-recovered-id';
            }
        };
        $this->app->instance(LocalSmtpProvider::class, $provider);

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id]);
        $source = $this->internalClientMessage($client, $shoot);
        $job = new SendInternalMessageNotificationEmail($source->id, $admin->id);

        try {
            $job->handle(app(\App\Services\Messaging\InternalMessageNotificationService::class));
            $this->fail('The first provider attempt should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Temporary SMTP outage', $exception->getMessage());
        }

        $failedDispatch = SystemEmailDispatch::query()->firstOrFail();
        $this->assertSame('failed', $failedDispatch->status);
        $this->assertSame(1, $failedDispatch->attempt_count);

        $job->handle(app(\App\Services\Messaging\InternalMessageNotificationService::class));

        $dispatch = $failedDispatch->fresh();
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame(2, $dispatch->attempt_count);
        $this->assertSame('provider-recovered-id', $dispatch->provider_message_id);
        $this->assertSame(1, SystemEmailDispatch::query()->count());
        $this->assertSame(2, Message::query()->where('send_source', 'INTERNAL_MESSAGE_NOTIFICATION')->count());
        $this->assertSame([30, 120, 300], $job->backoff());
        $this->assertSame(3, $job->tries);
    }

    private function internalClientMessage(
        User $client,
        Shoot $shoot,
        string $body = 'Client dashboard message',
    ): Message {
        return app(MessagingService::class)->storeInternalEmail([
            'from' => $client->email,
            'to' => config('mail.contact_address', 'contact@reprophotos.com'),
            'subject' => 'Question about the shoot',
            'body_text' => $body,
            'user_id' => $client->id,
            'send_source' => 'MANUAL',
            'sender_user_id' => $client->id,
            'sender_account_id' => $client->id,
            'sender_role' => $client->role,
            'sender_display_name' => $client->name,
            'contact_email' => $client->email,
            'contact_name' => $client->name,
            'contact_type' => 'client',
            'contact_user_id' => $client->id,
            'contact_account_id' => $client->id,
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
            'related_account_id' => $client->id,
        ], 'INBOUND');
    }

    private function createDefaultEmailChannel(): MessageChannel
    {
        return MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);
    }
}
