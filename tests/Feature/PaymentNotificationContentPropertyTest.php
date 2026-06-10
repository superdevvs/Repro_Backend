<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\ManualNotificationService;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature: production-qa-fixes-2, Property 8: Payment notifications carry their
 * required content.
 *
 * Validates: Requirements 12.3, 12.4
 *
 * Universal invariant under test, for any shoot / recipient / channel / payment
 * combination:
 *
 *   (12.3) WHEN a Payment due notification is dispatched, THE rendered message
 *          content delivered to MessagingService SHALL contain a payment link.
 *          The link is produced by PublicPaymentAccessTokenService::buildPublicUrl
 *          (`{frontend}/payment/{token}`), so the rendered output must contain the
 *          `/payment/` access path injected via the {{payment_link}} variable.
 *
 *   (12.4) WHEN a Payment receipt notification is dispatched, THE rendered message
 *          content SHALL contain payment confirmation details — at minimum the
 *          amount paid (derived from the shoot's completed Payment records) and the
 *          remaining-balance summary injected via the {{payment_details}} variable.
 *
 * MessagingService is the seam: it is mocked so the rendered payload that would be
 * sent is captured without performing any real dispatch. ManualNotificationService::send
 * is the unit under test — it injects `payment_link` for `payment_due` and
 * `payment_details` (built from the shoot's Payment records) for `payment_receipt`.
 *
 * Approach: no PHP property-based-testing library is configured for the backend, so
 * the test follows the same "seeded strong randomization plus deterministic edge
 * cases" strategy used elsewhere in this suite (see
 * PaymentReminderCadencePropertyTest, CubiCasaPerShootIdempotencyPropertyTest):
 * 30 randomized cases (random notification type, recipient, channel, and — for
 * receipts — a random completed-payment amount) plus deterministic edge cases that
 * pin both channels, both recipients, and boundary payment amounts ($0.01 and a
 * whole-dollar $1000.00). Every generated input must satisfy the content invariant
 * for its notification type.
 */
class PaymentNotificationContentPropertyTest extends TestCase
{
    use RefreshDatabase;

    /** Spec mandates >= 25 randomized cases. */
    private const RANDOM_ITERATIONS = 30;

    /** Fixed seed so any counterexample reproduces; bump if a case is fixed. */
    private const SEED = 12_03_12_04;

    private const TYPE_DUE = 'payment_due';
    private const TYPE_RECEIPT = 'payment_receipt';

    /**
     * The latest payload MessagingService would have dispatched. Updated on every
     * sendEmail/sendSms call by the mock installed in {@see fakeMessaging()}.
     *
     * @var array<string, mixed>|null
     */
    private ?array $captured = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic frontend URL so the payment link is predictable.
        config()->set('app.frontend_url', 'https://dashboard.test');

        $this->seedPaymentTemplates();
        $this->fakeMessaging();
    }

    /**
     * Create the payment_due / payment_receipt templates exactly once. Each body
     * references the variable the service injects for that type so the rendered
     * output can be asserted against the required content.
     */
    private function seedPaymentTemplates(): void
    {
        // Drop any seeded fixtures that would shadow our deterministic templates.
        MessageTemplate::whereIn('slug', ['payment-due', 'payment-receipt'])->delete();

        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Payment Due',
            'slug' => 'payment-due',
            'description' => null,
            'category' => 'GENERAL',
            'subject' => 'Payment due for your shoot',
            'body_html' => '<p>Please pay your invoice here: {{payment_link}}</p>',
            'body_text' => 'Please pay your invoice here: {{payment_link}}',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);

        MessageTemplate::create([
            'channel' => 'EMAIL',
            'name' => 'Payment Receipt',
            'slug' => 'payment-receipt',
            'description' => null,
            'category' => 'GENERAL',
            'subject' => 'Your payment receipt',
            'body_html' => '<p>Payment received. {{payment_details}}</p>',
            'body_text' => 'Payment received. {{payment_details}}',
            'scope' => 'SYSTEM',
            'is_system' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Mock MessagingService so both send paths capture the rendered payload
     * instead of dispatching. A single mock updates {@see $this->captured} on
     * every call, so the test can read the latest payload after each send.
     */
    private function fakeMessaging(): void
    {
        $this->mock(MessagingService::class, function (MockInterface $mock): void {
            $capture = function (array $payload): Message {
                $this->captured = $payload;

                return Message::make([
                    'channel' => $payload['contact_phone'] ?? null ? 'SMS' : 'EMAIL',
                    'to_address' => $payload['to'] ?? 'recipient@example.com',
                    'status' => 'SENT',
                ]);
            };

            $mock->shouldReceive('sendEmail')->andReturnUsing($capture);
            $mock->shouldReceive('sendSms')->andReturnUsing($capture);
        });
    }

    /**
     * Generator: 30 randomized + deterministic edge cases.
     *
     * Each entry is ['type' => ..., 'recipient' => ..., 'channel' => ..., 'amount' => float|null, 'label' => ...].
     * `amount` is the completed-payment amount used for receipt cases (null for due cases).
     *
     * @return array<string, array<string, mixed>>
     */
    private function casesGenerator(): array
    {
        mt_srand(self::SEED);

        $types = [self::TYPE_DUE, self::TYPE_RECEIPT];
        $recipients = ['client', 'photographer'];
        $channels = ['email', 'sms'];

        $cases = [];

        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            $type = $types[mt_rand(0, 1)];
            $recipient = $recipients[mt_rand(0, 1)];
            $channel = $channels[mt_rand(0, 1)];

            // Random amount with cents for receipts: $0.50 .. $9999.99.
            $amount = $type === self::TYPE_RECEIPT
                ? round(mt_rand(50, 999_999) / 100, 2)
                : null;

            $cases["random_{$i}_{$type}_{$recipient}_{$channel}"] = [
                'type' => $type,
                'recipient' => $recipient,
                'channel' => $channel,
                'amount' => $amount,
                'label' => "random iter {$i}",
            ];
        }

        // Deterministic edge cases pinning channels, recipients, and boundary amounts.
        $edges = [
            ['type' => self::TYPE_DUE, 'recipient' => 'client', 'channel' => 'email', 'amount' => null, 'label' => 'due / client / email'],
            ['type' => self::TYPE_DUE, 'recipient' => 'photographer', 'channel' => 'sms', 'amount' => null, 'label' => 'due / photographer / sms'],
            ['type' => self::TYPE_RECEIPT, 'recipient' => 'client', 'channel' => 'email', 'amount' => 0.01, 'label' => 'receipt / client / email / $0.01'],
            ['type' => self::TYPE_RECEIPT, 'recipient' => 'client', 'channel' => 'sms', 'amount' => 1000.00, 'label' => 'receipt / client / sms / $1000.00'],
            ['type' => self::TYPE_RECEIPT, 'recipient' => 'photographer', 'channel' => 'email', 'amount' => 250.50, 'label' => 'receipt / photographer / email / $250.50'],
        ];

        foreach ($edges as $j => $edge) {
            $cases["edge_{$j}_" . str_replace([' ', '/', '$', '.'], '_', $edge['label'])] = $edge;
        }

        return $cases;
    }

    /**
     * Property 8 — every payment notification carries its required content.
     *
     * Validates: Requirements 12.3, 12.4
     */
    #[Test]
    public function payment_notifications_carry_required_content_for_all_inputs(): void
    {
        $sender = User::factory()->create(['role' => 'admin']);

        foreach ($this->casesGenerator() as $key => $case) {
            $this->captured = null;

            $client = User::factory()->create();
            $photographer = User::factory()->photographer()->create();
            $shoot = Shoot::factory()->create([
                'client_id' => $client->id,
                'photographer_id' => $photographer->id,
            ]);

            $expectedAmount = null;
            if ($case['type'] === self::TYPE_RECEIPT) {
                Payment::factory()->create([
                    'shoot_id' => $shoot->id,
                    'amount' => $case['amount'],
                    'status' => Payment::STATUS_COMPLETED,
                ]);
                $expectedAmount = (float) $case['amount'];
            }

            app(ManualNotificationService::class)->send(
                $shoot->fresh(),
                $case['type'],
                $case['recipient'],
                $case['channel'],
                $sender,
            );

            $context = sprintf(
                'case %s (type=%s, recipient=%s, channel=%s, amount=%s, label=%s)',
                $key,
                $case['type'],
                $case['recipient'],
                $case['channel'],
                $case['amount'] === null ? 'n/a' : number_format($expectedAmount ?? 0, 2),
                $case['label'],
            );

            $this->assertNotNull($this->captured, "MessagingService was never invoked for {$context}");

            // Rendered content for whichever channel was selected: SMS carries body_text
            // only, email carries both. Inspect both fields so the invariant holds
            // regardless of channel.
            $content = (string) ($this->captured['body_text'] ?? '')
                . "\n" . (string) ($this->captured['body_html'] ?? '');

            $this->assertNotSame('', trim($content), "rendered content was empty for {$context}");

            if ($case['type'] === self::TYPE_DUE) {
                // AC 12.3 — the dispatched content must include the payment link.
                $this->assertStringContainsString(
                    '/payment/',
                    $content,
                    "payment_due content is missing the payment link for {$context}\n  content: {$content}"
                );
            } else {
                // AC 12.4 — the dispatched content must include payment confirmation details:
                // the amount paid (formatted from the completed Payment record) and the
                // remaining-balance summary.
                $formattedAmount = 'Amount paid: $' . number_format($expectedAmount, 2);
                $this->assertStringContainsString(
                    $formattedAmount,
                    $content,
                    "payment_receipt content is missing the confirmation amount for {$context}\n  content: {$content}"
                );
                $this->assertStringContainsString(
                    'Remaining balance:',
                    $content,
                    "payment_receipt content is missing the remaining-balance detail for {$context}\n  content: {$content}"
                );
            }
        }
    }
}
