<?php

namespace Tests\Feature;

use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\AccountStatusService;
use App\Services\CubiCasaService;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use App\Services\RolePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 24: Audited actions write exactly one Audit_Log entry
 *
 * **Validates: Requirements 12.9, 16.7, 18.4, 19.10**
 *
 * For any manual notification send (Req 12.9), account lock/delete (Req 16.7), account-type
 * conversion (Req 18.4), or manual CubiCasa create/sync (Req 19.10), performing the action
 * writes EXACTLY ONE Audit_Log entry (a row on `user_activity_logs`, the table the
 * {@see \App\Services\AuditLogService} facade writes through). Every entry has the uniform
 * audited-action shape:
 *
 *   - actor       → `actor_user_id` equals the user who performed the action
 *   - timestamp   → `occurred_at` (and `created_at`) are set
 *   - target      → `target_type` + `target_id` address the model the action concerns
 *   - action      → `event_type` is the stable action identifier
 *   - metadata    → `metadata` is a (possibly empty) array of action-specific context
 *
 * Two universal sub-properties are asserted across the input space:
 *
 *   (1) Exactly-one-per-invocation — for every audited action variant
 *       (notification.manual_send, account.locked, account.deleted,
 *       account.type_converted, cubicasa.manual_create, cubicasa.manual_sync),
 *       a single invocation on a fresh target increases the count of audit rows
 *       matching (event_type, actor, target) by exactly one — never zero, never
 *       many — and the new row has the uniform shape above.
 *
 *   (2) Exactly-one-per-action under repetition — for the safely-repeatable
 *       variants, performing the action R >= 2 times on the same target writes
 *       exactly R entries (one per action), proving the logging is per-action
 *       rather than zero, batched, or unbounded.
 *
 * Approach: no PHP property-based-testing library is configured for the backend, so this test
 * follows the established "deterministic generator + strong randomization" strategy used by the
 * other property tests in this suite (see ManualDispatchMappedTemplatePropertyTest,
 * AccountTypeConversionPropertyTest, CubiCasaPerShootIdempotencyPropertyTest). Sub-property (1)
 * exhaustively covers all six action variants and then re-rolls 30 randomized picks across the
 * variant set (each on fresh state); sub-property (2) drives 12 randomized repetition counts
 * across the repeatable variants. The same invariant must hold for every generated input.
 *
 * External dispatch is mocked so the test is hermetic: MessagingService is a Mockery double
 * (no real email/SMS), and the CubiCasa provider is faked via Http::fake (no real HTTP).
 */
class AuditedActionLoggingPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const CUBICASA_BASE_URL = 'https://app.cubi.casa/api/integrate/v3';

    /** Sub-property (1): randomized single-invocation picks across all variants. */
    private const SINGLE_RANDOM_ITERATIONS = 30;

    /** Sub-property (2): randomized repetition cases across repeatable variants. */
    private const REPEAT_RANDOM_ITERATIONS = 12;

    /** The audited-action variants exercised by sub-property (1). */
    private const VARIANT_MANUAL_SEND = 'notification.manual_send';
    private const VARIANT_ACCOUNT_LOCKED = 'account.locked';
    private const VARIANT_ACCOUNT_DELETED = 'account.deleted';
    private const VARIANT_TYPE_CONVERTED = 'account.type_converted';
    private const VARIANT_CUBICASA_CREATE = 'cubicasa.manual_create';
    private const VARIANT_CUBICASA_SYNC = 'cubicasa.manual_sync';

    /** Variants that are safe to repeat back-to-back on the same target. */
    private const REPEATABLE_VARIANTS = [
        self::VARIANT_MANUAL_SEND,
        self::VARIANT_TYPE_CONVERTED,
        self::VARIANT_CUBICASA_SYNC,
    ];

    /** id of the seeded MessageTemplate per slug (for manual_send). */
    private array $templateIdBySlug = [];

    protected function setUp(): void
    {
        parent::setUp();

        // CubiCasa provider config so createOrder()'s credential gate passes.
        config()->set('services.cubicasa.api_key', 'test-key');
        config()->set('services.cubicasa.owner_email', 'orders@reprophotos.com');
        config()->set('services.cubicasa.base_url', self::CUBICASA_BASE_URL);
        config()->set('services.cubicasa.environment', 'production');

        // Seed exactly one active template per manual notification type → slug so
        // ManualNotificationService::resolveTemplate() resolves unambiguously. Delete any
        // pre-seeded rows (MessagingSystemSeeder may create some) so the slug is test-owned.
        MessageTemplate::query()
            ->whereIn('slug', array_values(ManualNotificationService::TYPES))
            ->delete();

        foreach (ManualNotificationService::TYPES as $slug) {
            $template = MessageTemplate::create([
                'channel'     => 'EMAIL',
                'name'        => ucfirst(str_replace('-', ' ', $slug)),
                'slug'        => $slug,
                'description' => null,
                'category'    => 'GENERAL',
                'subject'     => 'Subject for ' . $slug,
                'body_html'   => '<p>Hello {{recipient_first_name}} (' . $slug . ')</p>',
                'body_text'   => 'Hello {{recipient_first_name}} (' . $slug . ')',
                'scope'       => 'SYSTEM',
                'is_system'   => true,
                'is_active'   => true,
            ]);
            $this->templateIdBySlug[$slug] = $template->id;
        }
    }

    // -----------------------------------------------------------------------------------------
    // Generators
    // -----------------------------------------------------------------------------------------

    /**
     * Generator for sub-property (1): every variant once (deterministic) + randomized picks.
     *
     * @return list<string>
     */
    private function singleInvocationVariants(): array
    {
        $variants = [
            self::VARIANT_MANUAL_SEND,
            self::VARIANT_ACCOUNT_LOCKED,
            self::VARIANT_ACCOUNT_DELETED,
            self::VARIANT_TYPE_CONVERTED,
            self::VARIANT_CUBICASA_CREATE,
            self::VARIANT_CUBICASA_SYNC,
        ];

        $cases = $variants; // deterministic: each variant at least once.

        mt_srand(24_24_24);
        for ($i = 0; $i < self::SINGLE_RANDOM_ITERATIONS; $i++) {
            $cases[] = $variants[mt_rand(0, count($variants) - 1)];
        }

        return $cases;
    }

    /**
     * Generator for sub-property (2): (variant, repetitionCount) cases.
     *
     * @return list<array{0:string,1:int}>
     */
    private function repetitionCases(): array
    {
        // Deterministic: each repeatable variant with a fixed small count.
        $cases = [
            [self::VARIANT_MANUAL_SEND, 2],
            [self::VARIANT_TYPE_CONVERTED, 3],
            [self::VARIANT_CUBICASA_SYNC, 2],
        ];

        mt_srand(99_24_99);
        for ($i = 0; $i < self::REPEAT_RANDOM_ITERATIONS; $i++) {
            $variant = self::REPEATABLE_VARIANTS[mt_rand(0, count(self::REPEATABLE_VARIANTS) - 1)];
            $cases[] = [$variant, mt_rand(2, 5)];
        }

        return $cases;
    }

    // -----------------------------------------------------------------------------------------
    // Sub-property (1): exactly one audit entry per single invocation
    // -----------------------------------------------------------------------------------------

    /**
     * Property 24 (1) — every audited action variant writes exactly one Audit_Log entry per
     * invocation, with the uniform (actor, timestamp, target, action, metadata) shape.
     *
     * Validates: Requirements 12.9, 16.7, 18.4, 19.10
     */
    public function test_each_audited_action_writes_exactly_one_audit_entry(): void
    {
        foreach ($this->singleInvocationVariants() as $i => $variant) {
            $context = sprintf('iteration %d (variant=%s)', $i, $variant);

            $before = UserActivityLog::query()->where('event_type', $variant)->count();

            // Perform the action exactly once; the runner returns the expected
            // (actor id, target type, target id) descriptor for the entry.
            $descriptor = $this->performAction($variant);

            // ---- Exactly one new row for this event_type. ----
            $after = UserActivityLog::query()->where('event_type', $variant)->count();
            $this->assertSame(
                $before + 1,
                $after,
                "exactly one '{$variant}' audit entry must be written per invocation for {$context}"
            );

            // ---- The new row matches the action's actor + target, and has uniform shape. ----
            $entry = UserActivityLog::query()
                ->where('event_type', $variant)
                ->where('actor_user_id', $descriptor['actor_id'])
                ->where('target_type', $descriptor['target_type'])
                ->where('target_id', $descriptor['target_id'])
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull(
                $entry,
                "an audit entry addressing the action's actor+target must exist for {$context}"
            );

            // Precisely one row for this (event_type, actor, target) tuple — not many.
            $this->assertSame(
                1,
                UserActivityLog::query()
                    ->where('event_type', $variant)
                    ->where('actor_user_id', $descriptor['actor_id'])
                    ->where('target_type', $descriptor['target_type'])
                    ->where('target_id', $descriptor['target_id'])
                    ->count(),
                "exactly one entry for this (action, actor, target) tuple for {$context}"
            );

            $this->assertUniformShape($entry, $variant, $descriptor, $context);
        }
    }

    // -----------------------------------------------------------------------------------------
    // Sub-property (2): exactly one audit entry per action under repetition
    // -----------------------------------------------------------------------------------------

    /**
     * Property 24 (2) — performing a repeatable audited action R times writes exactly R entries
     * (one per action), proving the logging is per-action rather than zero, batched, or unbounded.
     *
     * Validates: Requirements 12.9, 18.4, 19.10
     */
    public function test_repeated_audited_actions_write_one_entry_each(): void
    {
        foreach ($this->repetitionCases() as $i => [$variant, $repetitions]) {
            $context = sprintf('iteration %d (variant=%s, repetitions=%d)', $i, $variant, $repetitions);

            $before = UserActivityLog::query()->where('event_type', $variant)->count();

            $this->performActionRepeatedly($variant, $repetitions);

            $after = UserActivityLog::query()->where('event_type', $variant)->count();
            $this->assertSame(
                $before + $repetitions,
                $after,
                "{$repetitions} invocations of '{$variant}' must write exactly {$repetitions} audit entries for {$context}"
            );
        }
    }

    // -----------------------------------------------------------------------------------------
    // Shared shape assertion
    // -----------------------------------------------------------------------------------------

    /**
     * Assert the uniform audited-action shape: actor, timestamp, target, action, metadata.
     *
     * @param  array{actor_id:?int,target_type:?string,target_id:mixed}  $descriptor
     */
    private function assertUniformShape(
        UserActivityLog $entry,
        string $variant,
        array $descriptor,
        string $context
    ): void {
        // action
        $this->assertSame($variant, $entry->event_type, "event_type (action) must be set for {$context}");

        // actor
        $this->assertSame(
            $descriptor['actor_id'],
            $entry->actor_user_id,
            "actor_user_id must equal the acting user for {$context}"
        );

        // timestamp
        $this->assertNotNull($entry->occurred_at, "occurred_at (timestamp) must be set for {$context}");
        $this->assertNotNull($entry->created_at, "created_at must be set for {$context}");

        // target
        $this->assertSame(
            $descriptor['target_type'],
            $entry->target_type,
            "target_type must address the action's target for {$context}"
        );
        $this->assertSame(
            (int) $descriptor['target_id'],
            (int) $entry->target_id,
            "target_id must address the action's target for {$context}"
        );

        // metadata
        $this->assertIsArray($entry->metadata, "metadata must be an array for {$context}");
    }

    // -----------------------------------------------------------------------------------------
    // Action runners — each performs ONE audited action and returns its (actor, target) descriptor
    // -----------------------------------------------------------------------------------------

    /**
     * @return array{actor_id:?int,target_type:?string,target_id:mixed}
     */
    private function performAction(string $variant): array
    {
        return match ($variant) {
            self::VARIANT_MANUAL_SEND     => $this->runManualSend(),
            self::VARIANT_ACCOUNT_LOCKED  => $this->runAccountStatus(AccountStatusService::STATUS_LOCKED),
            self::VARIANT_ACCOUNT_DELETED => $this->runAccountStatus(AccountStatusService::STATUS_DELETED),
            self::VARIANT_TYPE_CONVERTED  => $this->runTypeConversion(),
            self::VARIANT_CUBICASA_CREATE => $this->runCubicasaCreate(),
            self::VARIANT_CUBICASA_SYNC   => $this->runCubicasaSync(),
        };
    }

    private function performActionRepeatedly(string $variant, int $repetitions): void
    {
        match ($variant) {
            self::VARIANT_MANUAL_SEND   => $this->runManualSendRepeatedly($repetitions),
            self::VARIANT_TYPE_CONVERTED => $this->runTypeConversionRepeatedly($repetitions),
            self::VARIANT_CUBICASA_SYNC => $this->runCubicasaSyncRepeatedly($repetitions),
        };
    }

    // ---- notification.manual_send ----

    /** @return array{actor_id:?int,target_type:?string,target_id:mixed} */
    private function runManualSend(): array
    {
        $this->mockMessagingServiceAllowingAnySend();

        $sender = User::factory()->create(['role' => 'admin']);
        $shoot = $this->shootWithContactableParties();

        $type = array_rand(ManualNotificationService::TYPES);
        $recipient = ['client', 'photographer'][mt_rand(0, 1)];
        $channel = ['email', 'sms'][mt_rand(0, 1)];

        app(ManualNotificationService::class)->send($shoot, $type, $recipient, $channel, $sender);

        return ['actor_id' => $sender->id, 'target_type' => Shoot::class, 'target_id' => $shoot->id];
    }

    private function runManualSendRepeatedly(int $repetitions): void
    {
        $this->mockMessagingServiceAllowingAnySend();

        $sender = User::factory()->create(['role' => 'admin']);
        $shoot = $this->shootWithContactableParties();
        $service = app(ManualNotificationService::class);

        for ($r = 0; $r < $repetitions; $r++) {
            $type = array_rand(ManualNotificationService::TYPES);
            $service->send($shoot, $type, 'client', 'email', $sender);
        }
    }

    // ---- account.locked / account.deleted ----

    /** @return array{actor_id:?int,target_type:?string,target_id:mixed} */
    private function runAccountStatus(string $status): array
    {
        // Distinct admin actor and a non-admin target so the self-action and
        // admin-delete safety guards never trip (those paths throw before audit).
        $actor = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'photographer']);

        app(AccountStatusService::class)->setStatus($target, $status, $actor);

        return ['actor_id' => $actor->id, 'target_type' => User::class, 'target_id' => $target->id];
    }

    // ---- account.type_converted (via HTTP, through role: middleware) ----

    /** @return array{actor_id:?int,target_type:?string,target_id:mixed} */
    private function runTypeConversion(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $roles = app(RolePermissionService::class)->roleIds();
        $source = 'photographer';
        $target = $this->pickDifferentRole($roles, $source);

        $user = User::factory()->create(['role' => $source]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/users/{$user->id}/convert-type", ['account_type' => $target])
            ->assertOk();

        return ['actor_id' => $admin->id, 'target_type' => User::class, 'target_id' => $user->id];
    }

    private function runTypeConversionRepeatedly(int $repetitions): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $roles = array_values(app(RolePermissionService::class)->roleIds());
        $user = User::factory()->create(['role' => 'photographer']);

        Sanctum::actingAs($admin);

        for ($r = 0; $r < $repetitions; $r++) {
            // Cycle deterministically through defined roles; each conversion
            // writes one audit entry (even a same-role conversion does).
            $target = $roles[$r % count($roles)];
            $this->patchJson("/api/admin/users/{$user->id}/convert-type", ['account_type' => $target])
                ->assertOk();
        }
    }

    // ---- cubicasa.manual_create ----

    /** @return array{actor_id:?int,target_type:?string,target_id:mixed} */
    private function runCubicasaCreate(): array
    {
        $this->fakeCubicasa();

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = $this->cubicasaShoot(linked: false);

        app(CubiCasaService::class)->createOrder($shoot, $actor);

        return ['actor_id' => $actor->id, 'target_type' => Shoot::class, 'target_id' => $shoot->id];
    }

    // ---- cubicasa.manual_sync ----

    /** @return array{actor_id:?int,target_type:?string,target_id:mixed} */
    private function runCubicasaSync(): array
    {
        $this->fakeCubicasa();

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = $this->cubicasaShoot(linked: true);

        app(CubiCasaService::class)->createOrder($shoot, $actor);

        return ['actor_id' => $actor->id, 'target_type' => Shoot::class, 'target_id' => $shoot->id];
    }

    private function runCubicasaSyncRepeatedly(int $repetitions): void
    {
        $this->fakeCubicasa();

        $actor = User::factory()->create(['role' => 'admin']);
        $shoot = $this->cubicasaShoot(linked: true);
        $service = app(CubiCasaService::class);

        for ($r = 0; $r < $repetitions; $r++) {
            $service->createOrder($shoot->fresh(), $actor);
        }
    }

    // -----------------------------------------------------------------------------------------
    // Hermetic fixtures / mocks
    // -----------------------------------------------------------------------------------------

    /**
     * Mock MessagingService so manual sends never hit a real transport, allowing any number of
     * sendEmail/sendSms calls. Returns a SENT Message so send() proceeds to the audit step.
     */
    private function mockMessagingServiceAllowingAnySend(): void
    {
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $reply = function (array $payload, string $channel) {
                return \App\Models\Message::make([
                    'channel'    => $channel,
                    'to_address' => $payload['to'] ?? '',
                    'status'     => 'SENT',
                ]);
            };
            $mock->shouldReceive('sendEmail')->andReturnUsing(fn (array $p) => $reply($p, 'EMAIL'));
            $mock->shouldReceive('sendSms')->andReturnUsing(fn (array $p) => $reply($p, 'SMS'));
        });
    }

    /** A Shoot whose client and photographer both have email + phone for any recipient/channel. */
    private function shootWithContactableParties(): Shoot
    {
        $client = User::factory()->create([
            'email'       => 'client+' . uniqid('', true) . '@example.com',
            'phonenumber' => '+1555' . str_pad((string) mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
            'name'        => 'Casey Client',
        ]);
        $photographer = User::factory()->create([
            'email'       => 'photog+' . uniqid('', true) . '@example.com',
            'phonenumber' => '+1555' . str_pad((string) mt_rand(1000000, 9999999), 7, '0', STR_PAD_LEFT),
            'name'        => 'Pat Photographer',
            'role'        => 'photographer',
        ]);

        return Shoot::factory()->create([
            'client_id'       => $client->id,
            'photographer_id' => $photographer->id,
        ]);
    }

    /** A shoot for CubiCasa create (unlinked) or sync (already linked). */
    private function cubicasaShoot(bool $linked): Shoot
    {
        return Shoot::factory()->create([
            'cubicasa_order_id'        => $linked ? 'existing-order-id' : null,
            'cubicasa_external_id'     => $linked ? 'shoot-existing' : null,
            'cubicasa_idempotency_key' => null,
            'address' => '521 Brightfield Road',
            'city'    => 'Ottawa',
            'state'   => 'ON',
            'zip'     => 'K1A0B1',
        ]);
    }

    /** Fake the CubiCasa provider so both create (POST /orders) and sync (GET /orders/{id}) succeed. */
    private function fakeCubicasa(): void
    {
        // Fresh factory so stubs from a prior iteration cannot leak.
        Http::swap(new HttpFactory(
            $this->app->bound('events') ? $this->app->make('events') : null
        ));

        $payload = [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'info' => [
                'external_id' => 'shoot-prop-24',
                'status'      => 'New',
                'order_type'  => 'Tier3-LiDAR',
            ],
            'address' => ['full_address' => '521 Brightfield Road'],
        ];

        Http::fake([
            self::CUBICASA_BASE_URL . '/orders/*' => Http::response($payload, 200),
            self::CUBICASA_BASE_URL . '/orders/draft'   => Http::response($payload, 200),
        ]);
    }

    /**
     * @param  array<int|string, string>  $roles
     */
    private function pickDifferentRole(array $roles, string $source): string
    {
        $roles = array_values($roles);
        $candidates = array_values(array_filter($roles, fn (string $r) => $r !== $source));

        return $candidates[mt_rand(0, count($candidates) - 1)];
    }
}
