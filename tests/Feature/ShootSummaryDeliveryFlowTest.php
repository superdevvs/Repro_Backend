<?php

namespace Tests\Feature;

use App\Jobs\MaybeSendShootSummary;
use App\Jobs\SendShootReadyEmailJob;
use App\Models\AutomationRule;
use App\Models\Message;
use App\Models\MessageChannel;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Models\Shoot;
use App\Models\User;
use App\Services\MailService;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\OutboundDeliveryGuard;
use App\Services\Messaging\ShootSummaryNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShootSummaryDeliveryFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        OutboundDeliveryGuard::allowFakeProviderPipelineForTesting();
        MessageChannel::create([
            'type' => 'EMAIL',
            'provider' => 'LOCAL_SMTP',
            'display_name' => 'Test delivery',
            'from_email' => 'delivery@example.test',
            'is_default' => true,
            'owner_scope' => 'GLOBAL',
        ]);

        // A saved email action must not send a second delivery email after the
        // protected, payment-aware email is accepted.
        AutomationRule::where('trigger_type', 'SHOOT_COMPLETED')->firstOrFail()->update([
            'is_active' => true,
            'workflow_definition_json' => [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger.event', 'config' => ['triggerType' => 'SHOOT_COMPLETED']],
                    ['id' => 'send', 'type' => 'action.email', 'config' => [
                        'subject' => 'Old generic delivery',
                        'bodyText' => 'Old generic delivery body',
                        'recipientMode' => 'roles',
                        'recipientRoles' => ['client'],
                    ]],
                    ['id' => 'end', 'type' => 'end', 'config' => []],
                ],
                'edges' => [
                    ['id' => 'one', 'source' => 'trigger', 'target' => 'send'],
                    ['id' => 'two', 'source' => 'send', 'target' => 'end'],
                ],
            ],
        ]);
    }

    public function test_paid_before_delivery_gets_one_summary_and_no_generic_delivery_or_reminders(): void
    {
        $shoot = $this->deliveredShoot([
            'status' => Shoot::WORKFLOW_COMPLETED,
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'delivery_status' => 'not_started',
        ]);
        $this->payment($shoot, 500);
        $this->assertSame('paid', $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('card')['payment_status']);
        $shoot->update([
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'delivery_status' => 'delivered',
            'tour_links' => [
                'mls' => 'https://example.test/tour/mls',
                'branded' => 'https://example.test/tour/branded',
                'video_mls' => 'https://example.test/video/property.mp4',
                'zillow_3d' => 'https://example.test/zillow/3d',
            ],
        ]);

        $this->deliver($shoot);
        $this->deliver($shoot); // A worker retry must reuse the accepted dispatch.

        $this->assertSame(1, $this->messages($shoot, 'SHOOT_SUMMARY')->count());
        $this->assertSame(0, $this->messages($shoot, 'SHOOT_DELIVERED')->count());
        $this->assertSame(0, $this->messages($shoot, 'AUTOMATION')->count());
        $this->assertSame(0, PaymentReminder::where('shoot_id', $shoot->id)->count());
        $this->assertStringContainsString('Shoot Summary', (string) $this->messages($shoot, 'SHOOT_SUMMARY')->sole()->subject);
        $html = (string) $this->messages($shoot, 'SHOOT_SUMMARY')->sole()->body_html;
        foreach (['https://example.test/tour/mls', 'https://example.test/tour/branded',
            'https://example.test/video/property.mp4', 'https://example.test/zillow/3d'] as $url) {
            $this->assertStringContainsString($url, $html);
        }
        $this->assertStringContainsString('Open Shoot in Dashboard', $html);
        $this->assertStringNotContainsString('Pay Now', $html);
    }

    public function test_unpaid_delivery_then_partial_then_final_payment_sends_summary_once_and_stops_reminders(): void
    {
        Queue::fake([MaybeSendShootSummary::class]);
        $shoot = $this->deliveredShoot();

        $this->deliver($shoot);
        $this->assertSame(1, $this->messages($shoot, 'SHOOT_DELIVERED')->count());
        $this->assertSame(0, $this->messages($shoot, 'SHOOT_SUMMARY')->count());
        $this->assertSame(0, $this->messages($shoot, 'AUTOMATION')->count());
        $this->assertGreaterThan(0, PaymentReminder::where('shoot_id', $shoot->id)->where('status', PaymentReminder::STATUS_PENDING)->count());

        $this->payment($shoot, 125);
        $partial = $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('card');
        $this->assertSame('partial', $partial['payment_status']);
        $this->assertSame(375.0, $partial['remaining_balance']);
        Queue::assertNotPushed(MaybeSendShootSummary::class);
        $this->assertGreaterThan(0, PaymentReminder::where('shoot_id', $shoot->id)->where('status', PaymentReminder::STATUS_PENDING)->count());

        $this->payment($shoot, 375);
        $paid = $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('card');
        $this->assertSame('paid', $paid['payment_status']);
        Queue::assertPushed(MaybeSendShootSummary::class, 1);
        $this->assertSame(0, PaymentReminder::where('shoot_id', $shoot->id)->where('status', PaymentReminder::STATUS_PENDING)->count());

        (new MaybeSendShootSummary($shoot->id))->handle(app(ShootSummaryNotificationService::class));
        (new MaybeSendShootSummary($shoot->id))->handle(app(ShootSummaryNotificationService::class));
        $this->deliver($shoot);

        $this->assertSame(1, $this->messages($shoot, 'SHOOT_SUMMARY')->count());
        $this->assertSame(1, $this->messages($shoot, 'SHOOT_DELIVERED')->count());
        $this->assertSame(0, $this->messages($shoot, 'AUTOMATION')->count());
    }

    public function test_no_charge_delivery_gets_summary_without_payment(): void
    {
        $shoot = $this->deliveredShoot(['total_quote' => 0, 'base_quote' => 0, 'payment_status' => Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED]);

        $this->deliver($shoot);

        $this->assertSame(1, $this->messages($shoot, 'SHOOT_SUMMARY')->count());
        $this->assertSame(0, $this->messages($shoot, 'SHOOT_DELIVERED')->count());
        $this->assertSame(0, PaymentReminder::where('shoot_id', $shoot->id)->count());
    }

    public function test_paywall_bypass_alone_does_not_send_the_paid_summary(): void
    {
        $shoot = $this->deliveredShoot(['bypass_paywall' => true]);

        $this->deliver($shoot);

        $this->assertSame(1, $this->messages($shoot, 'SHOOT_DELIVERED')->count());
        $this->assertSame(0, $this->messages($shoot, 'SHOOT_SUMMARY')->count());
        $this->assertSame(0, PaymentReminder::where('shoot_id', $shoot->id)->where('status', PaymentReminder::STATUS_PENDING)->count());
    }

    public function test_disabled_delivery_rule_suppresses_both_delivery_and_later_paid_summary(): void
    {
        AutomationRule::where('trigger_type', 'SHOOT_COMPLETED')->firstOrFail()->update(['is_active' => false]);
        $shoot = $this->deliveredShoot();

        $this->deliver($shoot);
        $this->assertSame(0, Message::where('related_shoot_id', $shoot->id)->count());
        $this->assertSame(0, PaymentReminder::where('shoot_id', $shoot->id)->count());

        $this->payment($shoot, 500);
        $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('card');
        (new MaybeSendShootSummary($shoot->id))->handle(app(ShootSummaryNotificationService::class));

        $this->assertSame(0, Message::where('related_shoot_id', $shoot->id)->count());
    }

    public function test_reconciling_old_paid_records_does_not_email_historical_shoots(): void
    {
        Queue::fake([MaybeSendShootSummary::class]);
        $shoot = $this->deliveredShoot();
        $payment = $this->payment($shoot, 500);
        $payment->forceFill(['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)])->save();

        $this->assertSame('paid', $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('card')['payment_status']);
        Queue::assertNotPushed(MaybeSendShootSummary::class);
    }

    public function test_confirming_an_older_pending_payment_sends_the_delivered_shoot_summary(): void
    {
        Queue::fake([MaybeSendShootSummary::class]);
        $shoot = $this->deliveredShoot();
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'invoice_id' => null,
            'amount' => 500,
            'status' => Payment::STATUS_PENDING,
        ]);
        $payment->forceFill(['created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4)])->save();
        $payment->status = Payment::STATUS_COMPLETED;
        $payment->save();

        $this->assertSame('paid', $shoot->fresh(['payments'])->syncPaymentStatusFromRecords('offline')['payment_status']);
        Queue::assertPushed(MaybeSendShootSummary::class, 1);
    }

    private function deliveredShoot(array $attributes = []): Shoot
    {
        $client = User::factory()->create([
            'role' => 'client',
            'name' => 'Casey Client',
            'email' => 'casey@example.test',
            'phonenumber' => null,
        ]);

        return Shoot::factory()->create(array_merge([
            'client_id' => $client->id,
            'address' => '1036 Scenic Drive',
            'city' => 'Jefferson',
            'state' => 'NJ',
            'zip' => '07438',
            'total_quote' => 500,
            'base_quote' => 500,
            'payment_status' => 'unpaid',
            'delivery_status' => 'delivered',
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'status' => Shoot::STATUS_DELIVERED,
            'shoot_ready_notified_at' => null,
        ], $attributes));
    }

    private function payment(Shoot $shoot, float $amount): Payment
    {
        return Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'invoice_id' => null,
            'amount' => $amount,
            'status' => Payment::STATUS_COMPLETED,
        ]);
    }

    private function deliver(Shoot $shoot): void
    {
        (new SendShootReadyEmailJob($shoot->id))->handle(app(MailService::class), app(AutomationService::class));
    }

    private function messages(Shoot $shoot, string $source)
    {
        return Message::where('related_shoot_id', $shoot->id)->where('send_source', $source)->get();
    }
}
