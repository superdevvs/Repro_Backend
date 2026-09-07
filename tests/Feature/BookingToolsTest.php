<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\ShootMediaStorageService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\ReproAi\Tools\BookingTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class BookingToolsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function ai_booking_uses_booked_fallback_to_notify_client_and_photographer(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'AI Client',
            'email' => 'ai-client@test.com',
        ]);

        $photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'AI Photographer',
            'email' => 'ai-photographer@test.com',
        ]);

        $service = Service::factory()->create([
            'name' => 'AI Booking Service',
            'price' => 180.00,
        ]);

        $this->actingAs($client);

        $dropboxService = Mockery::mock(ShootMediaStorageService::class);
        $dropboxService->shouldIgnoreMissing();
        $dropboxService->shouldReceive('createShootFolders')->once()->andReturnNull();
        $this->app->instance(ShootMediaStorageService::class, $dropboxService);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('generatePaymentLink')->once()->andReturn('https://example.test/payment');
        $mailService->shouldReceive('sendShootScheduledEmail')
            ->once()
            ->withArgs(function (User $recipient, Shoot $shoot, string $paymentLink, ?bool $notifyPhotographer = null) use ($client, $photographer) {
                return $recipient->is($client)
                    && $shoot->client_id === $client->id
                    && $shoot->photographer_id === $photographer->id
                    && $paymentLink === 'https://example.test/payment'
                    && $notifyPhotographer === false;
            })
            ->andReturnTrue();
        $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        $this->app->instance(MailService::class, $mailService);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->once()->andReturnUsing(
            fn (Shoot $shoot) => [
                'shoot' => $shoot,
                'shoot_id' => $shoot->id,
                'client' => $shoot->client,
                'photographer' => $shoot->photographer,
                'photographers' => $shoot->photographer ? [$shoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_BOOKED')
            ->andReturn([
                'trigger_type' => 'SHOOT_BOOKED',
                'active_rule_count' => 1,
                'run_count' => 1,
                'completed_run_count' => 0,
                'waiting_run_count' => 0,
                'failed_run_count' => 1,
                'handled' => false,
                'errors' => [
                    ['automation_id' => 2, 'message' => 'Failed to authenticate with Cakemail API'],
                ],
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_BOOKED', Mockery::type('array'))
            ->andReturnTrue();
        $this->app->instance(AutomationService::class, $automationService);

        $result = app(BookingTools::class)->bookShoot([
            'address' => '900 AI Booking Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [$service->id],
            'photographer_id' => $photographer->id,
            'date' => now()->addDays(2)->toDateString(),
            'time' => '13:00',
        ], [
            'user_id' => $client->id,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('scheduled', $result['status']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ai_booking_still_uses_booked_fallback_when_automation_is_handled_without_sending_client_email(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'AI Client',
            'email' => 'ai-client-2@test.com',
        ]);

        $photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'AI Photographer',
            'email' => 'ai-photographer-2@test.com',
        ]);

        $service = Service::factory()->create([
            'name' => 'AI Booking Service',
            'price' => 180.00,
        ]);

        $this->actingAs($client);

        $dropboxService = Mockery::mock(ShootMediaStorageService::class);
        $dropboxService->shouldIgnoreMissing();
        $dropboxService->shouldReceive('createShootFolders')->once()->andReturnNull();
        $this->app->instance(ShootMediaStorageService::class, $dropboxService);

        $mailService = Mockery::mock(MailService::class);
        $mailService->shouldIgnoreMissing();
        $mailService->shouldReceive('generatePaymentLink')->once()->andReturn('https://example.test/payment');
        $mailService->shouldReceive('sendShootScheduledEmail')
            ->once()
            ->withArgs(function (User $recipient, Shoot $shoot, string $paymentLink, ?bool $notifyPhotographer = null) use ($client, $photographer) {
                return $recipient->is($client)
                    && $shoot->client_id === $client->id
                    && $shoot->photographer_id === $photographer->id
                    && $paymentLink === 'https://example.test/payment'
                    && $notifyPhotographer === false;
            })
            ->andReturnTrue();
        $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->once()->andReturnTrue();
        $this->app->instance(MailService::class, $mailService);

        $automationService = Mockery::mock(AutomationService::class);
        $automationService->shouldIgnoreMissing();
        $automationService->shouldReceive('buildShootContext')->once()->andReturnUsing(
            fn (Shoot $shoot) => [
                'shoot' => $shoot,
                'shoot_id' => $shoot->id,
                'client' => $shoot->client,
                'photographer' => $shoot->photographer,
                'photographers' => $shoot->photographer ? [$shoot->photographer] : [],
            ]
        );
        $automationService->shouldReceive('handleEvent')
            ->once()
            ->withArgs(fn (string $triggerType) => $triggerType === 'SHOOT_BOOKED')
            ->andReturn([
                'trigger_type' => 'SHOOT_BOOKED',
                'active_rule_count' => 1,
                'run_count' => 1,
                'completed_run_count' => 1,
                'waiting_run_count' => 0,
                'failed_run_count' => 0,
                'handled' => true,
                'errors' => [],
                'email_sent_to' => ['ops@test.com'],
                'client_email_sent' => false,
                'photographer_email_sent' => false,
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_BOOKED', Mockery::type('array'))
            ->andReturnFalse();
        $this->app->instance(AutomationService::class, $automationService);

        $result = app(BookingTools::class)->bookShoot([
            'address' => '901 AI Booking Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [$service->id],
            'photographer_id' => $photographer->id,
            'date' => now()->addDays(2)->toDateString(),
            'time' => '13:00',
        ], [
            'user_id' => $client->id,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('scheduled', $result['status']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ai_booking_returns_a_validation_style_error_when_client_has_no_primary_email(): void
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'AI Client Missing Email',
            'email' => ' ',
        ]);

        $service = Service::factory()->create([
            'name' => 'AI Guarded Service',
            'price' => 180.00,
        ]);

        $this->actingAs($client);

        $result = app(BookingTools::class)->bookShoot([
            'address' => '902 AI Booking Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [$service->id],
            'date' => now()->addDays(2)->toDateString(),
            'time' => '13:00',
        ], [
            'user_id' => $client->id,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status_code']);
        $this->assertSame(
            'Selected client must have a primary email before booking a shoot.',
            $result['error']
        );
    }
}
