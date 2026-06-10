<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 7: Manual notifications dispatch via the mapped template
 *
 * Validates: Requirements 12.1, 12.2
 *
 * For every notification type defined on {@see ManualNotificationService::TYPES},
 * {@see ManualNotificationService::send()} dispatches a {@see Message} via
 * {@see MessagingService} where the resolved template's slug equals the mapped slug
 * for that type. Unknown types throw {@see InvalidArgumentException} and dispatch
 * nothing.
 *
 * Three universal sub-properties are asserted across the input space:
 *
 *   (A) Type → template mapping (Req 12.2) — for every key `T` in
 *       `ManualNotificationService::TYPES`, sending a manual notification of
 *       type `T` dispatches a Message whose payload's `template_id` references
 *       a {@see MessageTemplate} with `slug === ManualNotificationService::TYPES[T]`.
 *
 *   (B) Dispatch via MessagingService (Req 12.1) — sending routes through
 *       MessagingService::sendEmail (channel=email) or MessagingService::sendSms
 *       (channel=sms) — i.e. the existing SystemEmails/messaging infrastructure —
 *       and the captured payload carries `send_source = MANUAL` and the shoot id.
 *
 *   (C) Unknown types reject (Req 12.2) — any type string outside the TYPES
 *       map raises InvalidArgumentException, and no Message is dispatched.
 *
 * Because no PHP property-based-testing library is installed in this project,
 * the test follows the spec's "strong randomization plus deterministic edge
 * cases" approach. The valid-type space is small and finite (six types, two
 * recipients, two channels = 24 combinations); we exhaustively cover all 24
 * deterministic combinations and add 30 randomized cases that vary the
 * (type, recipient, channel) tuple. The unknown-type space is unbounded; we
 * cover six deterministic edge strings (empty, whitespace, near-misses,
 * case-shift, hyphenated slug used as a key) plus 20 random gibberish strings.
 * The same universal property must hold for every generated input.
 */
class ManualDispatchMappedTemplatePropertyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Spec mandates >= 20 randomized cases for valid types and unknown types.
     */
    private const VALID_RANDOM_ITERATIONS = 30;
    private const UNKNOWN_RANDOM_ITERATIONS = 20;

    private const RECIPIENT_TYPES = ['client', 'photographer'];
    private const CHANNELS = ['email', 'sms'];

    /** id of the seeded MessageTemplate per slug. */
    private array $idBySlug = [];

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = User::factory()->create(['role' => 'admin']);

        // Seed exactly one active template per defined type → slug mapping so
        // ManualNotificationService::resolveTemplate() resolves unambiguously.
        // Some migrations re-run MessagingSystemSeeder which pre-creates a
        // few of these slugs (e.g. shoot-scheduled, shoot-ready); delete any
        // pre-seeded row for our mapped slugs first so the property assertion
        // grounds in a single, test-owned template per slug.
        // The template body intentionally references {{recipient_first_name}}
        // and the payment-due template references {{payment_link}} so the
        // renderer pipeline (resolver + renderer) is exercised end to end —
        // the property is about the *mapping*, but the test must still go
        // through the real send path.
        MessageTemplate::query()
            ->whereIn('slug', array_values(ManualNotificationService::TYPES))
            ->delete();

        foreach (ManualNotificationService::TYPES as $type => $slug) {
            $template = MessageTemplate::create([
                'channel'     => 'EMAIL',
                'name'        => ucfirst(str_replace('-', ' ', $slug)),
                'slug'        => $slug,
                'description' => null,
                'category'    => 'GENERAL',
                'subject'     => 'Subject for ' . $slug,
                'body_html'   => $slug === 'payment-due'
                    ? '<p>Hello {{recipient_first_name}}, pay at {{payment_link}}</p>'
                    : '<p>Hello {{recipient_first_name}} ('.$slug.')</p>',
                'body_text'   => $slug === 'payment-due'
                    ? 'Hello {{recipient_first_name}}, pay at {{payment_link}}'
                    : 'Hello {{recipient_first_name}} ('.$slug.')',
                'scope'       => 'SYSTEM',
                'is_system'   => true,
                'is_active'   => true,
            ]);
            $this->idBySlug[$slug] = $template->id;
        }
    }

    /**
     * Generator: 24 deterministic + 30 randomized (type, recipient, channel) tuples.
     *
     * The 24 deterministic cases are the full Cartesian product of the six
     * defined types × two recipient types × two channels — exhaustive coverage
     * of the small finite valid input space. The 30 randomized cases re-roll
     * the tuple to surface any iteration-order bug or hidden state coupling
     * (e.g. a stale mock binding, an audit-log key collision, or an unintended
     * dependency on a previous iteration's persisted Message row).
     *
     * @return list<array{0:string,1:string,2:string}>  list of [type, recipient, channel]
     */
    private function validCasesGenerator(): array
    {
        $cases = [];

        // Deterministic: every (type, recipient, channel) combination.
        foreach (array_keys(ManualNotificationService::TYPES) as $type) {
            foreach (self::RECIPIENT_TYPES as $recipient) {
                foreach (self::CHANNELS as $channel) {
                    $cases[] = [$type, $recipient, $channel];
                }
            }
        }

        // Randomized: cycle through all defined types so no type is starved,
        // then re-randomize recipient + channel.
        $types = array_keys(ManualNotificationService::TYPES);
        for ($i = 0; $i < self::VALID_RANDOM_ITERATIONS; $i++) {
            $cases[] = [
                $types[$i % count($types)],
                self::RECIPIENT_TYPES[mt_rand(0, count(self::RECIPIENT_TYPES) - 1)],
                self::CHANNELS[mt_rand(0, count(self::CHANNELS) - 1)],
            ];
        }

        return $cases;
    }

    /**
     * Generator: deterministic + randomized unknown-type strings.
     *
     * Edge strings cover structural extremes (empty, whitespace, near-miss
     * by case, near-miss by punctuation, the slug used as a key, and an
     * unrelated label). Random strings sample arbitrary gibberish so the
     * property holds across the full unknown-type input space.
     *
     * @return list<string>
     */
    private function unknownTypesGenerator(): array
    {
        $cases = [
            '',                  // empty
            '   ',               // whitespace only
            'SHOOT_SCHEDULED',   // case-shift (TYPES is keyed lowercase)
            'shoot-scheduled',   // the *slug* used as a *key*
            'shoot_canceled',    // common misspelling near-miss
            'invoice_due',       // unrelated label
        ];

        for ($i = 0; $i < self::UNKNOWN_RANDOM_ITERATIONS; $i++) {
            // 8-16 char gibberish that cannot collide with any defined key.
            $cases[] = 'rand_' . bin2hex(random_bytes(mt_rand(4, 8)));
        }

        // Sanity: defensively drop anything that — by astronomical luck —
        // collided with a defined key.
        $defined = array_keys(ManualNotificationService::TYPES);
        return array_values(array_filter(
            $cases,
            fn (string $t) => !in_array($t, $defined, true)
        ));
    }

    /**
     * Build a Shoot whose client and photographer both have a usable email
     * AND phone number, so any (recipient, channel) tuple in the test grid
     * resolves to a non-empty address.
     */
    private function shootForCase(): Shoot
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

    /**
     * Re-mock MessagingService for a single iteration. Captures the dispatch
     * method name and the payload passed to it so the assertions can verify
     * (a) the right send method was called for the channel and (b) the
     * captured `template_id` resolves to the slug mapped from the type.
     *
     * @param  array{method:?string,payload:?array<string,mixed>}  &$capture
     */
    private function mockMessagingForOneCall(array &$capture): void
    {
        $capture = ['method' => null, 'payload' => null];

        $this->mock(MessagingService::class, function (MockInterface $mock) use (&$capture): void {
            $mock->shouldReceive('sendEmail')
                ->withArgs(function (array $payload) use (&$capture): bool {
                    $capture['method'] = 'sendEmail';
                    $capture['payload'] = $payload;
                    return true;
                })
                ->andReturnUsing(function (array $payload): Message {
                    return Message::make([
                        'channel'    => 'EMAIL',
                        'to_address' => $payload['to'] ?? '',
                        'status'     => 'SENT',
                    ]);
                });

            $mock->shouldReceive('sendSms')
                ->withArgs(function (array $payload) use (&$capture): bool {
                    $capture['method'] = 'sendSms';
                    $capture['payload'] = $payload;
                    return true;
                })
                ->andReturnUsing(function (array $payload): Message {
                    return Message::make([
                        'channel'    => 'SMS',
                        'to_address' => $payload['to'] ?? '',
                        'status'     => 'SENT',
                    ]);
                });
        });
    }

    /**
     * The property: for any defined type × recipient × channel, send()
     * dispatches via the correct MessagingService method and the captured
     * payload's `template_id` references the MessageTemplate whose slug
     * equals the mapped slug for that type.
     *
     * Validates: Requirements 12.1, 12.2
     */
    public function test_send_dispatches_via_mapped_template_for_every_defined_type(): void
    {
        foreach ($this->validCasesGenerator() as $i => [$type, $recipient, $channel]) {
            $context = sprintf(
                'iteration %d (type=%s, recipient=%s, channel=%s)',
                $i,
                $type,
                $recipient,
                $channel
            );

            $shoot = $this->shootForCase();
            $capture = ['method' => null, 'payload' => null];
            $this->mockMessagingForOneCall($capture);

            // Resolve fresh per iteration AFTER mocking so the service's
            // injected MessagingService is the mock bound by mockMessagingForOneCall.
            $service = app(ManualNotificationService::class);
            $message = $service->send($shoot, $type, $recipient, $channel, $this->sender);

            // ----------------------------------------------------------------
            // (B) Dispatch routes through MessagingService::sendEmail or
            //     ::sendSms based on the channel param (Req 12.1).
            // ----------------------------------------------------------------
            $this->assertInstanceOf(
                Message::class,
                $message,
                "[B] send() must return a Message for {$context}"
            );
            $expectedMethod = $channel === 'sms' ? 'sendSms' : 'sendEmail';
            $this->assertSame(
                $expectedMethod,
                $capture['method'],
                "[B] channel={$channel} must route through MessagingService::{$expectedMethod} for {$context}"
            );
            $this->assertNotNull(
                $capture['payload'],
                "[B] MessagingService payload must be captured for {$context}"
            );

            // The dispatch payload identifies the manual send path and the
            // related shoot — i.e. it goes through the existing messaging
            // infrastructure (Req 12.1) rather than a side channel.
            $this->assertSame(
                'MANUAL',
                $capture['payload']['send_source'] ?? null,
                "[B] payload.send_source must be MANUAL for {$context}"
            );
            $this->assertSame(
                $shoot->id,
                $capture['payload']['related_shoot_id'] ?? null,
                "[B] payload.related_shoot_id must equal the shoot id for {$context}"
            );

            // ----------------------------------------------------------------
            // (A) The captured `template_id` references a MessageTemplate
            //     whose slug equals the mapped slug for the type (Req 12.2).
            //     Re-resolve via the DB so the assertion is grounded in the
            //     persisted template, not in test-local bookkeeping.
            // ----------------------------------------------------------------
            $expectedSlug = ManualNotificationService::TYPES[$type] ?? null;
            $this->assertNotNull(
                $expectedSlug,
                "[A] type {$type} must be a defined manual notification type for {$context}"
            );

            $capturedTemplateId = $capture['payload']['template_id'] ?? null;
            $this->assertNotNull(
                $capturedTemplateId,
                "[A] payload.template_id must be set for {$context}"
            );

            $resolved = MessageTemplate::find($capturedTemplateId);
            $this->assertNotNull(
                $resolved,
                "[A] payload.template_id must reference a persisted MessageTemplate for {$context}"
            );
            $this->assertSame(
                $expectedSlug,
                $resolved->slug,
                "[A] dispatched template's slug must equal ManualNotificationService::TYPES[{$type}] for {$context}"
            );
            $this->assertSame(
                $this->idBySlug[$expectedSlug],
                $capturedTemplateId,
                "[A] payload.template_id must equal the seeded template id for slug {$expectedSlug} for {$context}"
            );
        }
    }

    /**
     * The property: any type string outside ManualNotificationService::TYPES
     * raises InvalidArgumentException, and the dispatch path is never reached.
     *
     * Validates: Requirements 12.2
     */
    public function test_unknown_types_throw_invalid_argument_and_do_not_dispatch(): void
    {
        // Strict mock: any send call would surface as an unexpected-method
        // failure on the underlying MockInterface, failing the test.
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendEmail');
            $mock->shouldNotReceive('sendSms');
        });

        $service = app(ManualNotificationService::class);

        foreach ($this->unknownTypesGenerator() as $i => $unknownType) {
            $context = sprintf(
                'iteration %d (unknownType=%s)',
                $i,
                var_export($unknownType, true)
            );

            $shoot = $this->shootForCase();
            $threw = false;

            try {
                // Recipient + channel are valid; the only invalid axis is $type.
                // The recipient/channel pick is randomized so the throw cannot
                // be incidentally caused by a specific recipient/channel value.
                $service->send(
                    $shoot,
                    $unknownType,
                    self::RECIPIENT_TYPES[mt_rand(0, count(self::RECIPIENT_TYPES) - 1)],
                    self::CHANNELS[mt_rand(0, count(self::CHANNELS) - 1)],
                    $this->sender
                );
            } catch (InvalidArgumentException $e) {
                $threw = true;
                // The exception message references the rejected type, which
                // helps operators diagnose miswired callers.
                $this->assertStringContainsString(
                    'Unknown notification type',
                    $e->getMessage(),
                    "[C] InvalidArgumentException message must identify an unknown notification type for {$context}"
                );
            }

            $this->assertTrue(
                $threw,
                "[C] send() must throw InvalidArgumentException for unknown type for {$context}"
            );
        }

        // No Message rows persisted as a side effect of the unknown-type loop.
        $this->assertSame(
            0,
            Message::count(),
            '[C] no Message rows may be persisted while exercising unknown-type inputs'
        );
    }
}
