<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use App\Services\Shoots\ShootSalesRepResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SalesRequestEmailRoutingTest extends TestCase
{
    use RefreshDatabase;

    private array $deliveries = [];

    private function fixture(bool $accountAssignment = false): array
    {
        $client = User::factory()->create(['role' => 'client']);
        $rep = User::factory()->create(['role' => 'salesRep']);
        $photographer = User::factory()->photographer()->create();
        if ($accountAssignment) {
            $client->update(['metadata' => ['accountRepId' => (string) $rep->id]]);
        }
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id, 'rep_id' => $accountAssignment ? null : $rep->id,
            'photographer_id' => $photographer->id, 'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED, 'cancellation_reason' => 'Seller needs more time',
        ]);
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->zeroOrMoreTimes()->andReturnUsing(function (array $payload) {
            $this->deliveries[] = $payload;

            return new Message;
        });
        $this->app->instance(MessagingService::class, $messaging);

        return [$shoot, $client, $rep, $photographer];
    }

    public function test_cancellation_request_email_gives_rep_review_instructions_and_excludes_photographer(): void
    {
        [$shoot, $client, $rep, $photographer] = $this->fixture(true);
        $mail = app(MailService::class);
        $this->assertTrue($mail->sendShootCancellationRequestedEmail($rep, $shoot));
        $this->assertFalse($mail->sendShootCancellationRequestedEmail($photographer, $shoot));
        $this->assertCount(1, $this->deliveries);
        $this->assertSame($rep->email, $this->deliveries[0]['to']);
        $this->assertSame('rep', $this->deliveries[0]['contact_type']);
        $this->assertStringContainsString('approve or reject', $this->deliveries[0]['body_html']);
        $this->assertStringNotContainsString('pause any prep', $this->deliveries[0]['body_html']);
    }

    public function test_final_cancellation_notifies_client_account_rep_and_assigned_photographer(): void
    {
        [$shoot, $client, $rep, $photographer] = $this->fixture(true);
        $this->assertTrue(app(MailService::class)->sendShootCancelledEmail($client, $shoot));
        $this->assertSame([$client->email, $rep->email, $photographer->email], array_column($this->deliveries, 'to'));
        $this->assertSame(['client', 'rep', 'photographer'], array_column($this->deliveries, 'contact_type'));
    }

    public function test_cancellation_explicit_recipient_does_not_fan_out(): void
    {
        [$shoot, $client, $rep, $photographer] = $this->fixture();
        foreach ([$client, $rep, $photographer] as $recipient) {
            $this->deliveries = [];
            $this->assertTrue(app(MailService::class)->sendShootCancelledEmail($recipient, $shoot, false));
            $this->assertSame([$recipient->email], array_column($this->deliveries, 'to'));
        }
    }

    public function test_final_cancellation_includes_each_service_photographer_and_excludes_unassigned_users(): void
    {
        [$shoot, $client, $rep, $photographer] = $this->fixture();
        $second = User::factory()->photographer()->create();
        $unassigned = User::factory()->photographer()->create();
        foreach ([$photographer, $second] as $assigned) {
            $service = Service::factory()->create();
            $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $assigned->id]);
        }
        $mail = app(MailService::class);
        $this->assertTrue($mail->sendShootCancelledEmail($client, $shoot));
        $this->assertSame([$client->email, $rep->email, $photographer->email, $second->email], array_column($this->deliveries, 'to'));
        $this->assertFalse($mail->sendShootCancelledEmail($unassigned, $shoot, false));
        $this->assertCount(4, $this->deliveries);
    }

    public function test_final_cancellation_uses_effective_service_assignments_instead_of_superseded_parent_photographer(): void
    {
        [$shoot, $client, $rep, $oldPhotographer] = $this->fixture();
        $assigned = User::factory()->photographer()->create();
        $service = Service::factory()->create();
        $shoot->services()->attach($service->id, ['price' => 100, 'quantity' => 1, 'photographer_id' => $assigned->id]);
        $mail = app(MailService::class);
        $this->assertTrue($mail->sendShootCancelledEmail($client, $shoot));
        $this->assertSame([$client->email, $rep->email, $assigned->email], array_column($this->deliveries, 'to'));
        $this->assertFalse($mail->sendShootCancelledEmail($oldPhotographer, $shoot, false));
    }

    public function test_final_cancellation_deduplicates_a_sales_rep_who_is_also_the_assigned_photographer(): void
    {
        [$shoot, $client, $rep] = $this->fixture();
        $rep->update(['secondary_roles' => ['photographer']]);
        $shoot->update(['photographer_id' => $rep->id]);
        $this->assertTrue(app(MailService::class)->sendShootCancelledEmail($client, $shoot));
        $this->assertSame([$client->email, $rep->email], array_column($this->deliveries, 'to'));
    }

    public function test_booking_request_staff_email_targets_the_rep(): void
    {
        [$shoot, , $rep, $photographer] = $this->fixture();
        $mail = app(MailService::class);
        $this->assertTrue($mail->sendShootRequestedStaffEmail($rep, $shoot));
        $this->assertFalse($mail->sendShootRequestedStaffEmail($photographer, $shoot));
        $this->assertSame([$rep->email], array_column($this->deliveries, 'to'));
        $this->assertSame('rep', $this->deliveries[0]['contact_type']);
    }

    public function test_resolver_prefers_shoot_rep_and_never_guesses_a_missing_rep(): void
    {
        [$shoot, $client, $rep, $photographer] = $this->fixture();
        $other = User::factory()->create(['role' => 'salesRep']);
        $client->update(['metadata' => ['account_rep_id' => $other->id]]);
        $resolver = app(ShootSalesRepResolver::class);
        $this->assertSame($rep->id, $resolver->resolve($shoot->fresh())->id);
        $shoot->update(['rep_id' => $photographer->id]);
        $this->assertSame($other->id, $resolver->resolve($shoot->fresh())->id);
        $client->update(['metadata' => ['account_rep_id' => $photographer->id]]);
        $this->assertNull($resolver->resolve($shoot->fresh()));
    }

    public function test_manual_hold_can_notify_the_assigned_photographer(): void
    {
        [$shoot, , , $photographer] = $this->fixture();
        \App\Models\MessageTemplate::create([
            'slug' => 'shoot-on-hold', 'channel' => 'EMAIL', 'name' => 'Hold', 'is_active' => true,
            'scope' => 'SYSTEM', 'body_html' => '<p>Shoot on hold</p>', 'body_text' => 'Shoot on hold',
        ]);
        app(ManualNotificationService::class)->send($shoot, 'shoot_on_hold', 'photographer', 'email', $shoot->client);
        $this->assertSame([$photographer->email], array_column($this->deliveries, 'to'));
    }
}
