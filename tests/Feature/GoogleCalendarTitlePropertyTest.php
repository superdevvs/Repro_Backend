<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarEventPayloadBuilder;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Feature: google-calendar-sync-upgrade, Property 1: Title is client name,
 * cancelled is prefixed.
 *
 * Validates: Requirements 1.1, 1.2, 1.3, 8.2
 *
 * For any shoot, the event title produced by
 * GoogleCalendarEventPayloadBuilder::build() (the `summary` key, derived from
 * buildTitle()):
 *
 *   (a) equals the client display name when the shoot is NOT cancelled
 *       (Req 1.1) — where the display name is the client `name`, falling back
 *       to `company_name`, then the literal "Client";
 *   (b) equals "CANCELLED - {client name}" when the shoot IS cancelled, where
 *       cancellation is signalled by either `status` or `workflow_status`
 *       equalling 'cancelled' (Req 1.3, 8.2);
 *   (c) never contains service names, the raw shoot status string, or the
 *       photographer's name — the only status-derived text permitted is the
 *       "CANCELLED - " prefix mandated by Req 1.3/8.2 (Req 1.2).
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so this test follows the deterministic-generator convention used by
 * the rest of the suite (see CubiCasaPerShootIdempotencyPropertyTest,
 * TimezoneNormalizationPropertyTest): a seeded PRNG produces well over 100
 * randomized shoot states spanning every cancellation channel, the client
 * name/company/empty fallbacks, and a spread of non-cancellation statuses.
 * External Google Calendar HTTP is mocked (the builder issues none, but the
 * GoogleCalendarService is bound to a mock and stray HTTP is blocked).
 */
class GoogleCalendarTitlePropertyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /** Property iterations — comfortably above the mandated 100. */
    private const ITERATIONS = 150;

    /** Fixed seed so any counterexample reproduces deterministically. */
    private const SEED = 1_00_01;

    /** Statuses that are NOT cancellations (declined is non-cancel for title). */
    private const NON_CANCEL_STATUSES = [
        Shoot::STATUS_REQUESTED,
        Shoot::STATUS_SCHEDULED,
        Shoot::STATUS_UPLOADED,
        Shoot::STATUS_EDITING,
        Shoot::STATUS_REVIEW,
        Shoot::STATUS_READY,
        Shoot::STATUS_DELIVERED,
        Shoot::STATUS_ON_HOLD,
        Shoot::STATUS_DECLINED,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The builder performs pure string/array construction and makes no HTTP
        // calls, but the task mandates the Google Calendar transport is mocked
        // and no live HTTP escapes. Bind a mock service and block stray calls.
        $this->app->instance(GoogleCalendarService::class, Mockery::mock(GoogleCalendarService::class));
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Feature: google-calendar-sync-upgrade, Property 1: Title is client name,
     * cancelled is prefixed.
     *
     * Validates: Requirements 1.1, 1.2, 1.3, 8.2
     */
    public function test_title_is_client_name_and_cancelled_is_prefixed(): void
    {
        mt_srand(self::SEED);

        $builder = app(GoogleCalendarEventPayloadBuilder::class);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // --- Generate the client identity (drives the expected display name).
            // 0: name present, 1: name empty -> company fallback, 2: both empty -> "Client".
            $identityCase = mt_rand(0, 2);
            $nameToken = 'ClientNm' . $i . 'Qz';
            $companyToken = 'ClientCo' . $i . 'Qz';

            [$clientName, $clientCompany, $expectedDisplayName] = match ($identityCase) {
                0 => [$nameToken, $companyToken, $nameToken],
                1 => ['', $companyToken, $companyToken],
                default => ['', '', 'Client'],
            };

            $client = User::factory()->create([
                'role' => 'client',
                'name' => $clientName,
                'company_name' => $clientCompany,
            ]);

            // --- Generate a photographer with a distinctive name to detect leaks.
            $photographerName = 'PhotogNm' . $i . 'Qz';
            $photographer = User::factory()->photographer()->create([
                'name' => $photographerName,
                'timezone' => 'America/New_York',
            ]);

            // --- Generate the cancellation channel.
            // 0: not cancelled, 1: status cancelled, 2: workflow cancelled, 3: both.
            $cancelCase = mt_rand(0, 3);
            $nonCancel = self::NON_CANCEL_STATUSES[mt_rand(0, count(self::NON_CANCEL_STATUSES) - 1)];
            $otherNonCancel = self::NON_CANCEL_STATUSES[mt_rand(0, count(self::NON_CANCEL_STATUSES) - 1)];

            [$status, $workflowStatus, $expectedCancelled] = match ($cancelCase) {
                0 => [$nonCancel, $otherNonCancel, false],
                1 => [Shoot::STATUS_CANCELLED, $otherNonCancel, true],
                2 => [$nonCancel, Shoot::STATUS_CANCELLED, true],
                default => [Shoot::STATUS_CANCELLED, Shoot::STATUS_CANCELLED, true],
            };

            $scheduledAt = now()->addDays(mt_rand(1, 30))->setTime(mt_rand(7, 18), [0, 15, 30, 45][mt_rand(0, 3)]);

            $shoot = Shoot::factory()->create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'status' => $status,
                'workflow_status' => $workflowStatus,
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledAt->toDateString(),
                'time' => $scheduledAt->format('H:i'),
            ]);

            // --- Attach 0-3 services with distinctive names to detect leaks.
            $serviceCount = mt_rand(0, 3);
            $serviceNames = [];
            for ($s = 0; $s < $serviceCount; $s++) {
                $serviceName = 'SvcNm' . $i . '_' . $s . 'Qz';
                $serviceNames[] = $serviceName;
                $service = Service::factory()->create([
                    'name' => $serviceName,
                    'delivery_time' => 1,
                ]);
                $shoot->services()->attach($service->id, [
                    'price' => 100,
                    'quantity' => 1,
                    'photographer_pay' => 40,
                    'photographer_id' => $photographer->id,
                ]);
            }

            $payload = $builder->build($shoot->fresh(['services', 'client']), $photographer);
            $title = $payload['summary'];

            $context = sprintf(
                'iteration %d, identityCase=%d, cancelCase=%d, status=%s, workflow=%s',
                $i,
                $identityCase,
                $cancelCase,
                $status,
                $workflowStatus
            );

            // (a)/(b) Exact title contract.
            $expectedTitle = $expectedCancelled
                ? "CANCELLED - {$expectedDisplayName}"
                : $expectedDisplayName;

            $this->assertSame(
                $expectedTitle,
                $title,
                "[a/b] title must equal the contracted value. {$context}"
            );

            // (c) The title never leaks service names.
            foreach ($serviceNames as $serviceName) {
                $this->assertStringNotContainsString(
                    $serviceName,
                    $title,
                    "[c] title must not contain a service name. {$context}"
                );
            }

            // (c) The title never leaks the photographer name.
            $this->assertStringNotContainsString(
                $photographerName,
                $title,
                "[c] title must not contain the photographer name. {$context}"
            );

            // (c) The title never contains a raw shoot status string. The only
            //     status-derived text allowed is the "CANCELLED - " prefix
            //     (Req 1.3/8.2), so strip it before checking.
            $titleWithoutCancelPrefix = $expectedCancelled
                ? substr($title, strlen('CANCELLED - '))
                : $title;

            foreach (array_unique([$status, $workflowStatus]) as $statusValue) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $statusValue,
                    $titleWithoutCancelPrefix,
                    "[c] title must not contain the shoot status '{$statusValue}'. {$context}"
                );
            }
        }
    }
}
