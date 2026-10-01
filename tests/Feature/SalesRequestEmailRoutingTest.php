<?php

namespace Tests\Feature;

use App\Models\Message;
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

    public function test_cancellation_receipt_copies_account_rep_instead_of_photographer(): void
    {
        [$shoot, $client, $rep, $photographer] = $this->fixture(true);
        $this->assertTrue(app(MailService::class)->sendShootCancelledEmail($client, $shoot));
        $this->assertSame([$client->email, $rep->email], array_column($this->deliveries, 'to'));
        $this->assertNotContains($photographer->email, array_column($this->deliveries, 'to'));
    }

    public function test_cancellation_explicit_recipient_does_not_fan_out(): void
    {
        [$shoot, $client] = $this->fixture();
        $this->assertTrue(app(MailService::class)->sendShootCancelledEmail($client, $shoot, false));
        $this->assertSame([$client->email], array_column($this->deliveries, 'to'));
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

    public function test_manual_hold_and_cancellation_sends_reject_photographer(): void
    {
        [$shoot] = $this->fixture();
        foreach (['shoot_on_hold', 'shoot_cancelled'] as $type) {
            try {
                app(ManualNotificationService::class)->send($shoot, $type, 'photographer', 'email', $shoot->client);
                $this->fail('Photographer routing must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('sales rep', $exception->getMessage());
            }
        }
        $this->assertSame([], $this->deliveries);
    }
}
