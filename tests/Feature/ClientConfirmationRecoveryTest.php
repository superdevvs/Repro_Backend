<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootEmailDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientConfirmationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The replay test asserts a confirmation is actually re-sent and marked
        // recovered, so it needs the send pipeline rather than the guard's
        // blocked-by-default posture. The provider remains a fake.
        \App\Services\Messaging\OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
    }

    /** @test */
    public function admin_can_list_client_confirmation_recovery_candidates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client', 'email' => 'recovery-client@test.com']);
        $shoot = $this->createScheduledShoot($client);
        $delivery = ShootEmailDelivery::query()->create([
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_FAILED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_PROVIDER_ERROR,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
            'last_error_message' => 'SMTP unavailable',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/messaging/email/recovery/client-confirmations');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $delivery->id)
            ->assertJsonPath('data.0.shoot.id', $shoot->id)
            ->assertJsonPath('data.0.client.id', $client->id)
            ->assertJsonPath('data.0.status', ShootEmailDelivery::STATUS_FAILED);
    }

    /** @test */
    public function non_admin_users_cannot_list_or_replay_client_confirmation_recovery_candidates(): void
    {
        $clientUser = User::factory()->create(['role' => 'client', 'email' => 'client@test.com']);
        $delivery = ShootEmailDelivery::query()->create([
            'shoot_id' => $this->createScheduledShoot($clientUser)->id,
            'recipient_user_id' => $clientUser->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_FAILED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_PROVIDER_ERROR,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
        ]);

        Sanctum::actingAs($clientUser);

        $this->getJson('/api/messaging/email/recovery/client-confirmations')
            ->assertForbidden();

        $this->postJson('/api/messaging/email/recovery/client-confirmations/replay', [
            'delivery_ids' => [$delivery->id],
        ])->assertForbidden();
    }

    /** @test */
    public function replay_rejects_deliveries_with_invalid_email_or_ineligible_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $missingEmailClient = User::factory()->create(['role' => 'client', 'email' => '   ']);
        $missingEmailShoot = $this->createScheduledShoot($missingEmailClient);
        $missingEmailDelivery = ShootEmailDelivery::query()->create([
            'shoot_id' => $missingEmailShoot->id,
            'recipient_user_id' => $missingEmailClient->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SKIPPED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_MISSING_EMAIL,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
        ]);

        $deliveredClient = User::factory()->create(['role' => 'client', 'email' => 'delivered@test.com']);
        $deliveredShoot = $this->createScheduledShoot($deliveredClient, [
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
        ]);
        $deliveredDelivery = ShootEmailDelivery::query()->create([
            'shoot_id' => $deliveredShoot->id,
            'recipient_user_id' => $deliveredClient->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_FAILED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_PROVIDER_ERROR,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/email/recovery/client-confirmations/replay', [
            'delivery_ids' => [$missingEmailDelivery->id, $deliveredDelivery->id],
        ]);

        $response->assertOk()
            ->assertJsonCount(0, 'replayed')
            ->assertJsonCount(2, 'rejected');

        $missingEmailDelivery->refresh();
        $deliveredDelivery->refresh();

        $this->assertSame(1, $missingEmailDelivery->attempt_count);
        $this->assertSame(1, $deliveredDelivery->attempt_count);
        $this->assertDatabaseCount('messages', 0);
    }

    /** @test */
    public function replay_sends_client_confirmation_for_eligible_delivery_and_marks_it_recovered(): void
    {
        Mail::fake();
        $this->createDefaultEmailChannel();

        $admin = User::factory()->create(['role' => 'admin']);
        $client = User::factory()->create(['role' => 'client', 'email' => 'eligible-client@test.com']);
        $shoot = $this->createScheduledShoot($client);
        $delivery = ShootEmailDelivery::query()->create([
            'shoot_id' => $shoot->id,
            'recipient_user_id' => $client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_FAILED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_PROVIDER_ERROR,
            'attempt_count' => 1,
            'last_attempted_at' => now()->subHour(),
            'last_error_message' => 'Original provider error',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/messaging/email/recovery/client-confirmations/replay', [
            'delivery_ids' => [$delivery->id],
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'replayed')
            ->assertJsonCount(0, 'rejected')
            ->assertJsonPath('replayed.0.id', $delivery->id)
            ->assertJsonPath('replayed.0.status', ShootEmailDelivery::STATUS_SENT)
            ->assertJsonPath('replayed.0.source', ShootEmailDelivery::SOURCE_REPLAY);

        $delivery->refresh();

        $this->assertSame(ShootEmailDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(ShootEmailDelivery::SOURCE_REPLAY, $delivery->source);
        $this->assertNull($delivery->reason_code);
        $this->assertSame(2, $delivery->attempt_count);
        $this->assertNotNull($delivery->recovered_at);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNotNull($delivery->last_message_id);

        $message = Message::query()->find($delivery->last_message_id);
        $this->assertNotNull($message);
        $this->assertSame('SHOOT_SCHEDULED', $message->send_source);
        $this->assertSame('SENT', $message->status);
        $this->assertSame($shoot->id, $message->related_shoot_id);
        $this->assertSame(strtolower($client->email), strtolower((string) $message->to_address));
    }

    /** @test */
    public function audit_command_lists_failed_and_skipped_deliveries_only(): void
    {
        $client = User::factory()->create(['role' => 'client', 'email' => 'audit-client@test.com']);
        $failedShoot = $this->createScheduledShoot($client, ['address' => '100 Failed Way']);
        $skippedShoot = $this->createScheduledShoot($client, ['address' => '200 Skipped Way']);
        $sentShoot = $this->createScheduledShoot($client, ['address' => '300 Sent Way']);

        $failed = ShootEmailDelivery::query()->create([
            'shoot_id' => $failedShoot->id,
            'recipient_user_id' => $client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_FAILED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_PROVIDER_ERROR,
            'attempt_count' => 2,
            'last_attempted_at' => now(),
        ]);

        $skipped = ShootEmailDelivery::query()->create([
            'shoot_id' => $skippedShoot->id,
            'recipient_user_id' => $client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SKIPPED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_MISSING_EMAIL,
            'attempt_count' => 1,
            'last_attempted_at' => now(),
        ]);

        $sent = ShootEmailDelivery::query()->create([
            'shoot_id' => $sentShoot->id,
            'recipient_user_id' => $client->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_REPLAY,
            'attempt_count' => 2,
            'last_attempted_at' => now(),
            'sent_at' => now(),
            'recovered_at' => now(),
        ]);

        Artisan::call('messaging:audit-client-confirmations');
        $output = Artisan::output();

        $this->assertStringContainsString((string) $failed->id, $output);
        $this->assertStringContainsString((string) $skipped->id, $output);
        $this->assertStringNotContainsString((string) $sent->id, $output);
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createScheduledShoot(User $client, array $overrides = []): Shoot
    {
        $service = Service::factory()->create([
            'name' => 'Recovery Service',
            'price' => 125.00,
        ]);

        $shoot = Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'service_id' => $service->id,
            'address' => '500 Recovery Ave',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
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

        return $shoot->fresh(['client', 'services.category']);
    }
}
