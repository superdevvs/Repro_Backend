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
 * Feature: production-qa-fixes-2, Property 23: Shoot ready notification records its timestamp
 *
 * Validates: Requirements 12.10
 *
 * For any send of a manual notification, the shoot's `shoot_ready_notified_at` is set to the
 * send time (non-null, ~now) IF AND ONLY IF the notification type is `shoot_ready`; for every
 * other type the field remains null/unchanged. The recorded timestamp is its own field,
 * distinct from the shoot date (`scheduled_date`) and the invoice date (the `invoices`
 * relation), so stamping it never coincides with, or is derived from, those dates.
 *
 * No property-based testing library is configured for the backend, so this test follows the
 * same deterministic-generator + seeded-randomization approach used by
 * {@see \Tests\Unit\Shoots\ShootEditingPayloadFilteringPropertyTest},
 * {@see \Tests\Unit\Shoots\ShootDatePreservationPropertyTest}, and
 * {@see \Tests\Unit\Scanning\Properties\WithholdingPropertyTest}: a fixed table of
 * deterministic edge cases (one per notification type × channel corner) plus a seeded PRNG
 * that produces 30 randomized {type, recipient, channel} cases. MessagingService is mocked so
 * no real email/SMS is dispatched; the property under test is purely the timestamp side effect.
 */
class ManualNotificationShootReadyTimestampPropertyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The shoot date used for every generated shoot. Fixed far from "now" so that the
     * stamped `shoot_ready_notified_at` (~now) is provably distinct from the shoot date.
     */
    private const FIXED_SHOOT_DATE = '2020-01-15';

    /**
     * Seed one active EMAIL template per manual notification type. The dispatch channel is
     * chosen independently of the template channel (the service routes on the channel arg),
     * so an EMAIL template with both html + text bodies serves every channel.
     */
    private function seedTemplates(): void
    {
        foreach (ManualNotificationService::TYPES as $type => $slug) {
            MessageTemplate::updateOrCreate(
                ['slug' => $slug],
                [
                    'channel'   => 'EMAIL',
                    'name'      => ucfirst(str_replace('-', ' ', $slug)),
                    'category'  => 'GENERAL',
                    'subject'   => 'Subject for ' . $slug,
                    'body_html' => '<p>Hello {{recipient_first_name}}</p>',
                    'body_text' => 'Hello {{recipient_first_name}}',
                    'scope'     => 'SYSTEM',
                    'is_system' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * A loose MessagingService mock that accepts any number of email/SMS sends and returns a
     * sent Message. The property is about the timestamp side effect, not the dispatch payload,
     * so we do not assert on call arguments here.
     */
    private function mockMessaging(): void
    {
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $message = Message::make([
                'channel'    => 'EMAIL',
                'to_address' => 'recipient@example.com',
                'status'     => 'SENT',
            ]);

            $mock->shouldReceive('sendEmail')->zeroOrMoreTimes()->andReturn($message);
            $mock->shouldReceive('sendSms')->zeroOrMoreTimes()->andReturn($message);
        });
    }

    /**
     * Build a fresh shoot with a client + photographer that both have an email and a phone, so
     * any (recipient, channel) combination is deliverable. `scheduled_date` is fixed and
     * `shoot_ready_notified_at` starts null. The `$n` suffix keeps emails/phones unique across
     * the many shoots created within a single test run.
     */
    private function makeShoot(int $n): Shoot
    {
        $client = User::factory()->create([
            'email'       => "client{$n}@example.com",
            'name'        => 'Casey Client',
            'phonenumber' => '+1555100' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        ]);
        $photographer = User::factory()->create([
            'email'       => "pro{$n}@example.com",
            'name'        => 'Pat Photographer',
            'phonenumber' => '+1555200' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        ]);

        return Shoot::factory()->create([
            'client_id'               => $client->id,
            'photographer_id'         => $photographer->id,
            'scheduled_date'          => self::FIXED_SHOOT_DATE,
            'shoot_ready_notified_at' => null,
        ]);
    }

    /**
     * Deterministic edge cases: every notification type at both channel corners, against both
     * recipient kinds. Guarantees the IFF is exercised in both directions (the single
     * `shoot_ready` type that MUST stamp, and all five types that MUST NOT).
     *
     * @return list<array{type: string, recipient: string, channel: string}>
     */
    private function edgeCases(): array
    {
        $cases = [];
        foreach (array_keys(ManualNotificationService::TYPES) as $type) {
            $cases[] = ['type' => $type, 'recipient' => 'client', 'channel' => 'email'];
            $cases[] = ['type' => $type, 'recipient' => 'photographer', 'channel' => 'sms'];
        }

        return $cases;
    }

    /**
     * Seeded random cases: reproducible {type, recipient, channel} triples drawn uniformly
     * across the full input space.
     *
     * @return list<array{type: string, recipient: string, channel: string}>
     */
    private function randomCases(int $count): array
    {
        // Seeded PRNG so the generator is reproducible across runs (same approach as the
        // other backend property tests).
        mt_srand(20260616);

        $types = array_keys(ManualNotificationService::TYPES);
        $recipients = ['client', 'photographer'];
        $channels = ['email', 'sms'];

        $cases = [];
        for ($i = 0; $i < $count; $i++) {
            $cases[] = [
                'type'      => $types[mt_rand(0, count($types) - 1)],
                'recipient' => $recipients[mt_rand(0, count($recipients) - 1)],
                'channel'   => $channels[mt_rand(0, count($channels) - 1)],
            ];
        }

        return $cases;
    }

    /**
     * Property 23: across randomized notification types, recipients, and channels,
     * `shoot_ready_notified_at` is stamped (non-null, ~now, distinct from the shoot date)
     * IF AND ONLY IF the type is `shoot_ready`, and is left null for every other type.
     */
    #[Test]
    public function shoot_ready_notified_at_is_stamped_iff_type_is_shoot_ready(): void
    {
        $this->seedTemplates();
        $this->mockMessaging();

        $service = app(ManualNotificationService::class);
        $sender = User::factory()->create(['role' => 'admin']);

        $cases = array_merge($this->edgeCases(), $this->randomCases(30));

        // Track that both branches of the IFF actually occur, so a generator that
        // accidentally produced only one type could never make this test vacuous.
        $sawReady = false;
        $sawOther = false;

        foreach ($cases as $index => $case) {
            $shoot = $this->makeShoot($index);

            $before = now();
            $message = $service->send(
                $shoot,
                $case['type'],
                $case['recipient'],
                $case['channel'],
                $sender,
            );
            $after = now();

            $this->assertInstanceOf(Message::class, $message, "case {$index}: send returned a Message");

            $stamped = $shoot->fresh()->shoot_ready_notified_at;
            $label = "case {$index} (type={$case['type']}, recipient={$case['recipient']}, channel={$case['channel']})";

            if ($case['type'] === 'shoot_ready') {
                $sawReady = true;

                // Non-null and stamped at the send time (within the call window).
                $this->assertNotNull($stamped, "{$label}: shoot_ready MUST stamp shoot_ready_notified_at");
                $this->assertTrue(
                    $stamped->betweenIncluded($before->copy()->subSecond(), $after->copy()->addSecond()),
                    "{$label}: shoot_ready_notified_at must equal the send time (~now)"
                );

                // Distinct from the shoot date — it is its own field, not derived from
                // scheduled_date (nor from the invoice date, which lives on the invoices
                // relation entirely separate from this column).
                $this->assertNotSame(
                    self::FIXED_SHOOT_DATE,
                    $stamped->format('Y-m-d'),
                    "{$label}: shoot_ready_notified_at must be distinct from the shoot date"
                );
            } else {
                $sawOther = true;

                // Only shoot_ready stamps — every other type leaves the field null.
                $this->assertNull(
                    $stamped,
                    "{$label}: non-ready notification MUST NOT set shoot_ready_notified_at"
                );
            }
        }

        $this->assertTrue($sawReady, 'generator must exercise the shoot_ready (stamping) branch');
        $this->assertTrue($sawOther, 'generator must exercise the non-ready (no-stamp) branch');
    }
}
