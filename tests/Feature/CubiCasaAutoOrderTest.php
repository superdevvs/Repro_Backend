<?php

namespace Tests\Feature;

use App\Jobs\CreateCubiCasaOrderJob;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\CubiCasaService;
use App\Services\ShootMediaStorageService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\PhotographerAvailabilityService;
use App\Services\ShootWorkflowService;
use App\Services\Shoots\Actions\ApproveShootAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Feature: auto-create-cubicasa-order
 *
 * Property and example coverage for the automatic CubiCasa order-creation
 * feature. Tests assert against the CubiCasa API HTTP boundary using
 * Http::fake() / Http::assertSent(), because the payload builder
 * (CubiCasaService::buildOrderPayload()) is private and must NOT be exercised
 * via reflection. We trigger the real code path with
 * CubiCasaService::createOrder($shoot) (which POSTs to /orders/draft) and
 * assert against the recorded request body.
 *
 * Subsequent tasks (1.4, 1.5, 1.6, ...) add further methods to this class.
 */
class CubiCasaAutoOrderTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';
    private const NEW_ORDER_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    /**
     * Number of randomized examples generated per property test.
     *
     * Spec mandates >= 100 iterations per property; this constant honors that
     * minimum. Adjust to trade run time for coverage.
     */
    private const PROPERTY_ITERATIONS = 100;

    /** Fixed seed so counterexamples reproduce; bump if a counterexample is fixed. */
    private const SEED = 720_2;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.owner_email', 'orders@reprophotos.com');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
    }

    private function successPayload(string $externalId): array
    {
        return [
            'id' => self::NEW_ORDER_ID,
            'info' => [
                'external_id' => $externalId,
                'status' => 'New',
                'order_type' => 'Tier3-LiDAR',
            ],
            'address' => [
                'full_address' => '521 Brightfield Road',
            ],
        ];
    }

    /**
     * Feature: auto-create-cubicasa-order, Property 2: Order payload always
     * carries the suite when present.
     *
     * For any shoot whose property_details contain a non-empty suite value
     * under any of the keys apt_suite, aptSuite, or suite, the recorded
     * POST /orders/draft request body's top-level `suite` equals that value.
     *
     * Validates: Requirements 7.1
     */
    public function test_order_payload_always_carries_suite_when_present(): void
    {
        mt_srand(self::SEED);

        $suiteKeys = ['apt_suite', 'aptSuite', 'suite'];

        for ($i = 0; $i < self::PROPERTY_ITERATIONS; $i++) {
            // Randomly choose which of the three supported keys holds the suite.
            $key = $suiteKeys[mt_rand(0, count($suiteKeys) - 1)];

            // Generate a randomized, non-empty (after trim) suite value.
            $suiteValue = $this->randomNonEmptySuite();

            // Fresh Http factory each iteration so prior stubs/recorded
            // requests cannot leak into this iteration's assertions.
            Http::swap(new HttpFactory(
                $this->app->bound('events') ? $this->app->make('events') : null
            ));
            Http::fake([
                self::BASE_URL . '/orders/draft' => Http::response($this->successPayload('shoot-prop-2'), 200),
            ]);

            $shoot = Shoot::factory()->create([
                'cubicasa_order_id' => null,
                'cubicasa_external_id' => null,
                'cubicasa_idempotency_key' => null,
                'address' => '521 Brightfield Road',
                'city' => 'Ottawa',
                'state' => 'ON',
                'zip' => 'K1A0B1',
                'property_details' => [$key => $suiteValue],
            ]);

            $context = sprintf(
                'iteration %d, key=%s, suite=%s',
                $i,
                $key,
                json_encode($suiteValue)
            );

            app(CubiCasaService::class)->createOrder($shoot);

            Http::assertSent(function ($request) use ($suiteValue, $context) {
                if ($request->method() !== 'POST'
                    || ! str_starts_with($request->url(), self::BASE_URL . '/orders')
                ) {
                    return false;
                }

                $body = $request->data();
                $sentSuite = $body['suite'] ?? null;

                $this->assertSame(
                    $suiteValue,
                    $sentSuite,
                    "POST /orders/draft suite must equal the property_details suite for {$context}"
                );

                return true;
            });
        }
    }

    /**
     * Feature: auto-create-cubicasa-order, Property 3: Country is always sent.
     *
     * CubiCasa v3 rejects an order with no `country` ("field required"), so it
     * is sent unconditionally — a Shoot has no country attribute and we only
     * serve US properties today. This previously defaulted to "US" only when
     * state AND zip were both present, which meant any shoot missing either one
     * produced a 400. The randomized state/zip combinations here exist to prove
     * country no longer depends on them.
     *
     * Validates: Requirements 7.2
     */
    public function test_country_is_always_sent_regardless_of_state_and_zip(): void
    {
        mt_srand(self::SEED);

        for ($i = 0; $i < self::PROPERTY_ITERATIONS; $i++) {
            // Independently decide whether this iteration's shoot has a
            // non-empty state and/or zip so we cover all four combinations.
            $hasState = mt_rand(0, 1) === 1;
            $hasZip = mt_rand(0, 1) === 1;

            $state = $hasState ? $this->randomState() : '';
            $zip = $hasZip ? $this->randomZip() : '';

            // Fresh Http factory each iteration so prior stubs/recorded
            // requests cannot leak into this iteration's assertions.
            Http::swap(new HttpFactory(
                $this->app->bound('events') ? $this->app->make('events') : null
            ));
            Http::fake([
                self::BASE_URL . '/orders/draft' => Http::response($this->successPayload('shoot-prop-3'), 200),
            ]);

            $shoot = Shoot::factory()->create([
                'cubicasa_order_id' => null,
                'cubicasa_external_id' => null,
                'cubicasa_idempotency_key' => null,
                'address' => '521 Brightfield Road',
                'city' => 'Ottawa',
                'state' => $state,
                'zip' => $zip,
                'property_details' => [],
            ]);

            $context = sprintf(
                'iteration %d, state=%s, zip=%s',
                $i,
                json_encode($state),
                json_encode($zip)
            );

            app(CubiCasaService::class)->createOrder($shoot);

            Http::assertSent(function ($request) use ($context) {
                if ($request->method() !== 'POST'
                    || ! str_starts_with($request->url(), self::BASE_URL . '/orders')
                ) {
                    return false;
                }

                $body = $request->data();

                $this->assertSame(
                    'United States',
                    $body['country'] ?? null,
                    "POST /orders/draft must always carry a country for {$context}"
                );

                return true;
            });
        }
    }

    /**
     * Feature: auto-create-cubicasa-order, Property 4: External id is derived
     * from the shoot id.
     *
     * For any shoot, the recorded POST /orders request body's info.external_id
     * equals "shoot-" concatenated with the shoot's identifier. We randomize
     * the surrounding shoot data (address fields, suite, state, zip) to show
     * the external_id derivation is independent of all other payload inputs.
     *
     * Validates: Requirements 7.3
     */
    public function test_external_id_is_derived_from_shoot_id(): void
    {
        mt_srand(self::SEED);

        $suiteKeys = ['apt_suite', 'aptSuite', 'suite'];

        for ($i = 0; $i < self::PROPERTY_ITERATIONS; $i++) {
            // Randomize surrounding payload inputs so the assertion proves
            // external_id depends only on the shoot id, nothing else.
            $hasSuite = mt_rand(0, 1) === 1;
            $propertyDetails = $hasSuite
                ? [$suiteKeys[mt_rand(0, count($suiteKeys) - 1)] => $this->randomNonEmptySuite()]
                : [];

            $hasState = mt_rand(0, 1) === 1;
            $hasZip = mt_rand(0, 1) === 1;

            // Fresh Http factory each iteration so prior stubs/recorded
            // requests cannot leak into this iteration's assertions.
            Http::swap(new HttpFactory(
                $this->app->bound('events') ? $this->app->make('events') : null
            ));
            Http::fake([
                self::BASE_URL . '/orders/draft' => Http::response($this->successPayload('shoot-prop-4'), 200),
            ]);

            $shoot = Shoot::factory()->create([
                'cubicasa_order_id' => null,
                'cubicasa_external_id' => null,
                'cubicasa_idempotency_key' => null,
                'address' => '521 Brightfield Road',
                'city' => 'Ottawa',
                'state' => $hasState ? $this->randomState() : '',
                'zip' => $hasZip ? $this->randomZip() : '',
                'property_details' => $propertyDetails,
            ]);

            $expectedExternalId = 'shoot-' . $shoot->id;

            $context = sprintf(
                'iteration %d, shoot_id=%s',
                $i,
                json_encode($shoot->id)
            );

            app(CubiCasaService::class)->createOrder($shoot);

            Http::assertSent(function ($request) use ($expectedExternalId, $context) {
                if ($request->method() !== 'POST'
                    || ! str_starts_with($request->url(), self::BASE_URL . '/orders')
                ) {
                    return false;
                }

                $body = $request->data();
                $sentExternalId = $body['external_id'] ?? null;

                $this->assertSame(
                    $expectedExternalId,
                    $sentExternalId,
                    "POST /orders info.external_id must equal 'shoot-' . shoot id for {$context}"
                );

                return true;
            });
        }
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 1.6 (example/unit): a source of
     * "auto" records the cubicasa.auto_create audit event.
     *
     * When createOrder() succeeds and is called with an explicit source of
     * "auto", exactly one audit entry is written for the create and its
     * event_type is cubicasa.auto_create (not cubicasa.manual_create).
     *
     * Validates: Requirements 6.1
     */
    public function test_auto_source_records_auto_create_audit_event(): void
    {
        Http::fake([
            self::BASE_URL . '/orders/draft' => Http::response($this->successPayload('shoot-auto'), 200),
        ]);

        $shoot = $this->unlinkedShoot();

        app(CubiCasaService::class)->createOrder($shoot, null, 'auto');

        $this->assertSame(
            1,
            UserActivityLog::where('event_type', 'cubicasa.auto_create')
                ->where('target_id', $shoot->id)
                ->count(),
            "source = 'auto' must record exactly one cubicasa.auto_create audit event."
        );

        $this->assertSame(
            0,
            UserActivityLog::where('event_type', 'cubicasa.manual_create')
                ->where('target_id', $shoot->id)
                ->count(),
            "source = 'auto' must NOT record a cubicasa.manual_create audit event."
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 1.6 (example/unit): a source of
     * "manual" records the cubicasa.manual_create audit event.
     *
     * When createOrder() succeeds and is called with an explicit source of
     * "manual" (and an acting user), exactly one audit entry is written for the
     * create and its event_type is cubicasa.manual_create.
     *
     * Validates: Requirements 6.2
     */
    public function test_manual_source_records_manual_create_audit_event(): void
    {
        Http::fake([
            self::BASE_URL . '/orders/draft' => Http::response($this->successPayload('shoot-manual'), 200),
        ]);

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = $this->unlinkedShoot();

        app(CubiCasaService::class)->createOrder($shoot, $actor, 'manual');

        $this->assertSame(
            1,
            UserActivityLog::where('event_type', 'cubicasa.manual_create')
                ->where('target_id', $shoot->id)
                ->count(),
            "source = 'manual' must record exactly one cubicasa.manual_create audit event."
        );

        $this->assertSame(
            0,
            UserActivityLog::where('event_type', 'cubicasa.auto_create')
                ->where('target_id', $shoot->id)
                ->count(),
            "source = 'manual' must NOT record a cubicasa.auto_create audit event."
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 1.6 (example/unit): an omitted
     * source defaults to "manual" and records the cubicasa.manual_create audit
     * event.
     *
     * When createOrder() succeeds and is called WITHOUT a source argument, the
     * source defaults to "manual", so exactly one audit entry is written for
     * the create and its event_type is cubicasa.manual_create.
     *
     * Validates: Requirements 6.3
     */
    public function test_omitted_source_defaults_to_manual_create_audit_event(): void
    {
        Http::fake([
            self::BASE_URL . '/orders/draft' => Http::response($this->successPayload('shoot-default'), 200),
        ]);

        $shoot = $this->unlinkedShoot();

        // Source argument omitted entirely -> defaults to "manual".
        app(CubiCasaService::class)->createOrder($shoot);

        $this->assertSame(
            1,
            UserActivityLog::where('event_type', 'cubicasa.manual_create')
                ->where('target_id', $shoot->id)
                ->count(),
            'An omitted source must default to manual and record exactly one cubicasa.manual_create audit event.'
        );

        $this->assertSame(
            0,
            UserActivityLog::where('event_type', 'cubicasa.auto_create')
                ->where('target_id', $shoot->id)
                ->count(),
            'An omitted source must NOT record a cubicasa.auto_create audit event.'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Property 1: Eligible, unlinked,
     * active shoots trigger an auto create.
     *
     * For any shoot that exists, is not cancelled or declined, has a
     * CubiCasa-eligible service, is not already linked, and has credentials
     * available, running CreateCubiCasaOrderJob invokes
     * CubiCasaService::createOrder() exactly once with the shoot and a source
     * value of "auto".
     *
     * We hold the five preconditions (exists / active / eligible / unlinked /
     * credentials) true while randomizing everything the contract must be
     * indifferent to: the active status + workflow_status pair, the address
     * fields, the suite, and the property_details. A hand-rolled spy subclass
     * of CubiCasaService records every createOrder() call (the receiver shoot,
     * the actor, and the source) and reports hasCredentials() === true, so we
     * can assert the exact invocation count and arguments per iteration without
     * Mockery expectation accumulation across the 100-iteration loop.
     *
     * Validates: Requirements 3.5
     */
    public function test_eligible_unlinked_active_shoots_trigger_an_auto_create(): void
    {
        mt_srand(self::SEED);

        // Statuses that are NOT cancelled/declined, so the active guard passes.
        $activeStatuses = [
            Shoot::STATUS_REQUESTED,
            Shoot::STATUS_SCHEDULED,
            Shoot::STATUS_UPLOADED,
            Shoot::STATUS_EDITING,
            Shoot::STATUS_REVIEW,
            Shoot::STATUS_READY,
            Shoot::STATUS_DELIVERED,
            Shoot::STATUS_ON_HOLD,
        ];

        $suiteKeys = ['apt_suite', 'aptSuite', 'suite'];

        for ($i = 0; $i < self::PROPERTY_ITERATIONS; $i++) {
            // Randomize the active status/workflow_status pair: any pairing of
            // non-cancelled/declined values must still trigger the create.
            $status = $activeStatuses[mt_rand(0, count($activeStatuses) - 1)];
            $workflowStatus = $activeStatuses[mt_rand(0, count($activeStatuses) - 1)];

            // Randomize otherwise-irrelevant payload inputs.
            $propertyDetails = mt_rand(0, 1) === 1
                ? [$suiteKeys[mt_rand(0, count($suiteKeys) - 1)] => $this->randomNonEmptySuite()]
                : [];

            $shoot = Shoot::factory()->create([
                'status' => $status,
                'workflow_status' => $workflowStatus,
                // Unlinked: no existing CubiCasa order/external id.
                'cubicasa_order_id' => null,
                'cubicasa_external_id' => null,
                'cubicasa_idempotency_key' => null,
                'address' => '521 Brightfield Road',
                'city' => 'Ottawa',
                'state' => mt_rand(0, 1) === 1 ? $this->randomState() : '',
                'zip' => mt_rand(0, 1) === 1 ? $this->randomZip() : '',
                'property_details' => $propertyDetails,
            ]);

            // Eligible: attach a CubiCasa-relevant service.
            $this->attachCubicasaService($shoot);

            $context = sprintf(
                'iteration %d, shoot_id=%s, status=%s, workflow_status=%s',
                $i,
                json_encode($shoot->id),
                $status,
                $workflowStatus
            );

            // Spy subclass: credentials present, createOrder records its args
            // and returns a non-null result (so the job treats it as success).
            $spy = new class extends CubiCasaService {
                /** @var array<int, array{0: Shoot, 1: ?User, 2: string}> */
                public array $createOrderCalls = [];

                public function hasCredentials(): bool
                {
                    return true;
                }

                public function createOrder(Shoot $shoot, ?User $actor = null, string $source = 'manual'): ?array
                {
                    $this->createOrderCalls[] = [$shoot, $actor, $source];

                    return ['order_id' => 'spy-order-id'];
                }
            };

            (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

            $this->assertCount(
                1,
                $spy->createOrderCalls,
                "createOrder() must be invoked exactly once for {$context}"
            );

            [$receivedShoot, $receivedActor, $receivedSource] = $spy->createOrderCalls[0];

            $this->assertInstanceOf(
                Shoot::class,
                $receivedShoot,
                "createOrder() must receive a Shoot instance for {$context}"
            );
            $this->assertSame(
                $shoot->id,
                $receivedShoot->id,
                "createOrder() must receive the same shoot that was dispatched for {$context}"
            );
            $this->assertSame(
                'auto',
                $receivedSource,
                "createOrder() must be invoked with source 'auto' for {$context}"
            );
        }
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.3 (example/edge): a missing
     * shoot completes the job without calling createOrder().
     *
     * When the referenced shoot id does not exist, the job loads null and
     * returns at the first guard, so the service create path is never reached.
     *
     * Validates: Requirements 3.1
     */
    public function test_missing_shoot_does_not_call_create_order(): void
    {
        $spy = $this->makeCreateOrderSpy();

        // An id that cannot correspond to any persisted shoot.
        (new CreateCubiCasaOrderJob(999_999))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'A missing shoot must complete without invoking createOrder().'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.3 (example/edge): a shoot
     * cancelled via its status completes the job without calling createOrder().
     *
     * The shoot is otherwise create-ready (eligible + unlinked) so the only
     * reason the create path is skipped is the cancelled status guard.
     *
     * Validates: Requirements 3.2
     */
    public function test_cancelled_via_status_does_not_call_create_order(): void
    {
        $spy = $this->makeCreateOrderSpy();

        $shoot = $this->unlinkedShoot();
        $shoot->forceFill([
            'status' => Shoot::STATUS_CANCELLED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ])->save();
        $this->attachCubicasaService($shoot);

        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'A shoot cancelled via status must complete without invoking createOrder().'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.3 (example/edge): a shoot
     * declined via its status completes the job without calling createOrder().
     *
     * Validates: Requirements 3.2
     */
    public function test_declined_via_status_does_not_call_create_order(): void
    {
        $spy = $this->makeCreateOrderSpy();

        $shoot = $this->unlinkedShoot();
        $shoot->forceFill([
            'status' => Shoot::STATUS_DECLINED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ])->save();
        $this->attachCubicasaService($shoot);

        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'A shoot declined via status must complete without invoking createOrder().'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.3 (example/edge): a shoot
     * cancelled via its workflow_status completes the job without calling
     * createOrder(), even when the primary status is active.
     *
     * Validates: Requirements 3.2
     */
    public function test_cancelled_via_workflow_status_does_not_call_create_order(): void
    {
        $spy = $this->makeCreateOrderSpy();

        $shoot = $this->unlinkedShoot();
        $shoot->forceFill([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_CANCELLED,
        ])->save();
        $this->attachCubicasaService($shoot);

        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'A shoot cancelled via workflow_status must complete without invoking createOrder().'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.3 (example/edge): a shoot
     * declined via its workflow_status completes the job without calling
     * createOrder(), even when the primary status is active.
     *
     * Validates: Requirements 3.2
     */
    public function test_declined_via_workflow_status_does_not_call_create_order(): void
    {
        $spy = $this->makeCreateOrderSpy();

        $shoot = $this->unlinkedShoot();
        $shoot->forceFill([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_DECLINED,
        ])->save();
        $this->attachCubicasaService($shoot);

        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'A shoot declined via workflow_status must complete without invoking createOrder().'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.3 (example/edge): an
     * ineligible shoot (no CubiCasa-eligible service attached) completes the
     * job without calling createOrder().
     *
     * The shoot is active and unlinked, so the only reason the create path is
     * skipped is the eligibility guard.
     *
     * Validates: Requirements 3.3
     */
    public function test_ineligible_shoot_does_not_call_create_order(): void
    {
        $spy = $this->makeCreateOrderSpy();

        // unlinkedShoot() attaches no services -> hasCubiCasaEligibleService() is false.
        $shoot = $this->unlinkedShoot();
        $shoot->forceFill([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ])->save();

        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'An ineligible shoot must complete without invoking createOrder().'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.3 (example/edge): a shoot
     * already linked via cubicasa_order_id completes the job without calling
     * createOrder().
     *
     * The shoot is active and eligible, so the only reason the create path is
     * skipped is the already-linked guard.
     *
     * Validates: Requirements 3.4
     */
    public function test_already_linked_via_order_id_does_not_call_create_order(): void
    {
        $spy = $this->makeCreateOrderSpy();

        $shoot = $this->unlinkedShoot();
        $shoot->forceFill([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'cubicasa_order_id' => 'existing-order-id',
        ])->save();
        $this->attachCubicasaService($shoot);

        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'A shoot already linked via cubicasa_order_id must complete without invoking createOrder().'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.3 (example/edge): a shoot
     * already linked via cubicasa_external_id completes the job without calling
     * createOrder().
     *
     * Validates: Requirements 3.4
     */
    public function test_already_linked_via_external_id_does_not_call_create_order(): void
    {
        $spy = $this->makeCreateOrderSpy();

        $shoot = $this->unlinkedShoot();
        $shoot->forceFill([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'cubicasa_external_id' => 'existing-external-id',
        ])->save();
        $this->attachCubicasaService($shoot);

        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'A shoot already linked via cubicasa_external_id must complete without invoking createOrder().'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.4 (example/edge): when
     * credentials are not configured, the job records an info log and completes
     * WITHOUT invoking createOrder() and WITHOUT throwing (no retry).
     *
     * The shoot is otherwise create-ready (active + eligible + unlinked) so the
     * only reason the create path is skipped is the credentials guard. A spy
     * whose hasCredentials() returns false but whose createOrder() still records
     * any call ensures a missing short-circuit would surface as a recorded call.
     *
     * Validates: Requirements 4.1, 4.2
     */
    public function test_missing_credentials_logs_info_and_does_not_call_create_order_or_throw(): void
    {
        Log::spy();

        $shoot = $this->createReadyShoot();

        $spy = new class extends CubiCasaService {
            /** @var array<int, array{0: Shoot, 1: ?User, 2: string}> */
            public array $createOrderCalls = [];

            public function hasCredentials(): bool
            {
                return false;
            }

            public function createOrder(Shoot $shoot, ?User $actor = null, string $source = 'manual'): ?array
            {
                $this->createOrderCalls[] = [$shoot, $actor, $source];

                return ['order_id' => 'should-not-be-created'];
            }
        };

        // Must complete without throwing (no exception => no queue retry).
        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertSame(
            [],
            $spy->createOrderCalls,
            'Missing credentials must complete without invoking createOrder().'
        );

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context = []) use ($shoot): bool {
                return str_contains($message, 'credentials')
                    && ($context['shoot_id'] ?? null) === $shoot->id;
            });

        // No throw means no warning-level failure log either.
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.4 (example/edge): when
     * createOrder() returns null due to an authentication failure
     * (getLastFailureReason() === FAILURE_AUTH), the job logs and completes
     * WITHOUT throwing (no retry).
     *
     * Validates: Requirements 4.3
     */
    public function test_auth_failure_null_result_logs_and_does_not_throw(): void
    {
        Log::spy();

        $shoot = $this->createReadyShoot();

        $spy = new class extends CubiCasaService {
            /** @var array<int, array{0: Shoot, 1: ?User, 2: string}> */
            public array $createOrderCalls = [];

            public function hasCredentials(): bool
            {
                return true;
            }

            public function createOrder(Shoot $shoot, ?User $actor = null, string $source = 'manual'): ?array
            {
                $this->createOrderCalls[] = [$shoot, $actor, $source];

                return null;
            }

            public function getLastFailureReason(): ?string
            {
                return CubiCasaService::FAILURE_AUTH;
            }
        };

        // Must complete without throwing (auth failure => no queue retry).
        (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);

        $this->assertCount(
            1,
            $spy->createOrderCalls,
            'createOrder() must be invoked once before the auth-failure short-circuit.'
        );

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context = []) use ($shoot): bool {
                return str_contains($message, 'authentication')
                    && ($context['shoot_id'] ?? null) === $shoot->id
                    && ($context['failure_reason'] ?? null) === CubiCasaService::FAILURE_AUTH;
            });

        // An auth failure is a clean no-op: no warning, hence no retry/throw.
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.4 (example/edge): when
     * createOrder() returns null for a transient/non-auth reason, the job
     * records a warning log that includes the failure reason and then throws to
     * trigger a retry.
     *
     * Validates: Requirements 5.1, 5.2
     */
    public function test_transient_null_result_logs_warning_with_reason_and_throws(): void
    {
        Log::spy();

        $shoot = $this->createReadyShoot();

        $spy = new class extends CubiCasaService {
            public function hasCredentials(): bool
            {
                return true;
            }

            public function createOrder(Shoot $shoot, ?User $actor = null, string $source = 'manual'): ?array
            {
                return null;
            }

            public function getLastFailureReason(): ?string
            {
                return CubiCasaService::FAILURE_OTHER;
            }
        };

        try {
            (new CreateCubiCasaOrderJob($shoot->id))->handle($spy);
            $this->fail('A transient (non-auth) null result must throw to trigger a retry.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                (string) $shoot->id,
                $e->getMessage(),
                'The thrown exception message should reference the shoot id.'
            );
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context = []) use ($shoot): bool {
                return ($context['shoot_id'] ?? null) === $shoot->id
                    && ($context['failure_reason'] ?? null) === CubiCasaService::FAILURE_OTHER;
            });
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 2.4 (example): the job is
     * configured for a maximum of 3 attempts with backoff delays of 60, 300,
     * and 900 seconds for successive retries.
     *
     * Validates: Requirements 5.3, 5.4
     */
    public function test_job_retry_configuration_tries_and_backoff(): void
    {
        $job = new CreateCubiCasaOrderJob(1);

        $this->assertSame(3, $job->tries, 'The job must allow a maximum of 3 attempts.');
        $this->assertSame(
            [60, 300, 900],
            $job->backoff(),
            'The job must back off 60s, 300s, then 900s on successive retries.'
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 4.2 (example): creating a
     * scheduled (non-client-request) shoot that has a scheduled date AND a
     * CubiCasa-eligible service dispatches CreateCubiCasaOrderJob.
     *
     * Drives the real CreateShootAction::execute() through the booking
     * endpoint (POST /api/shoots), mirroring ShootBookingTest, so the
     * production dispatch hook is exercised end-to-end. Queue::fake() captures
     * the afterCommit() dispatch.
     *
     * Validates: Requirements 1.1, 1.5
     */
    public function test_creation_dispatches_job_when_scheduled_with_date_and_eligible(): void
    {
        Queue::fake();

        [$admin, $client, $photographer] = $this->bookingActors();
        $eligible = $this->eligibleService();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots', [
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'address' => '123 Main St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $eligible->id, 'quantity' => 1],
            ],
            'scheduled_at' => $this->safeScheduledAt(),
        ]);

        $response->assertStatus(201);

        $shoot = Shoot::query()->firstOrFail();

        Queue::assertPushed(
            CreateCubiCasaOrderJob::class,
            fn (CreateCubiCasaOrderJob $job) => $job->shootId === $shoot->id,
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 4.2 (example): a non-client
     * booking with NO scheduled date (a hold_on shoot) must NOT dispatch the
     * job, even when the shoot has a CubiCasa-eligible service.
     *
     * Validates: Requirements 1.2
     */
    public function test_creation_does_not_dispatch_when_hold_on_without_date_even_when_eligible(): void
    {
        Queue::fake();

        [$admin, $client] = $this->bookingActors();
        $eligible = $this->eligibleService();

        Sanctum::actingAs($admin);

        // No scheduled_at and no photographer -> hold_on (scheduledAt === null).
        $response = $this->postJson('/api/shoots', [
            'client_id' => $client->id,
            'address' => '123 Main St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $eligible->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $shoot = Shoot::query()->firstOrFail();
        $this->assertSame('hold_on', $shoot->status);
        $this->assertNull($shoot->scheduled_at);

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 4.2 (example): a scheduled
     * shoot with NO CubiCasa-eligible service must NOT dispatch the job.
     *
     * Validates: Requirements 1.3
     */
    public function test_creation_does_not_dispatch_when_not_eligible(): void
    {
        Queue::fake();

        [$admin, $client, $photographer] = $this->bookingActors();
        $ineligible = $this->ineligibleService();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/shoots', [
            'client_id' => $client->id,
            'photographer_id' => $photographer->id,
            'address' => '123 Main St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'services' => [
                ['id' => $ineligible->id, 'quantity' => 1],
            ],
            'scheduled_at' => $this->safeScheduledAt(),
        ]);

        $response->assertStatus(201);

        $shoot = Shoot::query()->firstOrFail();
        $this->assertFalse(
            $shoot->hasCubiCasaEligibleService(),
            'Fixture sanity: the booked shoot must not have an eligible service.'
        );

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 4.2 (example): a client-request
     * booking must NOT dispatch the job during creation, even with an eligible
     * service (it awaits approval instead).
     *
     * Validates: Requirements 1.4
     */
    public function test_creation_does_not_dispatch_when_client_request(): void
    {
        Queue::fake();

        [, $client] = $this->bookingActors();
        $eligible = $this->eligibleService();

        // A client booking is always treated as a client request.
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/shoots', [
            'address' => '123 Client Request St',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'services' => [
                ['id' => $eligible->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);

        $shoot = Shoot::query()->firstOrFail();
        $this->assertSame(Shoot::STATUS_REQUESTED, $shoot->status);

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    /**
     * Create the admin, client, and photographer users used by the Task 4.2
     * booking-endpoint tests. Returned in [admin, client, photographer] order.
     *
     * @return array{0: User, 1: User, 2: User}
     */
    private function bookingActors(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin-4-2@test.com',
        ]);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client-4-2@test.com',
        ]);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'photographer-4-2@test.com',
        ]);

        return [$admin, $client, $photographer];
    }

    /**
     * A scheduled_at value that falls on a weekday inside the default
     * photographer availability window (09:00–18:00), so the booking endpoint
     * does not reject it on out-of-hours grounds. Mirrors the candidate-date
     * approach used by the availability parity tests.
     */
    private function safeScheduledAt(): string
    {
        return now()
            ->addWeek()
            ->next(\Carbon\Carbon::MONDAY)
            ->setTime(11, 0, 0)
            ->format('Y-m-d H:i:s');
    }

    /**
     * A bookable service whose name makes hasCubiCasaEligibleService() true.
     */
    private function eligibleService(): Service
    {
        return Service::factory()->create([
            'name' => '2D Floor plans',
            'price' => 195.00,
        ]);
    }

    /**
     * A bookable service whose name does NOT match any CubiCasa eligibility
     * needle, so hasCubiCasaEligibleService() is false.
     */
    private function ineligibleService(): Service
    {
        return Service::factory()->create([
            'name' => 'Standard Photography',
            'price' => 120.00,
        ]);
    }

    /**
     * Create an active (scheduled), eligible, unlinked shoot that reaches the
     * credentials guard and the create path. Used by the credentials/retry
     * Task 2.4 tests so the only behavior under test is the credentials /
     * failure-reason handling, not an earlier guard.
     */
    private function createReadyShoot(): Shoot
    {
        $shoot = $this->unlinkedShoot();
        $shoot->forceFill([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ])->save();
        $this->attachCubicasaService($shoot);

        return $shoot;
    }

    /**
     * Build a spy CubiCasaService that reports credentials are present and
     * records every createOrder() invocation. Reporting hasCredentials() ===
     * true ensures that if an earlier guard fails to short-circuit, the create
     * path is reached and the call is recorded, so the guard test would fail
     * (rather than passing for the wrong reason because credentials were
     * missing).
     */
    private function makeCreateOrderSpy(): CubiCasaService
    {
        return new class extends CubiCasaService {
            /** @var array<int, array{0: Shoot, 1: ?User, 2: string}> */
            public array $createOrderCalls = [];

            public function hasCredentials(): bool
            {
                return true;
            }

            public function createOrder(Shoot $shoot, ?User $actor = null, string $source = 'manual'): ?array
            {
                $this->createOrderCalls[] = [$shoot, $actor, $source];

                return ['order_id' => 'spy-order-id'];
            }
        };
    }

    /**
     * Attach a CubiCasa-eligible service to the shoot so
     * hasCubiCasaEligibleService() returns true. Mirrors the helper used by the
     * existing CubiCasa ingestion/webhook tests.
     */
    private function attachCubicasaService(Shoot $shoot): void
    {
        $service = Service::factory()->create(['name' => '2D Floor plans']);
        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 195,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Create an unlinked, create-ready shoot fixture: no existing CubiCasa
     * order/external id and no idempotency key, with a complete address so the
     * order payload builds normally.
     */
    private function unlinkedShoot(): Shoot
    {
        return Shoot::factory()->create([
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'cubicasa_idempotency_key' => null,
            'address' => '521 Brightfield Road',
            'city' => 'Ottawa',
            'state' => 'ON',
            'zip' => 'K1A0B1',
            'property_details' => [],
        ]);
    }

    /**
     * Produce a randomized, non-empty (after trim) US-style state code, e.g.
     * "CA", "TX". Kept to two-letter codes to mirror realistic shoot data.
     */
    private function randomState(): string
    {
        $states = ['CA', 'TX', 'NY', 'FL', 'WA', 'OR', 'NV', 'AZ', 'CO', 'GA'];

        return $states[mt_rand(0, count($states) - 1)];
    }

    /**
     * Produce a randomized, non-empty (after trim) US-style 5-digit zip code.
     */
    private function randomZip(): string
    {
        return str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Produce a randomized suite value that is a non-empty string after
     * trimming (the inclusion condition in buildOrderPayload). Covers common
     * shapes: bare numbers, "Suite N", "#N", unit letters, and values with
     * surrounding whitespace that still trim to a non-empty string.
     */
    private function randomNonEmptySuite(): string
    {
        $prefixes = ['', 'Suite ', 'Apt ', 'Unit ', '#', 'Ste '];
        $prefix = $prefixes[mt_rand(0, count($prefixes) - 1)];

        $number = (string) mt_rand(1, 9999);
        $letter = mt_rand(0, 1) === 1 ? chr(mt_rand(65, 90)) : '';

        $core = $prefix . $number . $letter;

        // Occasionally pad with surrounding whitespace; the value still trims
        // to a non-empty string so it must be included.
        if (mt_rand(0, 3) === 0) {
            $core = '  ' . $core . ' ';
        }

        return $core;
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 5.2 (example): approving a
     * requested shoot that is CubiCasa-eligible and not already linked
     * dispatches CreateCubiCasaOrderJob.
     *
     * Drives the real ApproveShootAction::execute() (the production dispatch
     * hook) with the heavy collaborators faked, so the only behavior under
     * test is the approval dispatch gate. The shoot is REQUESTED + eligible +
     * unlinked, so every gate passes and Queue::fake() must capture the
     * afterCommit() dispatch.
     *
     * Validates: Requirements 2.1
     */
    public function test_approval_dispatches_job_when_requested_eligible_and_unlinked(): void
    {
        Queue::fake();
        $this->bindApprovalSideEffectFakes();

        [$admin, $client, $photographer] = $this->approvalActors();

        $shoot = $this->requestedShoot($client);
        // Eligible + unlinked: only the requested gate remains, and it passes.
        $this->attachCubicasaService($shoot);

        $this->approveShoot($shoot, $admin, [
            'scheduled_at' => $this->safeScheduledAt(),
            'photographer_id' => $photographer->id,
            'notify_client' => false,
            'notify_photographer' => false,
        ]);

        Queue::assertPushed(
            CreateCubiCasaOrderJob::class,
            fn (CreateCubiCasaOrderJob $job) => $job->shootId === $shoot->id,
        );
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 5.2 (example): a shoot that is
     * already SCHEDULED, eligible and unlinked DOES get an order.
     *
     * This previously asserted the opposite. Dispatch used to be gated on the
     * $wasRequested flag, so an already-scheduled shoot fell through every
     * dispatch site and silently never got a floor plan — one of the coverage
     * gaps that made the integration look dead. Dispatch is now centralised in
     * ShootObserver and keyed on the shoot's state (confirmed + dated +
     * eligible + unlinked) rather than on which action happened to run, so this
     * scenario is exactly the one that must produce an order.
     *
     * ApproveShootAction no longer dispatches directly, so the single push
     * asserted here comes from the observer — which also proves the two are not
     * both firing.
     *
     * Validates: Requirements 2.2
     */
    public function test_approval_of_an_already_scheduled_eligible_shoot_dispatches_once(): void
    {
        Queue::fake();
        $this->bindApprovalSideEffectFakes();
        // approve() succeeds (no-op) for a non-requested shoot so execution
        // proceeds to the dispatch gate rather than failing a real transition.
        $this->bindNoopWorkflowService();

        [$admin, $client, $photographer] = $this->approvalActors();

        $shoot = $this->requestedShoot($client);
        $shoot->forceFill([
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
        ])->save();
        // Eligible + unlinked: the shoot is in exactly the state that should
        // produce an order.
        $this->attachCubicasaService($shoot);

        $this->approveShoot($shoot, $admin, [
            'scheduled_at' => $this->safeScheduledAt(),
            'photographer_id' => $photographer->id,
            'notify_client' => false,
            'notify_photographer' => false,
        ]);

        Queue::assertPushed(
            CreateCubiCasaOrderJob::class,
            fn (CreateCubiCasaOrderJob $job) => $job->shootId === $shoot->id
        );
        // Exactly one: the observer, not the observer plus a direct dispatch.
        Queue::assertPushed(CreateCubiCasaOrderJob::class, 1);
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 5.2 (example): approving a
     * requested shoot that has NO CubiCasa-eligible service must NOT dispatch
     * the job.
     *
     * The shoot is requested + unlinked, so the only reason the job is not
     * dispatched is the eligibility gate.
     *
     * Validates: Requirements 2.3
     */
    public function test_approval_does_not_dispatch_when_not_eligible(): void
    {
        Queue::fake();
        $this->bindApprovalSideEffectFakes();

        [$admin, $client, $photographer] = $this->approvalActors();

        $shoot = $this->requestedShoot($client);
        // Attach a non-CubiCasa service so the shoot has a service but is not
        // eligible.
        $this->attachIneligibleService($shoot);

        $this->approveShoot($shoot, $admin, [
            'scheduled_at' => $this->safeScheduledAt(),
            'photographer_id' => $photographer->id,
            'notify_client' => false,
            'notify_photographer' => false,
        ]);

        $shoot->refresh();
        $this->assertFalse(
            $shoot->hasCubiCasaEligibleService(),
            'Fixture sanity: the approved shoot must not have an eligible service.'
        );

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 5.2 (example): approving a
     * requested, eligible shoot that is ALREADY linked (cubicasa_order_id or
     * cubicasa_external_id set) must NOT dispatch the job.
     *
     * The shoot is requested + eligible, so the only reason the job is not
     * dispatched is the already-linked gate.
     *
     * Validates: Requirements 2.4
     */
    public function test_approval_does_not_dispatch_when_already_linked(): void
    {
        Queue::fake();
        $this->bindApprovalSideEffectFakes();

        [$admin, $client, $photographer] = $this->approvalActors();

        $shoot = $this->requestedShoot($client);
        $shoot->forceFill(['cubicasa_order_id' => 'existing-order-id'])->save();
        // Eligible so the only gate that can stop dispatch is already-linked.
        $this->attachCubicasaService($shoot);

        $this->approveShoot($shoot, $admin, [
            'scheduled_at' => $this->safeScheduledAt(),
            'photographer_id' => $photographer->id,
            'notify_client' => false,
            'notify_photographer' => false,
        ]);

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    /**
     * Feature: auto-create-cubicasa-order, Task 5.2 (example): when the
     * approval operation fails (the workflow approve() throws), the job must
     * NOT be dispatched.
     *
     * The shoot is requested + eligible + unlinked, so every OTHER gate would
     * pass; the dispatch line lives after the approve() call, so a thrown
     * approval is the only reason the job is not dispatched. We bind a workflow
     * service whose approve() throws and assert the exception propagates and no
     * job is queued.
     *
     * Validates: Requirements 2.5
     */
    public function test_approval_failure_does_not_dispatch(): void
    {
        Queue::fake();
        $this->bindApprovalSideEffectFakes();
        $this->bindFailingWorkflowService();

        [$admin, $client, $photographer] = $this->approvalActors();

        $shoot = $this->requestedShoot($client);
        // Eligible + unlinked: every gate other than approval success passes.
        $this->attachCubicasaService($shoot);

        try {
            $this->approveShoot($shoot, $admin, [
                'scheduled_at' => $this->safeScheduledAt(),
                'photographer_id' => $photographer->id,
                'notify_client' => false,
                'notify_photographer' => false,
            ]);
            $this->fail('A failed approval must propagate the exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'approval',
                strtolower($e->getMessage()),
                'The simulated approval failure exception should surface.'
            );
        }

        Queue::assertNotPushed(CreateCubiCasaOrderJob::class);
    }

    /**
     * Create the admin, client, and photographer users used by the Task 5.2
     * approval tests. Returned in [admin, client, photographer] order. Admins
     * skip the photographer-availability check during approval, so the
     * scheduled_at/photographer_id we pass are applied without availability
     * gating.
     *
     * @return array{0: User, 1: User, 2: User}
     */
    private function approvalActors(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin-5-2@test.com',
        ]);
        $client = User::factory()->create([
            'role' => 'client',
            'email' => 'client-5-2@test.com',
        ]);
        $photographer = User::factory()->create([
            'role' => 'photographer',
            'email' => 'photographer-5-2@test.com',
        ]);

        return [$admin, $client, $photographer];
    }

    /**
     * Create a REQUESTED, unlinked shoot owned by the given client with a
     * complete address. Callers attach an eligible/ineligible service and/or
     * adjust status as the case under test requires.
     */
    private function requestedShoot(User $client): Shoot
    {
        return Shoot::factory()->create([
            'client_id' => $client->id,
            'status' => Shoot::STATUS_REQUESTED,
            'workflow_status' => Shoot::STATUS_REQUESTED,
            'cubicasa_order_id' => null,
            'cubicasa_external_id' => null,
            'cubicasa_idempotency_key' => null,
            'address' => '88 Approval Ave',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'property_details' => [],
        ]);
    }

    /**
     * Invoke the real ApproveShootAction::execute() (the production approval
     * dispatch hook) with a constructed request, mirroring how
     * ShootMediaActionsTest drives its actions directly. Resolving the action
     * from the container picks up any side-effect/workflow fakes bound by the
     * caller.
     */
    private function approveShoot(Shoot $shoot, User $user, array $payload = []): Shoot
    {
        $request = Request::create("/api/shoots/{$shoot->id}/approve", 'POST', $payload);
        $request->setUserResolver(fn () => $user);

        return app(ApproveShootAction::class)->execute($request, $shoot, $user);
    }

    /**
     * Attach a non-CubiCasa service so the shoot has a service but
     * hasCubiCasaEligibleService() is false.
     */
    private function attachIneligibleService(Shoot $shoot): void
    {
        $service = Service::factory()->create(['name' => 'Standard Photography']);
        DB::table('shoot_service')->insert([
            'shoot_id' => $shoot->id,
            'service_id' => $service->id,
            'price' => 120,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Fake the heavy approval-flow collaborators (dropbox, invoicing, mail,
     * automation, availability) so the only behavior exercised is the dispatch
     * gate. Mirrors ShootMutationActionsTest::bindMutationSideEffectFakes().
     * Must be called BEFORE resolving ApproveShootAction.
     */
    private function bindApprovalSideEffectFakes(): void
    {
        $dropbox = Mockery::mock(ShootMediaStorageService::class);
        $dropbox->shouldIgnoreMissing();
        $dropbox->shouldReceive('createShootFolders')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(ShootMediaStorageService::class, $dropbox);

        $invoice = Mockery::mock(InvoiceService::class);
        $invoice->shouldIgnoreMissing();
        $invoice->shouldReceive('generateForShoot')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(InvoiceService::class, $invoice);

        $mail = Mockery::mock(MailService::class);
        $mail->shouldIgnoreMissing();
        $mail->shouldReceive('captureShootSnapshot')->zeroOrMoreTimes()->andReturn([]);
        $mail->shouldReceive('buildShootChangeSummary')->zeroOrMoreTimes()->andReturn([
            'summary' => '',
            'html' => '',
        ]);
        $mail->shouldReceive('generatePaymentLink')->zeroOrMoreTimes()->andReturn('https://example.test/payment');
        $mail->shouldReceive('sendShootScheduledEmail')->zeroOrMoreTimes()->andReturnTrue();
        $mail->shouldReceive('sendAssignedPhotographerShootScheduledEmails')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(MailService::class, $mail);

        $automation = Mockery::mock(AutomationService::class);
        $automation->shouldIgnoreMissing();
        $automation->shouldReceive('buildShootContext')->zeroOrMoreTimes()->andReturnUsing(
            fn (Shoot $shoot) => [
                'shoot' => $shoot,
                'shoot_id' => $shoot->id,
                'client' => $shoot->client,
                'photographer' => $shoot->photographer,
            ]
        );
        $automation->shouldReceive('handleEvent')->zeroOrMoreTimes()->andReturn([
            'client_email_sent' => false,
            'photographer_email_sent' => false,
        ]);
        $automation->shouldReceive('shouldUseFallback')->zeroOrMoreTimes()->andReturnFalse();
        $automation->shouldReceive('hasActiveTrigger')->zeroOrMoreTimes()->andReturnFalse();
        $this->app->instance(AutomationService::class, $automation);

        $availability = Mockery::mock(PhotographerAvailabilityService::class);
        $availability->shouldIgnoreMissing();
        $availability->shouldReceive('isAvailable')->zeroOrMoreTimes()->andReturnTrue();
        $this->app->instance(PhotographerAvailabilityService::class, $availability);
    }

    /**
     * Bind a workflow service whose approve() succeeds without performing a
     * real status transition, so execute() can run to the dispatch gate even
     * for a shoot that is not in the requested state. Must be called BEFORE
     * resolving ApproveShootAction.
     */
    private function bindNoopWorkflowService(): void
    {
        $workflow = Mockery::mock(ShootWorkflowService::class);
        $workflow->shouldIgnoreMissing();
        $workflow->shouldReceive('approve')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(ShootWorkflowService::class, $workflow);
    }

    /**
     * Bind a workflow service whose approve() throws, simulating a failed
     * approval. Must be called BEFORE resolving ApproveShootAction.
     */
    private function bindFailingWorkflowService(): void
    {
        $workflow = Mockery::mock(ShootWorkflowService::class);
        $workflow->shouldIgnoreMissing();
        $workflow->shouldReceive('approve')
            ->andThrow(new \RuntimeException('Simulated approval failure.'));
        $this->app->instance(ShootWorkflowService::class, $workflow);
    }
}
