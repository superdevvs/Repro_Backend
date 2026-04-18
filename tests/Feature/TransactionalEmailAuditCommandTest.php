<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\ShootEmailDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TransactionalEmailAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_command_lists_missing_email_live_shoots_recent_failures_and_delivery_issues(): void
    {
        Log::spy();

        $missingEmailClient = User::factory()->create([
            'role' => 'client',
            'email' => ' ',
        ]);

        $liveShoot = $this->createShootForClient($missingEmailClient, [
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'address' => '100 Missing Email Ave',
        ]);

        $healthyClient = User::factory()->create([
            'role' => 'client',
            'email' => 'audit-client@test.com',
        ]);

        $failedShoot = $this->createShootForClient($healthyClient, [
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'address' => '200 Failed Email Ave',
        ]);

        Message::query()->create([
            'channel' => 'EMAIL',
            'direction' => 'OUTBOUND',
            'provider' => 'CAKEMAIL',
            'from_address' => 'contact@reprophotos.com',
            'to_address' => 'audit-client@test.com',
            'subject' => 'Audit Failure',
            'body_text' => 'Audit Failure',
            'body_html' => '<p>Audit Failure</p>',
            'status' => 'FAILED',
            'send_source' => 'SHOOT_REQUESTED',
            'related_shoot_id' => $failedShoot->id,
            'related_account_id' => $healthyClient->id,
            'error_message' => 'Cakemail base URL is not configured',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        ShootEmailDelivery::query()->create([
            'shoot_id' => $failedShoot->id,
            'recipient_user_id' => $healthyClient->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
            'status' => ShootEmailDelivery::STATUS_SKIPPED,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => ShootEmailDelivery::REASON_MISSING_EMAIL,
            'attempt_count' => 1,
            'last_attempted_at' => now()->subMinutes(30),
        ]);

        Artisan::call('messaging:audit-transactional-email', [
            '--hours' => 48,
            '--limit' => 20,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('kind=missing_email', $output);
        $this->assertStringContainsString('100 Missing Email Ave', $output);
        $this->assertStringContainsString('kind=message status=FAILED', $output);
        $this->assertStringContainsString('source=SHOOT_REQUESTED', $output);
        $this->assertStringContainsString('kind=delivery status=skipped', strtolower($output));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Transactional email audit found repeated provider/config failures.' && ($context['count'] ?? 0) >= 1)
            ->once();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $message === 'Transactional email audit found skipped sends caused by missing recipient data.' && ($context['live_shoots_with_missing_client_email'] ?? 0) >= 1)
            ->once();
    }

    private function createShootForClient(User $client, array $overrides = []): Shoot
    {
        $service = Service::factory()->create([
            'name' => 'Audit Service',
            'price' => 125.00,
        ]);

        $shoot = Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'service_id' => $service->id,
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
