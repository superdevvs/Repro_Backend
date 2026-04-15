<?php

namespace Tests\Feature;

use App\Models\Message;
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

    /** @test */
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

                return new Message();
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
}
