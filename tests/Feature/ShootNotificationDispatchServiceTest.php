<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\ClientConfirmationRecoveryService;
use App\Services\Shoots\ShootNotificationDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ShootNotificationDispatchServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_external_requested_notifications_do_not_fall_back_when_automation_delivers_client_email(): void
    {
        $shoot = $this->createRequestedShoot('client-delivered@test.com');

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldReceive('buildShootContext')
            ->once()
            ->with(Mockery::type(Shoot::class))
            ->andReturn(['shoot_id' => $shoot->id, 'client' => $shoot->client]);
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->with('SHOOT_REQUESTED', Mockery::type('array'))
            ->andReturn([
                'active_rule_count' => 1,
                'handled' => true,
                'client_email_sent' => true,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_REQUESTED', Mockery::type('array'))
            ->andReturnFalse();

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldReceive('sendShootRequestedEmail')->never();
        $mailService->shouldReceive('sendShootRequestedAdminNotificationEmails')->never();

        $recoveryService = Mockery::mock(ClientConfirmationRecoveryService::class);
        $dropbox = Mockery::mock(ShootMediaStorageService::class);

        $service = new ShootNotificationDispatchService(
            $automationService,
            $recoveryService,
            $dropbox,
            $mailService
        );

        $service->processExternalShootRequested($shoot->id);
    }

    public function test_external_requested_notifications_fall_back_to_client_when_automation_misses_client_email(): void
    {
        $shoot = $this->createRequestedShoot('client-fallback@test.com');

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldReceive('buildShootContext')
            ->once()
            ->andReturn(['shoot_id' => $shoot->id, 'client' => $shoot->client]);
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->andReturn([
                'active_rule_count' => 1,
                'handled' => true,
                'client_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->andReturnFalse();

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldReceive('sendShootRequestedEmail')
            ->once()
            ->withArgs(fn (User $recipient, Shoot $targetShoot) => $recipient->is($shoot->client) && $targetShoot->is($shoot))
            ->andReturnTrue();
        $mailService->shouldReceive('sendShootRequestedAdminNotificationEmails')->never();

        $recoveryService = Mockery::mock(ClientConfirmationRecoveryService::class);
        $recoveryService->shouldReceive('hasDeliverableEmail')
            ->once()
            ->with(Mockery::on(fn (User $recipient) => $recipient->is($shoot->client)))
            ->andReturnTrue();

        $dropbox = Mockery::mock(ShootMediaStorageService::class);

        $service = new ShootNotificationDispatchService(
            $automationService,
            $recoveryService,
            $dropbox,
            $mailService
        );

        $service->processExternalShootRequested($shoot->id);
    }

    public function test_external_requested_notifications_send_admin_fallback_when_no_automation_handles_the_request(): void
    {
        $shoot = $this->createRequestedShoot('client-no-automation@test.com');

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldReceive('buildShootContext')
            ->once()
            ->andReturn(['shoot_id' => $shoot->id, 'client' => $shoot->client]);
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->andReturn([
                'active_rule_count' => 0,
                'handled' => false,
                'client_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->andReturnTrue();

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldReceive('sendShootRequestedEmail')->once()->andReturnTrue();
        $mailService->shouldReceive('sendShootRequestedAdminNotificationEmails')
            ->once()
            ->with(Mockery::on(fn (Shoot $targetShoot) => $targetShoot->is($shoot)))
            ->andReturnTrue();

        $recoveryService = Mockery::mock(ClientConfirmationRecoveryService::class);
        $recoveryService->shouldReceive('hasDeliverableEmail')->once()->andReturnTrue();

        $dropbox = Mockery::mock(ShootMediaStorageService::class);

        $service = new ShootNotificationDispatchService(
            $automationService,
            $recoveryService,
            $dropbox,
            $mailService
        );

        $service->processExternalShootRequested($shoot->id);
    }

    public function test_external_requested_notifications_skip_client_fallback_and_log_warning_when_client_email_is_missing(): void
    {
        Log::spy();

        $shoot = $this->createRequestedShoot(' ');

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldReceive('buildShootContext')
            ->once()
            ->andReturn(['shoot_id' => $shoot->id, 'client' => $shoot->client]);
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->andReturn([
                'active_rule_count' => 0,
                'handled' => false,
                'client_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->andReturnTrue();

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldReceive('sendShootRequestedEmail')->never();
        $mailService->shouldReceive('sendShootRequestedAdminNotificationEmails')->once()->andReturnTrue();

        $recoveryService = Mockery::mock(ClientConfirmationRecoveryService::class);
        $recoveryService->shouldReceive('hasDeliverableEmail')->once()->andReturnFalse();

        $dropbox = Mockery::mock(ShootMediaStorageService::class);

        $service = new ShootNotificationDispatchService(
            $automationService,
            $recoveryService,
            $dropbox,
            $mailService
        );

        $service->processExternalShootRequested($shoot->id);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Skipping external shoot requested fallback because recipient data is incomplete.'
                    && ($context['reason'] ?? null) === 'missing_client_email'
                    && ($context['recipient_type'] ?? null) === 'client';
            });
    }

    private function createRequestedShoot(string $clientEmail): Shoot
    {
        $client = User::factory()->create([
            'role' => 'client',
            'email' => $clientEmail,
        ]);

        $service = Service::factory()->create([
            'name' => 'Requested Shoot Service',
            'price' => 185.00,
        ]);

        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'address' => '500 Request Way',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
        ]);

        $shoot->services()->attach($service->id, [
            'price' => 185,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shoot->fresh(['client', 'services']);
    }
}
