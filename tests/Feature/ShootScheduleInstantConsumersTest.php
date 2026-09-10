<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Shoots\Actions\RequestCancellationAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ShootScheduleInstantConsumersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function reminderTimes(): array
    {
        return [
            'summer legacy at 24h' => ['2026-09-08 14:00:00', '2026-09-09 10:00:00', null, null, '2026-09-09T14:00:00+00:00', true],
            'legacy is not four hours early' => ['2026-09-08 10:00:00', '2026-09-09 10:00:00', null, null, '2026-09-09T14:00:00+00:00', false],
            'inclusive five-minute boundary' => ['2026-09-08 13:55:00', '2026-09-09 10:00:00', null, null, '2026-09-09T14:00:00+00:00', true],
            'outside five-minute boundary' => ['2026-09-08 13:54:59', '2026-09-09 10:00:00', null, null, '2026-09-09T14:00:00+00:00', false],
            'UTC date crossing' => ['2026-09-09 03:30:00', '2026-09-09 23:30:00', null, null, '2026-09-10T03:30:00+00:00', true],
            'explicit absolute timestamp' => ['2026-09-08 14:00:00', '2026-09-09 14:00:00', 'America/New_York', null, '2026-09-09T14:00:00+00:00', true],
            'spring DST day' => ['2026-03-07 07:30:00', '2026-03-08 03:30:00', null, null, '2026-03-08T07:30:00+00:00', true],
            'autumn DST day' => ['2026-10-31 08:30:00', '2026-11-01 03:30:00', null, null, '2026-11-01T08:30:00+00:00', true],
            'service photographer override' => ['2026-09-08 17:00:00', '2026-09-09 10:00:00', null, 'America/Los_Angeles', '2026-09-09T17:00:00+00:00', true],
            'service override is not four hours early' => ['2026-09-08 10:00:00', '2026-09-09 10:00:00', null, 'America/Los_Angeles', '2026-09-09T17:00:00+00:00', false],
        ];
    }

    #[DataProvider('reminderTimes')]
    public function test_reminder_sweep_uses_actual_appointment_instant(
        string $now, string $storedAt, ?string $shootTimezone, ?string $serviceTimezone, string $expectedUtc, bool $due,
    ): void {
        Carbon::setTestNow(Carbon::parse($now, 'UTC'));
        $shoot = $this->shoot($storedAt, $shootTimezone);
        if ($serviceTimezone) {
            $operator = User::factory()->photographer()->create(['timezone' => $serviceTimezone]);
            $this->attachService($shoot, $storedAt, $operator);
        }
        AutomationRule::query()->update(['is_active' => false]);
        $before = $shoot->getRawOriginal('scheduled_at');
        $mail = Mockery::mock(MailService::class);
        if ($due) {
            $mail->shouldReceive('sendShootReminderEmail')->twice()->withArgs(
                function (User $recipient, Shoot $target, Carbon $scheduledAt, array $tags) use ($shoot, $expectedUtc) {
                    return $target->id === $shoot->id
                        && $scheduledAt->copy()->utc()->toIso8601String() === $expectedUtc
                        && count($tags) === 1
                        && str_ends_with($tags[0], ':'.$expectedUtc);
                }
            )->andReturnTrue();
        } else {
            $mail->shouldNotReceive('sendShootReminderEmail');
        }
        $this->app->instance(MailService::class, $mail);

        app(AutomationService::class)->triggerShootReminders();

        $this->assertSame($before, $shoot->fresh()->getRawOriginal('scheduled_at'));
    }

    public static function cancellationTimes(): array
    {
        return [
            'four-hour boundary' => ['2026-09-09 10:00:00', '2026-09-09 10:00:00', null, true],
            'just before fee window' => ['2026-09-09 09:59:59', '2026-09-09 10:00:00', null, false],
            'at appointment' => ['2026-09-09 14:00:00', '2026-09-09 10:00:00', null, true],
            'appointment passed' => ['2026-09-09 14:00:01', '2026-09-09 10:00:00', null, false],
            'winter offset' => ['2026-01-15 11:00:00', '2026-01-15 10:00:00', null, true],
            'explicit instant unchanged' => ['2026-09-09 10:00:00', '2026-09-09 14:00:00', 'America/New_York', true],
        ];
    }

    #[DataProvider('cancellationTimes')]
    public function test_cancellation_notice_and_payout_use_same_four_hour_window(
        string $now, string $storedAt, ?string $shootTimezone, bool $eligible,
    ): void {
        Carbon::setTestNow(Carbon::parse($now, 'UTC'));
        $shoot = $this->shoot($storedAt, $shootTimezone);
        $this->attachService($shoot, $storedAt, $shoot->photographer);
        $action = app(RequestCancellationAction::class);
        $invoiceService = app(InvoiceService::class);

        $notice = (new ReflectionMethod($action, 'isWithinCancellationFeeWindow'))->invoke($action, $shoot);
        $ids = (new ReflectionMethod($invoiceService, 'eligibleCancellationPayoutPhotographerIds'))->invoke($invoiceService, $shoot);

        $this->assertSame($eligible, $notice);
        $this->assertSame($eligible ? [$shoot->photographer_id] : [], $ids->all());

        Sanctum::actingAs(User::factory()->admin()->create());
        $response = $this->getJson('/api/shoots/'.$shoot->id)->assertOk();
        $expectedInstant = $shootTimezone !== null
            ? Carbon::parse($storedAt, 'UTC')->toIso8601String()
            : Carbon::parse($storedAt, 'America/New_York')->utc()->toIso8601String();
        $response->assertJsonPath('data.cancellation_fee_window', $eligible)
            ->assertJsonPath('data.cancellationFeeWindow', $eligible)
            ->assertJsonPath('data.scheduled_instant', $expectedInstant)
            ->assertJsonPath('data.scheduledInstant', $expectedInstant)
            ->assertJsonPath('data.schedule_timezone', 'America/New_York')
            ->assertJsonPath('data.scheduleTimezone', 'America/New_York');
    }

    public function test_service_payout_uses_its_assigned_photographer_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09 16:00:00', 'UTC'));
        $shoot = $this->shoot('2026-09-09 10:00:00');
        $operator = User::factory()->photographer()->create(['timezone' => 'America/Los_Angeles']);
        $this->attachService($shoot, '2026-09-09 10:00:00', $operator);
        $service = app(InvoiceService::class);

        $ids = (new ReflectionMethod($service, 'eligibleCancellationPayoutPhotographerIds'))->invoke($service, $shoot);

        $this->assertSame([$operator->id], $ids->all());
    }

    public static function scheduleStorageConventions(): array
    {
        return [
            'legacy local clock' => ['2026-09-09 10:00:00', null],
            'explicit instant' => ['2026-09-09 14:00:00', 'America/New_York'],
        ];
    }

    #[DataProvider('scheduleStorageConventions')]
    public function test_dashboard_summary_exposes_true_instant_without_changing_start_time(
        string $storedAt, ?string $timezone,
    ): void {
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-09-09 08:00:00', 'UTC'));
        $shoot = $this->shoot($storedAt, $timezone);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.upcoming_shoots.0.id', $shoot->id)
            ->assertJsonPath('data.upcoming_shoots.0.scheduled_instant', '2026-09-09T14:00:00+00:00')
            ->assertJsonPath('data.upcoming_shoots.0.start_time', '2026-09-09T10:00:00+00:00');

        $this->getJson('/api/shoots?tab=scheduled&no_cache=true')->assertOk()
            ->assertJsonPath('data.0.id', $shoot->id)
            ->assertJsonPath('data.0.scheduled_instant', '2026-09-09T14:00:00+00:00')
            ->assertJsonPath('data.0.schedule_timezone', 'America/New_York');
    }

    #[DataProvider('scheduleStorageConventions')]
    public function test_photographer_phone_redaction_tracks_actual_instant_at_window_boundaries(
        string $storedAt, ?string $timezone,
    ): void {
        $shoot = $this->shoot($storedAt, $timezone);
        $shoot->client->update(['phone' => '202-555-0100', 'phonenumber' => '202-555-0100']);
        Sanctum::actingAs($shoot->photographer);

        foreach ([
            '2026-09-09 11:59:59' => null,
            '2026-09-09 12:00:00' => '202-555-0100',
            '2026-09-09 17:00:00' => '202-555-0100',
            '2026-09-09 17:00:01' => null,
        ] as $now => $expectedPhone) {
            Carbon::setTestNow(Carbon::parse($now, 'UTC'));
            $this->getJson('/api/shoots/'.$shoot->id)->assertOk()
                ->assertJsonPath('data.client.phone', $expectedPhone)
                ->assertJsonPath('data.client.name', $shoot->client->name)
                ->assertJsonPath('data.scheduled_instant', '2026-09-09T14:00:00+00:00');
        }

        Sanctum::actingAs($shoot->client);
        $this->getJson('/api/shoots/'.$shoot->id)->assertOk()
            ->assertJsonPath('data.scheduled_instant', '2026-09-09T14:00:00+00:00')
            ->assertJsonPath('data.schedule_timezone', 'America/New_York')
            ->assertJsonPath('data.cancellation_fee_window', false);
    }

    private function shoot(string $storedAt, ?string $timezone = null): Shoot
    {
        $photographer = User::factory()->photographer()->create(['timezone' => 'America/New_York']);

        return Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'scheduled_at' => $storedAt,
            'scheduled_date' => substr($storedAt, 0, 10),
            'time' => substr($storedAt, 11, 8),
            'timezone' => $timezone,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ]);
    }

    private function attachService(Shoot $shoot, string $scheduledAt, User $photographer): void
    {
        $service = Service::factory()->create();
        $shoot->services()->attach($service->id, [
            'photographer_id' => $photographer->id,
            'scheduled_at' => $scheduledAt,
            'workflow_status' => 'scheduled',
            'price' => 100,
            'quantity' => 1,
        ]);
    }
}
