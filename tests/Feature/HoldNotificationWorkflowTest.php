<?php

namespace Tests\Feature;

use App\Events\ShootActivityBroadcast;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\SmsNumber;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\Providers\TelnyxSmsProvider;
use App\Services\Messaging\ShootRequestRecipientRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HoldNotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Event::fake([ShootActivityBroadcast::class]);
    }

    public function test_legacy_hold_preserves_cancellation_and_reason_without_sending(): void
    {
        [$shoot, $admin, $client] = $this->fixture();
        $requestedAt = now()->subHour()->startOfSecond();
        $shoot->update([
            'hold_requested_at' => $requestedAt, 'hold_requested_by' => $client->id,
            'cancellation_requested_at' => $requestedAt, 'cancellation_requested_by' => $client->id,
            'cancellation_reason' => 'Seller requested cancellation',
        ]);
        $messaging = $this->mock(MessagingService::class);
        $messaging->shouldNotReceive('sendEmail');
        $messaging->shouldNotReceive('sendSms');

        $this->actingAs($admin, 'sanctum')->postJson("/api/shoots/{$shoot->id}/put-on-hold", ['reason' => 'More work needed'])
            ->assertOk()->assertJsonPath('notifications.requested', false)
            ->assertJsonPath('data.holdReason', 'More work needed')->assertJsonPath('data.holdRequestedAt', null);

        $shoot->refresh();
        $this->assertSame(Shoot::STATUS_ON_HOLD, $shoot->workflow_status);
        $this->assertTrue($shoot->cancellation_requested_at->equalTo($requestedAt));
        $this->assertSame($client->id, $shoot->cancellation_requested_by);
        $this->assertSame('Seller requested cancellation', $shoot->cancellation_reason);
        $this->assertNull($shoot->hold_requested_by);
    }

    public function test_explicit_hold_sends_saved_templates_after_write_to_client_and_effective_photographers(): void
    {
        [$shoot, $admin, $client, $obsolete] = $this->fixture();
        $first = User::factory()->photographer()->create(['phonenumber' => '+12025550111']);
        $second = User::factory()->photographer()->create(['phonenumber' => '+12025550112']);
        foreach ([$first, $second] as $photographer) {
            $shoot->services()->attach(Service::factory()->create()->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $photographer->id]);
        }
        $this->templates();
        $baselineTransactions = DB::transactionLevel();
        $sent = [];
        $messaging = $this->mock(MessagingService::class);
        foreach (['sendEmail', 'sendSms'] as $method) {
            $messaging->shouldReceive($method)->times(3)->andReturnUsing(function (array $payload) use (&$sent, $shoot, $baselineTransactions, $method): Message {
                $this->assertSame($baselineTransactions, DB::transactionLevel());
                $this->assertSame(Shoot::STATUS_ON_HOLD, $shoot->fresh()->workflow_status);
                $this->assertSame('MANUAL', $payload['send_source']);
                $this->assertStringContainsString('Saved hold copy', $payload['body_text']);
                $sent[$method][] = $payload['contact_user_id'];
                return new Message(['status' => $method === 'sendEmail' ? 'QUEUED' : 'SENT']);
            });
        }

        $this->actingAs($admin, 'sanctum')->postJson("/api/shoots/{$shoot->id}/put-on-hold", [
            'reason' => 'Waiting for staging', 'notify_client' => true, 'notify_photographer' => true,
            'notification_channels' => ['email', 'sms'],
        ])->assertOk()->assertJsonPath('notifications.sent', 3)->assertJsonPath('notifications.queued', 3)
            ->assertJsonPath('notifications.failed', 0)->assertJsonPath('notifications.skipped', 0);

        foreach ($sent as $ids) {
            $this->assertEqualsCanonicalizing([$client->id, $first->id, $second->id], $ids);
            $this->assertNotContains($obsolete->id, $ids);
        }
        $this->assertSame(6, \App\Models\UserActivityLog::where('event_type', 'notification.manual_send')->count());
    }

    public function test_sales_rep_can_approve_hold_and_notify_only_requested_recipient_with_default_email(): void
    {
        [$shoot, , $client, $photographer] = $this->fixture();
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot->update(['rep_id' => $rep->id, 'hold_requested_at' => now(), 'hold_requested_by' => $client->id, 'hold_reason' => 'Seller needs time']);
        $this->templates();
        $messaging = $this->mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->once()
            ->withArgs(fn (array $payload) => $payload['to'] === $photographer->email)
            ->andReturn(new Message(['status' => 'SENT']));
        $messaging->shouldNotReceive('sendSms');

        $this->actingAs($rep, 'sanctum')->postJson("/api/shoots/{$shoot->id}/approve-hold", ['notify_client' => false, 'notify_photographer' => true])
            ->assertOk()->assertJsonPath('notifications.sent', 1)->assertJsonPath('data.holdRequestedAt', null)
            ->assertJsonPath('data.holdReason', 'Seller needs time');
    }

    public function test_partial_notification_failure_never_reverses_hold_or_prevents_other_recipients(): void
    {
        [$shoot, $admin, $client] = $this->fixture();
        $this->templates();
        $messaging = $this->mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->twice()->andReturnUsing(function (array $payload) use ($client): Message {
            if ($payload['contact_user_id'] === $client->id) {
                throw new \RuntimeException('Provider unavailable');
            }
            return new Message(['status' => 'BLOCKED']);
        });

        $this->actingAs($admin, 'sanctum')->postJson("/api/shoots/{$shoot->id}/put-on-hold", [
            'reason' => 'Weather', 'notify_client' => true, 'notify_photographer' => true,
        ])->assertOk()->assertJsonPath('notifications.sent', 0)->assertJsonPath('notifications.failed', 1)
            ->assertJsonPath('notifications.skipped', 1)->assertJsonPath('data.status', 'on_hold');
        $this->assertSame(Shoot::STATUS_ON_HOLD, $shoot->fresh()->workflow_status);
    }

    public function test_invalid_notification_options_are_rejected_before_hold_is_saved(): void
    {
        [$shoot, $admin] = $this->fixture();
        foreach ([[], ['fax'], ['email', 'email']] as $channels) {
            $this->actingAs($admin, 'sanctum')->postJson("/api/shoots/{$shoot->id}/put-on-hold", [
                'reason' => 'Weather', 'notify_client' => true, 'notification_channels' => $channels,
            ])->assertUnprocessable();
            $this->assertSame(Shoot::STATUS_SCHEDULED, $shoot->fresh()->workflow_status);
        }
    }

    public function test_opted_out_photographer_is_not_texted_and_hold_still_succeeds(): void
    {
        [$shoot, $admin, , $photographer] = $this->fixture();
        $photographer->update(['sms_opt_out' => true]);
        $this->templates();
        SmsNumber::create(['provider' => 'TELNYX', 'phone_number' => '+12025550999', 'is_default' => true, 'owner_type' => 'GLOBAL']);
        $this->mock(TelnyxSmsProvider::class)->shouldNotReceive('send');

        $this->actingAs($admin, 'sanctum')->postJson("/api/shoots/{$shoot->id}/put-on-hold", [
            'reason' => 'Weather', 'notify_photographer' => true, 'notification_channels' => ['sms'],
        ])->assertOk()->assertJsonPath('notifications.sent', 0)->assertJsonPath('notifications.failed', 1);
        $this->assertSame(Shoot::STATUS_ON_HOLD, $shoot->fresh()->workflow_status);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_actual_hold_keeps_photographer_routing_while_pending_requests_go_to_rep(): void
    {
        [$shoot, , , $photographer] = $this->fixture();
        $this->assertSame(['client', 'photographer'], ShootRequestRecipientRouting::roles('SHOOT_ON_HOLD', ['client', 'photographer']));
        foreach (['HOLD_REQUESTED', 'SHOOT_CANCELLATION_REQUESTED', 'SHOOT_RESCHEDULE_REQUESTED'] as $trigger) {
            $this->assertSame(['client', 'rep'], ShootRequestRecipientRouting::roles($trigger, ['client', 'photographer']));
        }
        $context = ShootRequestRecipientRouting::context('SHOOT_ON_HOLD', ['shoot_id' => $shoot->id]);
        $this->assertSame([$photographer->id], collect($context['photographers'])->pluck('id')->all());
    }

    public function test_hold_automation_context_uses_all_current_service_photographers(): void
    {
        [$shoot, , , $obsolete] = $this->fixture();
        $assigned = User::factory()->photographer()->count(2)->create();
        foreach ($assigned as $photographer) {
            $shoot->services()->attach(Service::factory()->create()->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $photographer->id]);
        }
        $rule = new \App\Models\AutomationRule(['trigger_type' => 'SHOOT_ON_HOLD']);
        $recipients = (new \ReflectionMethod(\App\Services\Messaging\AutomationWorkflowExecutor::class, 'resolveActionRecipients'))->invoke(
            app(\App\Services\Messaging\AutomationWorkflowExecutor::class), $rule,
            ['recipientMode' => 'context', 'contextKey' => 'photographer'],
            ['shoot_id' => $shoot->id, 'photographer' => $obsolete, 'photographers' => [$obsolete]], 'email'
        );
        $this->assertEqualsCanonicalizing($assigned->pluck('id')->all(), array_column($recipients, 'id'));
        $this->assertNotContains($obsolete->id, array_column($recipients, 'id'));
    }

    public function test_rejecting_cancellation_on_a_held_shoot_preserves_hold_and_uses_current_status_in_notification(): void
    {
        [$shoot, $admin, $client] = $this->fixture();
        $shoot->update([
            'status' => Shoot::STATUS_ON_HOLD, 'workflow_status' => Shoot::STATUS_ON_HOLD,
            'cancellation_requested_at' => now(), 'cancellation_requested_by' => $client->id,
        ]);
        $this->mock(\App\Services\Messaging\AutomationService::class)
            ->shouldReceive('handleEvent')->once()->with('SHOOT_CANCELLATION_REJECTED', \Mockery::type('array'));
        $support = \Mockery::mock(\App\Services\Shoots\ShootWorkflowTransitionSupportService::class, [
            app(\App\Services\MailService::class), app(\App\Services\Messaging\AutomationService::class),
        ])->makePartial()->shouldAllowMockingProtectedMethods();
        $support->shouldReceive('notifyUser')->once()->withArgs(
            fn (User $recipient, string $type, string $title, string $message, array $data) =>
                $recipient->id === $client->id && $type === 'cancellation_rejected'
                && $message === 'Your cancellation request was rejected. The shoot remains on hold.'
        );
        $this->app->instance(\App\Services\Shoots\ShootWorkflowTransitionSupportService::class, $support);

        $this->actingAs($admin, 'sanctum')->postJson("/api/shoots/{$shoot->id}/reject-cancellation")
            ->assertOk()->assertJsonPath('data.status', 'on_hold')->assertJsonPath('data.cancellationRequestedAt', null);
    }

    public function test_pending_hold_lists_include_photographer_contacts_for_reviewers(): void
    {
        [$shoot, $admin, $client, $photographer] = $this->fixture();
        $second = User::factory()->photographer()->create(['phonenumber' => '+12025550144']);
        $shoot->services()->attach(Service::factory()->create()->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $second->id]);
        $shoot->update(['hold_requested_at' => now(), 'hold_requested_by' => $client->id]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/shoots/pending-holds')->assertOk()
            ->assertJsonPath('data.0.photographer.email', $photographer->email)
            ->assertJsonPath('data.0.photographer.phone', $photographer->phonenumber)
            ->assertJsonPath('data.0.services.0.photographer.email', $second->email)
            ->assertJsonPath('data.0.services.0.photographer.phone', $second->phonenumber);
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client', 'phonenumber' => '+12025550101']);
        $photographer = User::factory()->photographer()->create(['phonenumber' => '+12025550102']);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'photographer_id' => $photographer->id,
            'status' => Shoot::STATUS_SCHEDULED, 'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
        return [$shoot, $admin, $client, $photographer];
    }

    private function templates(): void
    {
        foreach (['EMAIL', 'SMS'] as $channel) {
            MessageTemplate::updateOrCreate(['slug' => 'shoot-on-hold', 'channel' => $channel], [
                'name' => 'Saved hold template', 'scope' => 'SYSTEM', 'is_system' => true, 'is_active' => true,
                'category' => 'BOOKING', 'subject' => 'On hold', 'body_html' => $channel === 'EMAIL' ? '<p>Saved hold copy {{recipient_first_name}}</p>' : null,
                'body_text' => 'Saved hold copy {{recipient_first_name}}',
            ]);
        }
    }
}
