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
 * Feature: google-calendar-sync-upgrade, Property 4: Description excludes
 * internal/financial data.
 *
 * Validates: Requirements 3.12, 3.13
 *
 * For any shoot, the photographer-facing description produced by
 * GoogleCalendarEventPayloadBuilder::build() (the `description` key, derived
 * from buildDescription()) NEVER contains:
 *
 *   - pricing values (per-service `price` / `photographer_pay`, and shoot-level
 *     `base_quote`, `total_quote`, `discount_amount`, `tax_amount`);
 *   - payment status / payment type (`payment_status`, `payment_type`);
 *   - the contents of the internal note columns `company_notes`,
 *     `editor_notes`, and `admin_issue_notes`.
 *
 * Req 3.12 mandates the photographer description excludes pricing, payment
 * status, and admin/internal notes. Req 3.13 confirms the derived sections
 * (Property Access / Arrival Instructions / On-Site Contact) come only from
 * customer-facing shoot/client fields, so the internal columns must never
 * surface through any derivation path either.
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so this test follows the deterministic-generator convention used by
 * the rest of the suite (see GoogleCalendarTitlePropertyTest,
 * CubiCasaPerShootIdempotencyPropertyTest): a seeded PRNG produces well over
 * 100 randomized shoot states. Each internal/financial field is populated with
 * a distinctive, unguessable sentinel token; the test then asserts none of
 * those sentinels appears anywhere in the rendered description. Customer-facing
 * fields (shoot_notes / notes / photographer_notes) are independently
 * randomized so the description is non-trivial, exercising the derivation paths
 * that could otherwise leak. External Google Calendar HTTP is mocked (the
 * builder issues none, but GoogleCalendarService is bound to a mock and stray
 * HTTP is blocked).
 */
class GoogleCalendarNoDataLeakPropertyTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /** Property iterations — comfortably above the mandated 100. */
    private const ITERATIONS = 150;

    /** Fixed seed so any counterexample reproduces deterministically. */
    private const SEED = 4_00_04;

    /** All shoot statuses, including cancellation. */
    private const STATUSES = [
        Shoot::STATUS_REQUESTED,
        Shoot::STATUS_SCHEDULED,
        Shoot::STATUS_UPLOADED,
        Shoot::STATUS_EDITING,
        Shoot::STATUS_REVIEW,
        Shoot::STATUS_READY,
        Shoot::STATUS_DELIVERED,
        Shoot::STATUS_ON_HOLD,
        Shoot::STATUS_CANCELLED,
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
     * Feature: google-calendar-sync-upgrade, Property 4: Description excludes
     * internal/financial data.
     *
     * Validates: Requirements 3.12, 3.13
     */
    public function test_description_never_leaks_internal_or_financial_data(): void
    {
        mt_srand(self::SEED);

        $builder = app(GoogleCalendarEventPayloadBuilder::class);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // --- Distinctive sentinels for every internal/financial field. None
            //     of these strings should ever appear in the description. They are
            //     deliberately unique per-iteration and unlike any customer-facing
            //     token so a match is unambiguous evidence of a leak.
            $companyNote = 'COMPANYNOTELEAK' . $i . 'Zx';
            $editorNote = 'EDITORNOTELEAK' . $i . 'Zx';
            $adminIssueNote = 'ADMINISSUELEAK' . $i . 'Zx';
            $paymentStatusToken = 'PAYSTATUSLEAK' . $i . 'Zx';
            $paymentTypeToken = 'PAYTYPELEAK' . $i . 'Zx';

            // Numeric pricing sentinels — large, distinctive integers that will
            // not collide with shoot ids, phone digits, or scheduled times.
            $baseQuote = 7100000 + $i;        // base_quote
            $totalQuote = 7200000 + $i;       // total_quote
            $discountAmount = 7300000 + $i;   // discount_amount
            $taxAmount = 7400000 + $i;        // tax_amount
            $servicePrice = 7500000 + $i;     // pivot.price
            $photographerPay = 7600000 + $i;  // pivot.photographer_pay

            $financialSentinels = [
                $companyNote,
                $editorNote,
                $adminIssueNote,
                $paymentStatusToken,
                $paymentTypeToken,
                (string) $baseQuote,
                (string) $totalQuote,
                (string) $discountAmount,
                (string) $taxAmount,
                (string) $servicePrice,
                (string) $photographerPay,
            ];

            // --- Client identity (customer-facing; allowed in the description).
            $identityCase = mt_rand(0, 2);
            [$clientName, $clientCompany] = match ($identityCase) {
                0 => ['Client' . $i . 'Nm', 'Client' . $i . 'Co'],
                1 => ['', 'Client' . $i . 'Co'],
                default => ['', ''],
            };

            $client = User::factory()->create([
                'role' => 'client',
                'name' => $clientName,
                'company_name' => $clientCompany,
                'phone' => mt_rand(0, 1) ? '555010' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) : '',
                'phonenumber' => mt_rand(0, 1) ? '555020' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) : '',
                'email' => "client{$i}@example.test",
            ]);

            $photographer = User::factory()->photographer()->create([
                'name' => 'Photog' . $i . 'Nm',
                'timezone' => 'America/New_York',
            ]);

            $scheduledAt = now()->addDays(mt_rand(1, 30))->setTime(mt_rand(7, 18), [0, 15, 30, 45][mt_rand(0, 3)]);

            // --- Customer-facing notes (allowed; randomized so derivation runs).
            $shootNotes = mt_rand(0, 1) ? 'ShootNote' . $i . 'Ok gate code present' : null;
            $genericNotes = mt_rand(0, 1) ? 'GenericNote' . $i . 'Ok' : null;
            $photographerNotes = mt_rand(0, 1) ? 'PhotogNote' . $i . 'Ok park out front' : null;

            $shoot = Shoot::factory()->create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
                'status' => self::STATUSES[mt_rand(0, count(self::STATUSES) - 1)],
                'workflow_status' => self::STATUSES[mt_rand(0, count(self::STATUSES) - 1)],
                'scheduled_at' => $scheduledAt,
                'scheduled_date' => $scheduledAt->toDateString(),
                'time' => $scheduledAt->format('H:i'),
                // Customer-facing notes (may appear).
                'shoot_notes' => $shootNotes,
                'notes' => $genericNotes,
                'photographer_notes' => $photographerNotes,
                // Internal notes (must NOT appear).
                'company_notes' => $companyNote,
                'editor_notes' => $editorNote,
                'admin_issue_notes' => $adminIssueNote,
                // Financial / payment fields (must NOT appear).
                'base_quote' => $baseQuote,
                'total_quote' => $totalQuote,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'payment_status' => $paymentStatusToken,
                'payment_type' => $paymentTypeToken,
            ]);

            // --- Attach 0-3 services carrying sentinel pricing in the pivot.
            $serviceCount = mt_rand(0, 3);
            for ($s = 0; $s < $serviceCount; $s++) {
                $service = Service::factory()->create([
                    'name' => 'Svc' . $i . '_' . $s . 'Ok',
                    'delivery_time' => 1,
                ]);
                $shoot->services()->attach($service->id, [
                    'price' => $servicePrice,
                    'quantity' => 1,
                    'photographer_pay' => $photographerPay,
                    'photographer_id' => $photographer->id,
                ]);
            }

            $payload = $builder->build($shoot->fresh(['services', 'client']), $photographer);
            $description = $payload['description'] ?? '';

            $context = sprintf(
                'iteration %d, identityCase=%d, status=%s, workflow=%s',
                $i,
                $identityCase,
                $shoot->status,
                $shoot->workflow_status
            );

            // Core property: no internal/financial sentinel appears anywhere in
            // the rendered description (Req 3.12, 3.13).
            foreach ($financialSentinels as $sentinel) {
                $this->assertStringNotContainsString(
                    $sentinel,
                    $description,
                    "description must not leak internal/financial value '{$sentinel}'. {$context}"
                );
            }
        }
    }
}
