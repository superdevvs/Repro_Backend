<?php

namespace Tests\Feature;

use App\Models\Shoot;
use App\Models\User;
use App\Services\CubiCasaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 31:
 * Per-shoot idempotency key prevents duplicate CubiCasa orders.
 *
 * Validates: Requirements 19.6
 *
 * For any shoot and any sequence of N >= 1 manual create attempts (with any
 * mix of upstream success/failure responses), the following invariants hold:
 *
 *   (a) The persisted shoots.cubicasa_idempotency_key is set on the first
 *       create attempt and never changes across subsequent attempts on the
 *       same shoot — even after upstream failures (502/404/...).
 *
 *   (b) Every POST /orders the service issues carries an Idempotency-Key
 *       header equal to that single persisted value, so the upstream
 *       provider can de-duplicate retried create requests.
 *
 *   (c) Once an attempt has linked the shoot (cubicasa_order_id is set),
 *       every subsequent createOrder call SYNCs the existing order via
 *       GET /orders/{id} instead of issuing a new POST /orders — the
 *       provider never receives a duplicate create for the same shoot.
 *
 * Approach: no PHP property-based testing library is configured for the
 * backend, so the test follows the spec's "deterministic generator" strategy
 * already used by other property tests in this suite (see
 * ShootEditingPayloadFilteringPropertyTest, PaymentReminderCadencePropertyTest):
 * a seeded PRNG produces 25 randomized cases (random N in 1..5, random outcome
 * sequences combining 200/502/404 responses) plus 5 deterministic edge cases
 * (single success, two failures then success, success then duplicate-create
 * calls, single 404 failure, mixed-failure-then-success). The same universal
 * invariants must hold for every generated input.
 */
class CubiCasaPerShootIdempotencyPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://app.cubi.casa/api/integrate/v3';
    private const NEW_ORDER_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    /** Spec mandates >= 25 randomized cases. */
    private const RANDOM_ITERATIONS = 25;

    /** Fixed seed so failures reproduce; bump if a counterexample is fixed. */
    private const SEED = 31_31_31;

    /** The three observable outcomes for a POST /orders attempt. */
    private const OUTCOME_SUCCESS = 'success';
    private const OUTCOME_FAIL_502 = 'fail_502';
    private const OUTCOME_FAIL_404 = 'fail_404';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.base_url', self::BASE_URL);
        config()->set('services.cubicasa.environment', 'production');
    }

    /**
     * Generator: 25 randomized + 5 deterministic outcome sequences.
     *
     * Each entry is the ordered list of outcomes for the N create attempts
     * the test will issue against a single shoot. Sequence length and the
     * mix of success/failure responses are randomized to exercise the
     * property across the input space.
     *
     * @return list<list<string>>
     */
    private function outcomeSequenceGenerator(): array
    {
        mt_srand(self::SEED);

        $outcomeSet = [self::OUTCOME_SUCCESS, self::OUTCOME_FAIL_502, self::OUTCOME_FAIL_404];

        $sequences = [];
        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $n = mt_rand(1, 5);
            $seq = [];
            for ($j = 0; $j < $n; $j++) {
                $seq[] = $outcomeSet[mt_rand(0, 2)];
            }
            $sequences[] = $seq;
        }

        // Deterministic edge cases — explicitly listed in the spec/task.
        $sequences[] = [self::OUTCOME_SUCCESS]; // single success
        $sequences[] = [self::OUTCOME_FAIL_502, self::OUTCOME_FAIL_502, self::OUTCOME_SUCCESS]; // two failures then success
        $sequences[] = [self::OUTCOME_SUCCESS, self::OUTCOME_SUCCESS, self::OUTCOME_SUCCESS]; // success then duplicate-create call
        $sequences[] = [self::OUTCOME_FAIL_404]; // single 404 failure
        $sequences[] = [self::OUTCOME_FAIL_502, self::OUTCOME_FAIL_404, self::OUTCOME_SUCCESS]; // mixed failures then success

        return $sequences;
    }

    private function successPayload(): array
    {
        return [
            'id' => self::NEW_ORDER_ID,
            'info' => [
                'external_id' => 'shoot-prop-31',
                'status' => 'New',
                'order_type' => 'Tier3-LiDAR',
            ],
            'address' => [
                'full_address' => '521 Brightfield Road',
            ],
        ];
    }

    /**
     * Build the sequenced POST /orders responder for a single iteration.
     *
     * Only outcomes up to and including the first success contribute a
     * response — once the shoot is linked, subsequent createOrder calls
     * take the sync path and never POST /orders again.
     */
    private function buildPostOrdersSequence(array $outcomes, int $expectedPosts): \Illuminate\Http\Client\ResponseSequence
    {
        $sequence = Http::sequence();
        for ($i = 0; $i < $expectedPosts; $i++) {
            $sequence = match ($outcomes[$i]) {
                self::OUTCOME_SUCCESS => $sequence->push($this->successPayload(), 200),
                self::OUTCOME_FAIL_502 => $sequence->push(['message' => 'upstream error'], 502),
                self::OUTCOME_FAIL_404 => $sequence->push(['message' => 'not found'], 404),
            };
        }

        return $sequence;
    }

    /**
     * Pre-compute how many POST /orders requests createOrder will issue for
     * a given outcome sequence: every attempt before (and including) the
     * first success is a POST; everything after the first success is a
     * sync (no POST). This mirrors the AC 19.5 / AC 19.6 contract under test.
     */
    private function expectedPostCount(array $outcomes): int
    {
        $count = 0;
        foreach ($outcomes as $outcome) {
            $count++;
            if ($outcome === self::OUTCOME_SUCCESS) {
                break;
            }
        }

        return $count;
    }

    /**
     * Property 31 — for every (N attempts, outcome sequence) input the three
     * invariants (a)/(b)/(c) above hold simultaneously.
     *
     * Validates: Requirements 19.6
     */
    public function test_per_shoot_idempotency_key_prevents_duplicate_cubicasa_orders(): void
    {
        $sequences = $this->outcomeSequenceGenerator();

        foreach ($sequences as $seqIndex => $outcomes) {
            $expectedPosts = $this->expectedPostCount($outcomes);

            // Swap in a fresh Http factory so callbacks/sequences from the
            // prior iteration cannot leak: Http::fake() merges new stubs onto
            // the existing list rather than replacing them, and an exhausted
            // sequence from a previous iteration would otherwise throw on the
            // next iteration's first request and be silently caught by
            // createOrder's try/catch.
            Http::swap(new HttpFactory(
                $this->app->bound('events') ? $this->app->make('events') : null
            ));

            // Re-fake on every iteration: Http::fake() resets the recorded
            // request log so per-iteration counts/headers stay isolated.
            Http::fake([
                // Sync path for an already-linked shoot — return a successful
                // payload so the post-success attempts complete their sync.
                self::BASE_URL . '/orders/*' => Http::response($this->successPayload(), 200),
                // Create path — sequenced according to the iteration's outcomes.
                self::BASE_URL . '/orders' => $this->buildPostOrdersSequence($outcomes, $expectedPosts),
            ]);

            $actor = User::factory()->create(['role' => 'admin']);
            $shoot = Shoot::factory()->create([
                'cubicasa_order_id' => null,
                'cubicasa_external_id' => null,
                'cubicasa_idempotency_key' => null,
                'address' => '521 Brightfield Road',
                'city' => 'Ottawa',
                'state' => 'ON',
                'zip' => 'K1A0B1',
            ]);

            $context = sprintf(
                'iteration %d, outcomes=%s, expectedPosts=%d',
                $seqIndex,
                json_encode($outcomes),
                $expectedPosts
            );

            $service = app(CubiCasaService::class);
            $persistedKeyAfterFirstAttempt = null;

            // Drive N create attempts back-to-back on the same shoot.
            for ($attempt = 0; $attempt < count($outcomes); $attempt++) {
                $service->createOrder($shoot->fresh(), $actor);

                $current = $shoot->fresh();

                // (a) The idempotency key is set after the first attempt and
                //     never changes across subsequent attempts — even when
                //     upstream returns 502/404 in between.
                $this->assertNotEmpty(
                    $current->cubicasa_idempotency_key,
                    "[a] persisted cubicasa_idempotency_key must be set after attempt #{$attempt} for {$context}"
                );

                if ($attempt === 0) {
                    $persistedKeyAfterFirstAttempt = $current->cubicasa_idempotency_key;
                } else {
                    $this->assertSame(
                        $persistedKeyAfterFirstAttempt,
                        $current->cubicasa_idempotency_key,
                        "[a] persisted cubicasa_idempotency_key must not change across attempts for {$context}"
                    );
                }
            }

            // (b) Every POST /orders carried the persisted Idempotency-Key.
            //     Counted directly from the recorded request log so a stray
            //     POST without the header (or with a different value) is
            //     surfaced as a counterexample rather than silently ignored.
            $postOrdersRequests = Http::recorded(function ($request) {
                return $request->method() === 'POST'
                    && $request->url() === self::BASE_URL . '/orders';
            });

            $this->assertCount(
                $expectedPosts,
                $postOrdersRequests,
                "[c] exactly {$expectedPosts} POST /orders request(s) should be issued for {$context}"
            );

            foreach ($postOrdersRequests as $idx => [$request, $_response]) {
                $this->assertTrue(
                    $request->hasHeader('Idempotency-Key', $persistedKeyAfterFirstAttempt),
                    "[b] POST /orders request #{$idx} must carry Idempotency-Key={$persistedKeyAfterFirstAttempt} for {$context}"
                );
            }

            // (c) After the shoot becomes linked (first success), every
            //     remaining attempt syncs via GET /orders/{id} — never a
            //     duplicate POST. This is implied by the count assertion
            //     above, but assert it explicitly so the failure message
            //     points at the duplicate-creation defect directly.
            $hasSuccess = in_array(self::OUTCOME_SUCCESS, $outcomes, true);
            if ($hasSuccess) {
                $remainingAttempts = count($outcomes) - $expectedPosts;
                $syncGetRequests = Http::recorded(function ($request) {
                    return $request->method() === 'GET'
                        && str_starts_with($request->url(), self::BASE_URL . '/orders/');
                });

                $this->assertCount(
                    $remainingAttempts,
                    $syncGetRequests,
                    "[c] post-link attempts must sync via GET /orders/{id} (no duplicate POST) for {$context}"
                );
            }
        }
    }
}
