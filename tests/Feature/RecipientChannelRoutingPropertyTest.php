<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 21: Manual notification routes to the
 * selected recipient and channel.
 *
 * Validates: Requirements 12.6, 12.7
 *
 * Universal invariant under test, for any defined notification type and any
 * (recipient ∈ {client, photographer}, channel ∈ {email, sms}) selection:
 *
 *   (12.6) The notification is delivered to the SELECTED recipient. When
 *          recipientType = client the dispatch targets the shoot's client;
 *          when recipientType = photographer it targets the shoot's
 *          photographer. The captured payload's `to`, `contact_type`, and
 *          `contact_user_id` must identify that recipient — and, because the
 *          two recipients are seeded with DISTINCT contact details, the
 *          dispatch must NOT carry the other party's address.
 *
 *   (12.7) The notification is sent over the SELECTED channel. channel = email
 *          routes through MessagingService::sendEmail and the `to` address is
 *          the recipient's email; channel = sms routes through
 *          MessagingService::sendSms and the `to` address is the recipient's
 *          phone number (phonenumber, falling back to phone).
 *
 * {@see ManualNotificationService::send()} is the unit under test.
 * {@see MessagingService} is the seam: it is mocked to capture which method
 * (sendEmail / sendSms) was invoked and the payload passed to it, so no real
 * dispatch occurs.
 *
 * Approach: no PHP property-based-testing library is configured for the backend,
 * so this test follows the same "seeded strong randomization plus deterministic
 * edge cases" strategy used elsewhere in this suite (see
 * ManualDispatchMappedTemplatePropertyTest, PaymentNotificationContentPropertyTest).
 * The valid selection space — recipient × channel — is small and finite (4
 * combinations), so all four are covered deterministically; 30 randomized cases
 * additionally vary the notification type so routing is exercised across every
 * mapped template. Every generated input must satisfy the routing invariant.
 */
class RecipientChannelRoutingPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases. */
    private const RANDOM_ITERATIONS = 30;

    /** Fixed seed so any counterexample reproduces; bump if a case is fixed. */
    private const SEED = 12_06_12_07;

    private const RECIPIENT_TYPES = ['client', 'photographer'];

    private const CHANNELS = ['email', 'sms'];

    private User $sender;

    /**
     * The method/payload MessagingService would have dispatched. Reset before
     * every send via {@see mockMessagingForOneCall()}.
     *
     * @var array{method:?string,payload:?array<string,mixed>}
     */
    private array $capture = ['method' => null, 'payload' => null];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = User::factory()->create(['role' => 'admin']);

        // Seed exactly one active template per defined type → slug so
        // ManualNotificationService::resolveTemplate() resolves unambiguously.
        // The template channel is irrelevant to routing (routing follows the
        // $channel argument, not the template's own channel), so EMAIL bodies
        // are fine for every case.
        MessageTemplate::query()
            ->whereIn('slug', array_values(ManualNotificationService::TYPES))
            ->delete();

        foreach (ManualNotificationService::TYPES as $slug) {
            MessageTemplate::create([
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
        }
    }

    /**
     * Generator: 4 deterministic (recipient × channel) + 30 randomized cases.
     *
     * The deterministic block is the full Cartesian product of the two
     * recipient types and two channels, pinning every routing combination on a
     * single (shoot_scheduled) type. The randomized block re-rolls the
     * (type, recipient, channel) tuple so routing is verified across every
     * mapped notification type and surfaces any state coupling between
     * iterations.
     *
     * @return array<string, array{type:string,recipient:string,channel:string,label:string}>
     */
    private function casesGenerator(): array
    {
        mt_srand(self::SEED);

        $cases = [];

        foreach (self::RECIPIENT_TYPES as $recipient) {
            foreach (self::CHANNELS as $channel) {
                $label = "shoot_scheduled / {$recipient} / {$channel}";
                $cases['edge_' . $recipient . '_' . $channel] = [
                    'type'      => 'shoot_scheduled',
                    'recipient' => $recipient,
                    'channel'   => $channel,
                    'label'     => $label,
                ];
            }
        }

        $types = array_keys(ManualNotificationService::TYPES);
        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $type = $types[mt_rand(0, count($types) - 1)];
            $recipient = self::RECIPIENT_TYPES[mt_rand(0, count(self::RECIPIENT_TYPES) - 1)];
            $channel = self::CHANNELS[mt_rand(0, count(self::CHANNELS) - 1)];

            $cases["random_{$i}_{$type}_{$recipient}_{$channel}"] = [
                'type'      => $type,
                'recipient' => $recipient,
                'channel'   => $channel,
                'label'     => "random iter {$i}",
            ];
        }

        return $cases;
    }

    /**
     * Build a shoot whose client and photographer have DISTINCT email and phone
     * details, so the test can prove the dispatch targets the *selected*
     * recipient and not the other party.
     *
     * @return array{shoot:Shoot,client:User,photographer:User}
     */
    private function shootWithDistinctRecipients(): array
    {
        $client = User::factory()->create([
            'email'       => 'client+' . uniqid('', true) . '@example.com',
            'phonenumber' => '+1555' . str_pad((string) mt_rand(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT),
            'name'        => 'Casey Client',
        ]);
        $photographer = User::factory()->create([
            'email'       => 'photog+' . uniqid('', true) . '@example.com',
            'phonenumber' => '+1444' . str_pad((string) mt_rand(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT),
            'name'        => 'Pat Photographer',
            'role'        => 'photographer',
        ]);

        $shoot = Shoot::factory()->create([
            'client_id'       => $client->id,
            'photographer_id' => $photographer->id,
        ]);

        return ['shoot' => $shoot, 'client' => $client, 'photographer' => $photographer];
    }

    /**
     * Re-mock MessagingService for a single iteration, capturing which send
     * method was called and the payload it received.
     */
    private function mockMessagingForOneCall(): void
    {
        $this->capture = ['method' => null, 'payload' => null];

        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendEmail')
                ->withArgs(function (array $payload): bool {
                    $this->capture = ['method' => 'sendEmail', 'payload' => $payload];
                    return true;
                })
                ->andReturnUsing(fn (array $payload): Message => Message::make([
                    'channel'    => 'EMAIL',
                    'to_address' => $payload['to'] ?? '',
                    'status'     => 'SENT',
                ]));

            $mock->shouldReceive('sendSms')
                ->withArgs(function (array $payload): bool {
                    $this->capture = ['method' => 'sendSms', 'payload' => $payload];
                    return true;
                })
                ->andReturnUsing(fn (array $payload): Message => Message::make([
                    'channel'    => 'SMS',
                    'to_address' => $payload['to'] ?? '',
                    'status'     => 'SENT',
                ]));
        });
    }

    /**
     * Property 21 — manual notifications route to the selected recipient and channel.
     *
     * Validates: Requirements 12.6, 12.7
     */
    #[Test]
    public function send_routes_to_selected_recipient_and_channel_for_all_inputs(): void
    {
        foreach ($this->casesGenerator() as $key => $case) {
            ['shoot' => $shoot, 'client' => $client, 'photographer' => $photographer]
                = $this->shootWithDistinctRecipients();

            $selected = $case['recipient'] === 'photographer' ? $photographer : $client;
            $other = $case['recipient'] === 'photographer' ? $client : $photographer;

            $expectedAddress = $case['channel'] === 'sms'
                ? $selected->phonenumber
                : $selected->email;
            $otherAddress = $case['channel'] === 'sms'
                ? $other->phonenumber
                : $other->email;

            $this->mockMessagingForOneCall();

            // Resolve fresh AFTER mocking so the service uses the bound mock.
            app(ManualNotificationService::class)->send(
                $shoot->fresh(),
                $case['type'],
                $case['recipient'],
                $case['channel'],
                $this->sender,
            );

            $context = sprintf(
                'case %s (type=%s, recipient=%s, channel=%s, label=%s)',
                $key,
                $case['type'],
                $case['recipient'],
                $case['channel'],
                $case['label'],
            );

            $payload = $this->capture['payload'];
            $this->assertNotNull($payload, "MessagingService was never invoked for {$context}");

            // ----------------------------------------------------------------
            // (12.7) Channel routing — the SELECTED channel's send path.
            // ----------------------------------------------------------------
            $expectedMethod = $case['channel'] === 'sms' ? 'sendSms' : 'sendEmail';
            $this->assertSame(
                $expectedMethod,
                $this->capture['method'],
                "[12.7] channel={$case['channel']} must route through MessagingService::{$expectedMethod} for {$context}"
            );

            // ----------------------------------------------------------------
            // (12.6 + 12.7) The 'to' address is the SELECTED recipient's
            // address for the SELECTED channel — and not the other party's.
            // ----------------------------------------------------------------
            $this->assertSame(
                $expectedAddress,
                $payload['to'] ?? null,
                "[12.6/12.7] dispatch 'to' must be the selected recipient's "
                . "{$case['channel']} address for {$context}"
            );
            $this->assertNotSame(
                $otherAddress,
                $payload['to'] ?? null,
                "[12.6] dispatch must NOT target the other party's address for {$context}"
            );

            // ----------------------------------------------------------------
            // (12.6) The payload identifies the SELECTED recipient.
            // ----------------------------------------------------------------
            $this->assertSame(
                $case['recipient'],
                $payload['contact_type'] ?? null,
                "[12.6] payload.contact_type must equal the selected recipient type for {$context}"
            );
            $this->assertSame(
                $selected->id,
                $payload['contact_user_id'] ?? null,
                "[12.6] payload.contact_user_id must equal the selected recipient id for {$context}"
            );

            // The channel-specific contact field carries the selected address too.
            if ($case['channel'] === 'sms') {
                $this->assertSame(
                    $expectedAddress,
                    $payload['contact_phone'] ?? null,
                    "[12.7] payload.contact_phone must be the selected recipient's phone for {$context}"
                );
            } else {
                $this->assertSame(
                    $expectedAddress,
                    $payload['contact_email'] ?? null,
                    "[12.7] payload.contact_email must be the selected recipient's email for {$context}"
                );
            }
        }
    }
}
