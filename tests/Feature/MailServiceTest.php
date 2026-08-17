<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function shoot_reminder_renders_the_date_and_call_time_without_repeating_the_time(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Reminder Client',
            'email' => 'reminder-client@test.com',
        ]);
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'address' => '123 Reminder Street',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
            'scheduled_date' => '2026-08-20',
            'time' => '14:30:00',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $deliveries = [];
        $messagingService = Mockery::mock(MessagingService::class);
        $messagingService->shouldReceive('sendEmail')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$deliveries) {
                $deliveries[] = $payload;

                return new Message;
            });
        $this->app->instance(MessagingService::class, $messagingService);

        $result = app(MailService::class)->sendShootReminderEmail(
            $client,
            $shoot,
            shouldNotifyPhotographer: false
        );

        $this->assertTrue($result);
        $this->assertCount(1, $deliveries);

        $visibleHtml = trim(preg_replace(
            '/\s+/',
            ' ',
            html_entity_decode(strip_tags($deliveries[0]['body_html'] ?? ''))
        ) ?? '');

        $this->assertStringContainsString('Aug 20, 2026', $visibleHtml);
        $this->assertStringContainsString('Call time: 2:30 PM', $visibleHtml);
        $this->assertSame(1, substr_count($visibleHtml, '2:30 PM'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function scheduled_email_continues_to_assigned_photographers_when_client_delivery_fails(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Scheduled Client',
            'email' => 'scheduled-client@test.com',
        ]);
        $primaryPhotographer = User::factory()->photographer()->create([
            'name' => 'Primary Photographer',
            'email' => 'primary-photographer@test.com',
        ]);
        $secondaryPhotographer = User::factory()->photographer()->create([
            'name' => 'Secondary Photographer',
            'email' => 'secondary-photographer@test.com',
        ]);
        $primaryService = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150.00,
        ]);
        $secondaryService = Service::factory()->create([
            'name' => 'Floor Plan',
            'price' => 90.00,
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $primaryPhotographer->id,
            'service_id' => $primaryService->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2)->setTime(11, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '11:00',
        ]);

        $shoot->services()->attach($primaryService->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $primaryPhotographer->id,
        ]);
        $shoot->services()->attach($secondaryService->id, [
            'price' => 90,
            'quantity' => 1,
            'photographer_pay' => 30,
            'photographer_id' => $secondaryPhotographer->id,
        ]);

        $deliveries = [];
        $messagingService = Mockery::mock(MessagingService::class);
        $messagingService->shouldReceive('sendEmail')
            ->times(3)
            ->andReturnUsing(function (array $payload) use (&$deliveries, $client) {
                $deliveries[] = $payload;

                if (($payload['to'] ?? null) === $client->email) {
                    throw new \RuntimeException('Client delivery failed');
                }

                return new Message;
            });
        $this->app->instance(MessagingService::class, $messagingService);

        $result = app(MailService::class)->sendShootScheduledEmail(
            $client,
            $shoot,
            'https://example.test/payment',
            true
        );

        $this->assertTrue($result);
        $this->assertSame([
            $client->email,
            $primaryPhotographer->email,
            $secondaryPhotographer->email,
        ], array_column($deliveries, 'to'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function scheduled_email_skips_blank_client_address_and_still_notifies_assigned_photographers(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Blank Scheduled Client',
            'email' => ' ',
        ]);
        $photographer = User::factory()->photographer()->create([
            'name' => 'Scheduled Photographer',
            'email' => 'scheduled-photographer@test.com',
        ]);
        $service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150.00,
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2)->setTime(11, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '11:00',
        ]);

        $shoot->services()->attach($service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $photographer->id,
        ]);

        $deliveries = [];
        $messagingService = Mockery::mock(MessagingService::class);
        $messagingService->shouldReceive('sendEmail')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$deliveries) {
                $deliveries[] = $payload;

                return new Message;
            });
        $this->app->instance(MessagingService::class, $messagingService);

        $result = app(MailService::class)->sendShootScheduledEmail(
            $client,
            $shoot,
            'https://example.test/payment',
            true
        );

        $this->assertTrue($result);
        $this->assertSame([$photographer->email], array_column($deliveries, 'to'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function updated_email_skips_blank_client_address_and_still_notifies_assigned_photographers(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Blank Updated Client',
            'email' => '  ',
        ]);
        $photographer = User::factory()->photographer()->create([
            'name' => 'Updated Photographer',
            'email' => 'updated-photographer@test.com',
        ]);
        $service = Service::factory()->create([
            'name' => 'HDR Photos',
            'price' => 150.00,
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2)->setTime(11, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '11:00',
        ]);

        $shoot->services()->attach($service->id, [
            'price' => 150,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $photographer->id,
        ]);

        $deliveries = [];
        $messagingService = Mockery::mock(MessagingService::class);
        $messagingService->shouldReceive('sendEmail')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$deliveries) {
                $deliveries[] = $payload;

                return new Message;
            });
        $this->app->instance(MessagingService::class, $messagingService);

        $result = app(MailService::class)->sendShootUpdatedEmail(
            $client,
            $shoot,
            'Shoot details updated',
            true,
            true
        );

        $this->assertTrue($result);
        $this->assertSame([$photographer->email], array_column($deliveries, 'to'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function shoot_requested_email_is_delivered_to_unverified_clients_as_a_transactional_message(): void
    {
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Requested Client',
            'email' => 'requested-client@test.com',
            'email_status' => 'unverified',
        ]);
        $service = Service::factory()->create([
            'name' => 'Request Service',
            'price' => 150.00,
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'scheduled_at' => now()->addDays(2)->setTime(11, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '11:00',
        ]);

        $shoot->services()->attach($service->id, [
            'price' => 150,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payloads = [];
        $messagingService = Mockery::mock(MessagingService::class);
        $messagingService->shouldReceive('sendEmail')
            ->atLeast()->once()
            ->andReturnUsing(function (array $payload) use (&$payloads) {
                $payloads[] = $payload;

                return new Message;
            });
        $this->app->instance(MessagingService::class, $messagingService);

        $result = app(MailService::class)->sendShootRequestedEmail($client, $shoot);

        $this->assertTrue($result);

        $clientPayload = collect($payloads)->firstWhere('to', $client->email);
        $this->assertNotNull($clientPayload, 'Expected shoot request confirmation to be sent to the client.');
        $this->assertSame('SHOOT_REQUESTED', $clientPayload['send_source'] ?? null);
        $this->assertSame($client->id, $clientPayload['related_account_id'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function shoot_requested_email_is_blocked_for_bounced_clients(): void
    {
        $this->createDefaultEmailChannel();

        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Bounced Client',
            'email' => 'bounced-client@test.com',
            'email_status' => 'bounced',
        ]);
        $service = Service::factory()->create([
            'name' => 'Request Service',
            'price' => 150.00,
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'scheduled_at' => now()->addDays(2)->setTime(11, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '11:00',
        ]);

        $shoot->services()->attach($service->id, [
            'price' => 150,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payloads = [];
        $messagingService = Mockery::mock(MessagingService::class);
        $messagingService->shouldReceive('sendEmail')
            ->andReturnUsing(function (array $payload) use (&$payloads) {
                $payloads[] = $payload;

                return new Message;
            });
        $this->app->instance(MessagingService::class, $messagingService);

        $result = app(MailService::class)->sendShootRequestedEmail($client, $shoot);

        $this->assertFalse($result);
        $this->assertNull(
            collect($payloads)->firstWhere('to', $client->email),
            'Bounced client emails must not be sent.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function terms_accepted_email_persists_client_account_context_without_blocking_unverified_clients(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Terms Client',
            'email' => 'terms-client@test.com',
            'email_status' => 'unverified',
        ]);

        $payloads = [];
        $messagingService = Mockery::mock(MessagingService::class);
        $messagingService->shouldReceive('sendEmail')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$payloads) {
                $payloads[] = $payload;

                return new Message;
            });
        $this->app->instance(MessagingService::class, $messagingService);

        $result = app(MailService::class)->sendTermsAcceptedEmail($client);

        $this->assertTrue($result);
        $this->assertCount(1, $payloads);
        $this->assertSame($client->id, $payloads[0]['related_account_id'] ?? null);
        $this->assertSame('TERMS_ACCEPTED', $payloads[0]['send_source'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function password_reset_email_persists_client_account_context_without_blocking_unverified_clients(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Reset Client',
            'email' => 'reset-client@test.com',
            'email_status' => 'unverified',
        ]);

        $payloads = [];
        $messagingService = Mockery::mock(MessagingService::class);
        $messagingService->shouldReceive('sendEmail')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$payloads) {
                $payloads[] = $payload;

                return new Message;
            });
        $this->app->instance(MessagingService::class, $messagingService);

        $result = app(MailService::class)->sendPasswordResetEmail($client, 'https://example.test/reset');

        $this->assertTrue($result);
        $this->assertCount(1, $payloads);
        $this->assertSame($client->id, $payloads[0]['related_account_id'] ?? null);
        $this->assertSame('PASSWORD_RESET', $payloads[0]['send_source'] ?? null);
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
