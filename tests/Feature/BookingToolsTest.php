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

    #[\PHPUnit\Framework\Attributes\DataProvider('clientRuleStates')]
    public function test_ai_date_time_booking_uses_the_saved_client_confirmation_and_honors_pause(bool $active): void
    {
        $this->seed(\Database\Seeders\MessagingSystemSeeder::class);
        \App\Services\Messaging\OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        \App\Models\MessageChannel::create(['type' => 'EMAIL', 'provider' => 'LOCAL_SMTP', 'display_name' => 'AI fixture sender',
            'from_email' => 'sender@example.test', 'owner_scope' => 'GLOBAL', 'is_active' => true]);
        $rule = \App\Models\AutomationRule::where('trigger_type', 'SHOOT_SCHEDULED')->firstOrFail();
        $rule->update(['is_active' => $active]);
        $rule->template->update(['subject' => 'Saved AI booking confirmation', 'body_html' => '<p>Saved booking at {{shoot_location}}</p>']);
        $client = User::factory()->create(['role' => 'client']);
        $photographer = User::factory()->photographer()->create();
        $service = Service::factory()->create(['price' => 180]);
        $this->actingAs($client);
        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('createShootFolders')->once();
        $this->app->instance(ShootMediaStorageService::class, $storage);

        $result = app(BookingTools::class)->bookShoot([
            'address' => '100 AI Date Time Ave', 'city' => 'Baltimore', 'state' => 'MD', 'zip' => '21201',
            'services' => [$service->id], 'photographer_id' => $photographer->id,
            'date' => now()->addDays(2)->toDateString(), 'time' => '13:00',
        ], ['user_id' => $client->id]);

        $this->assertTrue($result['success']);
        $shoot = Shoot::findOrFail($result['shoot_id']);
        $this->assertNull($shoot->scheduled_at, 'The AI tool uses the supported date/time fields.');
        $messages = \App\Models\Message::where('related_shoot_id', $shoot->id)->where('status', 'SENT')->get();
        $this->assertCount($active ? 2 : 1, $messages);
        $clientMessages = $messages->where('to_address', $client->email);
        $this->assertCount($active ? 1 : 0, $clientMessages);
        $this->assertCount(1, $messages->where('to_address', $photographer->email));
        if ($active) {
            $this->assertSame('Saved AI booking confirmation', $clientMessages->first()->subject);
            $this->assertStringContainsString('100 AI Date Time Ave', $clientMessages->first()->body_html);
        }
        $this->assertSame(0, \App\Models\SystemEmailDispatch::count(), 'Saved rules remain authoritative, including a paused rule.');
    }

    public static function clientRuleStates(): array
    {
        return ['active client confirmation' => [true], 'paused client confirmation' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ai_booking_without_saved_rules_uses_fallback_to_notify_client_and_photographer(): void
    {
        \App\Models\AutomationRule::whereIn('trigger_type', ['SHOOT_BOOKED', 'SHOOT_SCHEDULED'])->delete();
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
                'active_rule_count' => 0,
                'run_count' => 0,
                'completed_run_count' => 0,
                'waiting_run_count' => 0,
                'failed_run_count' => 0,
                'handled' => false,
                'errors' => [],
            ]);
        $automationService->shouldReceive('shouldUseFallback')
            ->once()
            ->with('SHOOT_BOOKED', Mockery::type('array'))
            ->andReturnTrue();
        $automationService->shouldReceive('handleEvent')->once()->with('SHOOT_SCHEDULED', Mockery::type('array'))
            ->andReturn(['client_email_sent' => false, 'active_rule_count' => 0]);
        $automationService->shouldReceive('shouldUseFallback')->once()->with('SHOOT_SCHEDULED', Mockery::type('array'))
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
    public function ai_booking_honors_saved_recipient_selection_without_direct_fallback(): void
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
        $mailService->shouldReceive('generatePaymentLink')->never();
        $mailService->shouldReceive('sendShootScheduledEmail')->never();
        $mailService->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->never();
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
        $automationService->shouldReceive('handleEvent')->once()->with('SHOOT_SCHEDULED', Mockery::type('array'))
            ->andReturn(['client_email_sent' => false, 'active_rule_count' => 1]);
        $automationService->shouldReceive('shouldUseFallback')->once()->with('SHOOT_SCHEDULED', Mockery::type('array'))
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
        $this->assertDatabaseCount('shoot_email_deliveries', 0);
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
