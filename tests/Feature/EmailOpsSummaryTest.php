<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootEmailDelivery;
use App\Models\User;
use App\Services\Messaging\Providers\CakemailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class EmailOpsSummaryTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_admin_can_load_email_ops_summary_with_counts_and_samples(): void
    {
        $this->createDefaultCakeMailChannel();
        $this->mockHealthyProvider();

        $missingEmailClient = User::factory()->create([
            'role' => 'client',
            'email' => ' ',
        ]);
        $missingEmailShoot = $this->createShootForClient($missingEmailClient, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $healthyClient = User::factory()->create([
            'role' => 'client',
            'email' => 'ops-summary-client@example.com',
        ]);
        $failedShoot = $this->createShootForClient($healthyClient, [
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
        ]);
        $skippedShoot = $this->createShootForClient($healthyClient, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);

        $failedMessage = Message::query()->create([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => 'CAKEMAIL',
            'from_address' => 'contact@reprophotos.com',
            'to_address' => $healthyClient->email,
            'subject' => 'Failed Ops Summary',
            'body_text' => 'Failed Ops Summary',
            'body_html' => '<p>Failed Ops Summary</p>',
            'status' => 'FAILED',
            'send_source' => 'SHOOT_REQUESTED',
            'related_shoot_id' => $failedShoot->id,
            'related_account_id' => $healthyClient->id,
            'error_message' => 'Provider failure',
            'failed_at' => now()->subMinutes(15),
        ]);

        $queuedMessage = Message::query()->create([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => 'CAKEMAIL',
            'from_address' => 'contact@reprophotos.com',
            'to_address' => 'queued@example.com',
            'subject' => 'Queued Ops Summary',
            'body_text' => 'Queued Ops Summary',
            'body_html' => '<p>Queued Ops Summary</p>',
            'status' => 'QUEUED',
            'send_source' => 'SHOOT_SCHEDULED',
            'related_shoot_id' => $failedShoot->id,
            'related_account_id' => $healthyClient->id,
        ]);
        $queuedMessage->forceFill([
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ])->save();

        $failedDelivery = ShootEmailDelivery::query()->create([
            'shoot_id' => $failedShoot->id,
            'recipient_user_id' => $healthyClient->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_FAILED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_PROVIDER_ERROR,
            'attempt_count' => 1,
            'last_attempted_at' => now()->subMinutes(12),
            'last_error_message' => 'SMTP unavailable',
        ]);

        $skippedDelivery = ShootEmailDelivery::query()->create([
            'shoot_id' => $skippedShoot->id,
            'recipient_user_id' => $healthyClient->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SKIPPED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_MISSING_EMAIL,
            'attempt_count' => 1,
            'last_attempted_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'email' => 'ops-admin@example.com',
        ]));

        $this->getJson('/api/messaging/email/ops-summary?sample=2&queued_minutes=5')
            ->assertOk()
            ->assertJsonPath('health.healthy', true)
            ->assertJsonPath('counts.live_shoots_blocked_by_missing_client_email', 1)
            ->assertJsonPath('counts.failed_outbound_messages', 1)
            ->assertJsonPath('counts.queued_outbound_messages_beyond_retry_threshold', 1)
            ->assertJsonPath('counts.failed_client_confirmations', 1)
            ->assertJsonPath('counts.skipped_client_confirmations', 1)
            ->assertJsonPath('blocking_issues_present', true)
            ->assertJsonPath('samples.live_shoots_missing_client_email.0.shoot_id', $missingEmailShoot->id)
            ->assertJsonPath('samples.failed_messages.0.message_id', $failedMessage->id)
            ->assertJsonPath('samples.queued_messages.0.message_id', $queuedMessage->id)
            ->assertJsonPath('samples.failed_client_confirmations.0.delivery_id', $failedDelivery->id)
            ->assertJsonPath('samples.skipped_client_confirmations.0.delivery_id', $skippedDelivery->id);
    }

    public function test_non_admin_cannot_load_email_ops_summary(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'client',
            'email' => 'ops-client@example.com',
        ]));

        $this->getJson('/api/messaging/email/ops-summary')
            ->assertForbidden();
    }

    private function createDefaultCakeMailChannel(): MessageChannel
    {
        return MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'CAKEMAIL',
            'display_name' => 'Cakemail Default',
            'from_email' => 'contact@reprophotos.com',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);
    }

    private function mockHealthyProvider(): void
    {
        $provider = Mockery::mock(CakemailProvider::class);
        $provider->shouldReceive('testConnection')
            ->andReturn([
                'success' => true,
                'account' => ['name' => 'Ops Account'],
                'senders' => [['id' => 'sender-1']],
                'lists' => [['id' => 1]],
            ]);
        $this->app->instance(CakemailProvider::class, $provider);
    }

    private function createShootForClient(User $client, array $overrides = []): Shoot
    {
        $service = Service::factory()->create([
            'name' => 'Ops Service',
            'price' => 125.00,
        ]);

        $shoot = Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '500 Ops Summary Ave',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'scheduled_at' => now()->addDays(2)->setTime(11, 0),
            'scheduled_date' => now()->addDays(2)->toDateString(),
            'time' => '11:00',
        ], $overrides));

        $shoot->services()->attach($service->id, [
            'price' => $service->price,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shoot->fresh(['client', 'services']);
    }
}
