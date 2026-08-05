<?php

namespace Tests\Feature;

use App\Jobs\SendInternalMessageNotificationEmail;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Shoot;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\Providers\LocalSmtpProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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

    public function test_client_message_queues_assigned_rep_and_all_active_admins_once(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $superAdmin = User::factory()->create(['role' => 'superadmin']);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        User::factory()->create(['role' => 'salesRep']);
        User::factory()->create(['role' => 'admin', 'account_status' => 'inactive']);
        User::factory()->create([
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
        ])->assertOk();

        $messageId = (int) $response->json('id');
        $recipientIds = collect(Queue::pushed(SendInternalMessageNotificationEmail::class))
            ->map(fn (SendInternalMessageNotificationEmail $job) => $job->recipientId)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$admin->id, $superAdmin->id, $salesRep->id])->sort()->values()->all(),
            $recipientIds,
        );
        Queue::assertPushed(
            SendInternalMessageNotificationEmail::class,
            fn (SendInternalMessageNotificationEmail $job) => $job->messageId === $messageId,
        );
    }

    public function test_unassigned_client_notifies_admins_without_any_sales_rep(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client', 'created_by_id' => null]);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => null]);

        Sanctum::actingAs($client);
        $this->postJson('/api/messaging/email/compose', [
            'body_text' => 'Unassigned client question',
            'related_shoot_id' => $shoot->id,
            'related_shoot_context_type' => 'new_shoot',
        ])->assertOk();

        $this->assertSame(
            [$admin->id],
            collect(Queue::pushed(SendInternalMessageNotificationEmail::class))
                ->map(fn (SendInternalMessageNotificationEmail $job) => $job->recipientId)
                ->all(),
        );
    }

    public function test_a_user_qualifying_as_admin_and_assigned_rep_receives_only_one_job(): void
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
        ])->assertOk();

        Queue::assertPushed(SendInternalMessageNotificationEmail::class, 1);
        Queue::assertPushed(
            SendInternalMessageNotificationEmail::class,
            fn (SendInternalMessageNotificationEmail $job) => $job->recipientId === $adminRep->id,
        );
    }

    public function test_staff_reply_stays_internal_marks_client_unread_and_queues_client_notification(): void
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

        $reply = Message::query()->findOrFail((int) $response->json('id'));
        $this->assertSame('INTERNAL', $reply->provider);
        $this->assertSame('OUTBOUND', $reply->direction);
        $this->assertSame($client->email, $reply->to_address);
        $this->assertSame($original->thread_id, $reply->thread_id);
        $this->assertSame($original->id, $reply->metadata['internal_reply_to_message_id'] ?? null);
        $this->assertContains($client->id, $reply->thread->fresh()->unread_for_user_ids_json);

        Queue::assertPushed(SendInternalMessageNotificationEmail::class, 1);
        Queue::assertPushed(
            SendInternalMessageNotificationEmail::class,
            fn (SendInternalMessageNotificationEmail $job) => $job->messageId === $reply->id
                && $job->recipientId === $client->id,
        );

        Sanctum::actingAs($client);
        $this->getJson("/api/messaging/email/messages/{$reply->id}")->assertOk();
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment([
                'action' => 'internal_message_received',
                'actionUrl' => "/messaging/email/inbox?message={$reply->id}",
            ]);
        $this->postJson("/api/messaging/email/threads/{$reply->thread_id}/mark-read")->assertOk();
        $this->assertNotContains($client->id, $reply->thread->fresh()->unread_for_user_ids_json ?? []);
    }

    public function test_assigned_sales_rep_reply_notifies_client_and_client_reply_notifies_staff(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $salesRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $salesRep->id]);
        $original = $this->internalClientMessage($client, $shoot);

        Sanctum::actingAs($salesRep);
        $repResponse = $this->postJson('/api/messaging/email/compose', [
            'in_reply_to_message_id' => $original->id,
            'body_text' => 'Your sales rep is following up.',
        ])->assertOk();
        $repReply = Message::query()->findOrFail((int) $repResponse->json('id'));

        $this->assertSame('OUTBOUND', $repReply->direction);
        Queue::assertPushed(
            SendInternalMessageNotificationEmail::class,
            fn (SendInternalMessageNotificationEmail $job) => $job->messageId === $repReply->id
                && $job->recipientId === $client->id,
        );

        Queue::fake();
        Sanctum::actingAs($client);
        $clientResponse = $this->postJson('/api/messaging/email/compose', [
            'in_reply_to_message_id' => $repReply->id,
            'body_text' => 'Thanks, I have one more question.',
        ])->assertOk();
        $clientReply = Message::query()->findOrFail((int) $clientResponse->json('id'));

        $this->assertSame('INBOUND', $clientReply->direction);
        $this->assertSame($original->thread_id, $clientReply->thread_id);
        $queuedRecipientIds = collect(Queue::pushed(SendInternalMessageNotificationEmail::class))
            ->map(fn (SendInternalMessageNotificationEmail $job) => $job->recipientId)
            ->sort()
            ->values()
            ->all();
        $this->assertSame(collect([$admin->id, $salesRep->id])->sort()->values()->all(), $queuedRecipientIds);
    }

    public function test_staff_reply_uses_secondary_roles_when_choosing_the_client_recipient(): void
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
        $response = $this->postJson('/api/messaging/email/compose', [
            'in_reply_to_message_id' => $original->id,
            'body_text' => 'Replying while using the admin role.',
        ])->assertOk();

        $replyId = (int) $response->json('id');
        Queue::assertPushed(
            SendInternalMessageNotificationEmail::class,
            fn (SendInternalMessageNotificationEmail $job) => $job->messageId === $replyId
                && $job->recipientId === $client->id,
        );
        Queue::assertPushed(SendInternalMessageNotificationEmail::class, 1);
    }

    public function test_sales_rep_in_app_notifications_are_scoped_to_their_assigned_client(): void
    {
        $assignedRep = User::factory()->create(['role' => 'salesRep']);
        $otherRep = User::factory()->create(['role' => 'salesRep']);
        $client = User::factory()->create(['role' => 'client']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'rep_id' => $assignedRep->id]);
        $message = $this->internalClientMessage($client, $shoot);

        Sanctum::actingAs($assignedRep);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonFragment(['id' => 'email-' . $message->id]);

        Sanctum::actingAs($otherRep);
        $payload = $this->getJson('/api/notifications')->assertOk()->json('data.activity_log');
        $this->assertFalse(collect($payload)->contains('id', 'email-' . $message->id));
    }

    public function test_staff_reply_skips_client_with_disabled_preference_or_inactive_account(): void
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
            $this->postJson('/api/messaging/email/compose', [
                'in_reply_to_message_id' => $original->id,
                'body_text' => 'Staff response',
            ])->assertOk();
        }

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
            'Hello <script>alert("x")</script> ' . str_repeat('private detail ', 40),
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
