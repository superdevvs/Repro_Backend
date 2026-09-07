<?php

namespace Tests\Feature;

use App\Exceptions\Messaging\EmailProviderRejectedException;
use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\SystemEmailDispatch;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\ShootMediaStorageService;
use App\Services\Shoots\ShootNotificationDispatchService;
use App\Services\SystemEmails\EmailAuditService;
use App\Services\SystemEmails\EmailTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhotographerScheduledEmailDeliveryTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    #[DataProvider('providerFailures')]
    public function test_booking_retries_only_explicit_rejections_and_preserves_successful_deliveries(bool $retryable): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $lead = User::factory()->photographer()->create();
        $second = User::factory()->photographer()->create();
        $shoot = Shoot::factory()->create([
            'client_id' => $client->id,
            'photographer_id' => $lead->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDays(2)->setTime(10, 0),
        ]);
        foreach ([$lead, $second] as $photographer) {
            $shoot->services()->attach(Service::factory()->create()->id, [
                'price' => 150,
                'quantity' => 1,
                'photographer_id' => $photographer->id,
            ]);
        }

        $template = MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Booked photographer notification',
            'subject' => 'New Shoot Scheduled',
            'body_html' => '<p>Your shoot is scheduled.</p>',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);
        AutomationRule::create([
            'name' => 'Notify booked photographers',
            'trigger_type' => 'SHOOT_BOOKED',
            'template_id' => $template->id,
            'is_active' => true,
            'scope' => 'GLOBAL',
            'recipients_json' => ['photographer'],
        ]);

        $attempts = [];
        $messaging = Mockery::mock(MessagingService::class);
        $messaging->shouldReceive('sendEmail')->times($retryable ? 4 : 3)->andReturnUsing(function (array $payload) use (&$attempts, $lead, $retryable): Message {
            $email = $payload['to'];
            $attempts[$email] = ($attempts[$email] ?? 0) + 1;
            if ($email === $lead->email && $attempts[$email] === 1) {
                throw $retryable
                    ? new EmailProviderRejectedException('Provider rejected request with HTTP 429')
                    : new \RuntimeException('Provider outcome unknown after timeout');
            }

            return new Message(['status' => 'SENT']);
        });
        $this->app->instance(MessagingService::class, $messaging);
        $storage = Mockery::mock(ShootMediaStorageService::class);
        $storage->shouldReceive('createShootFolders')->once();
        $this->app->instance(ShootMediaStorageService::class, $storage);

        app(ShootNotificationDispatchService::class)->processCreatedShoot($shoot->id, false, true);

        $this->assertSame($retryable ? 2 : 1, $attempts[$lead->email] ?? 0, 'Only an explicit rejection may be retried.');
        $this->assertSame(1, $attempts[$second->email] ?? 0, 'The successful photographer is deduplicated during fallback.');
        $this->assertSame(1, $attempts[$client->email] ?? 0);
        $this->assertSame($retryable ? 3 : 2, SystemEmailDispatch::query()->where('email_alias', 'SHOOT_SCHEDULED')->where('status', 'sent')->count());
        $leadDispatch = SystemEmailDispatch::query()->where('recipient_email', $lead->email)->firstOrFail();
        $this->assertSame($retryable ? 2 : 1, $leadDispatch->attempt_count);
        $this->assertSame($retryable ? 'sent' : 'failed', $leadDispatch->status);
        $this->assertSame($lead->email, $leadDispatch->payload_snapshot['recipient']['email']);
        $this->assertSame($lead->email, $leadDispatch->transport_snapshot['to']);
    }

    public static function providerFailures(): array
    {
        return ['explicit rejection' => [true], 'ambiguous timeout' => [false]];
    }

    #[DataProvider('changedFailures')]
    public function test_stale_retry_claim_cannot_overwrite_a_changed_failure(int $newAttemptCount, ?string $newErrorCode): void
    {
        $audit = app(EmailAuditService::class);
        $definition = app(EmailTypeRegistry::class)->definition('SHOOT_SCHEDULED');
        $payload = ['recipient' => ['email' => 'photographer@example.test']];
        $transport = ['to' => 'photographer@example.test', 'contact_type' => 'photographer'];
        $options = [
            'idempotency_key' => 'stale-photographer-retry',
            'retry_failed' => true,
            'retry_failed_error_codes' => ['EmailProviderRejectedException'],
        ];
        $dispatch = $audit->begin($definition, $payload, $transport, $options)['dispatch'];
        $dispatch->update(['status' => 'failed', 'error_code' => 'EmailProviderRejectedException']);

        // Change the persisted outcome immediately after the stale caller reads
        // the rejection, simulating another sender completing an attempt.
        $originalEvents = SystemEmailDispatch::getEventDispatcher();
        $isolatedEvents = clone $originalEvents;
        SystemEmailDispatch::setEventDispatcher($isolatedEvents);
        $changed = false;
        $isolatedEvents->listen('eloquent.retrieved: ' . SystemEmailDispatch::class, function (SystemEmailDispatch $retrieved) use ($dispatch, &$changed, $newAttemptCount, $newErrorCode): void {
            if ($changed || $retrieved->id !== $dispatch->id) {
                return;
            }
            $changed = true;
            DB::table('system_email_dispatches')->where('id', $dispatch->id)->update([
                'status' => 'failed',
                'attempt_count' => $newAttemptCount,
                'error_code' => $newErrorCode,
                'error_message' => 'Newer provider outcome',
            ]);
        });

        try {
            $result = $audit->begin($definition, $payload, $transport, $options);
        } finally {
            SystemEmailDispatch::setEventDispatcher($originalEvents);
        }

        $this->assertTrue($changed);
        $this->assertTrue($result['duplicate'], 'A stale caller must not claim a changed failure for resend.');
        $this->assertSame('failed', $result['dispatch']->status);
        $this->assertSame($newAttemptCount, $result['dispatch']->attempt_count);
        $this->assertSame($newErrorCode, $result['dispatch']->error_code);
        $this->assertSame('Newer provider outcome', $result['dispatch']->error_message);
    }

    public static function changedFailures(): array
    {
        return [
            'newer ambiguous attempt' => [2, 'ConnectionException'],
            'outcome changed without attempt increment' => [1, 'ConnectionException'],
            'newer explicitly rejected attempt' => [2, 'EmailProviderRejectedException'],
            'outcome became unknown' => [1, null],
        ];
    }
}
