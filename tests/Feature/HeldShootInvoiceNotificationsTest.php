<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\WeeklyDigestContexts;
use App\Support\ReportingWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeldShootInvoiceNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public static function holdAssociations(): iterable
    {
        foreach (['shoot', 'shoots', 'items'] as $association) {
            foreach (['status', 'workflow_status'] as $field) {
                foreach (['on_hold', 'hold_on', 'cancelled', 'canceled'] as $status) {
                    yield "$association-$field-$status" => [$association, $field, $status];
                }
            }
        }
    }

    #[DataProvider('holdAssociations')]
    public function test_held_invoices_are_excluded_from_summary_counts_totals_and_rows_and_resume_after_release(string $association, string $field, string $status): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $rep = User::factory()->create(['role' => 'salesRep']);
        $shoot = Shoot::factory()->create(['client_id' => $client->id, 'status' => 'completed', 'workflow_status' => 'completed', $field => $status]);
        [$start] = ReportingWeek::lastCompleted();
        $attributes = ['client_id' => $client->id, 'user_id' => $client->id, 'sales_rep_id' => $rep->id,
            'shoot_id' => null, 'role' => Invoice::ROLE_CLIENT, 'issue_date' => $start,
            'status' => 'pending', 'total' => 636, 'amount_paid' => 0];
        $held = Invoice::factory()->create($attributes);
        if ($association === 'shoot') {
            $held->update(['shoot_id' => $shoot->id]);
        } elseif ($association === 'shoots') {
            $held->shoots()->attach($shoot);
        } else {
            InvoiceItem::create(['invoice_id' => $held->id, 'shoot_id' => $shoot->id,
                'type' => InvoiceItem::TYPE_CHARGE, 'description' => 'Shoot', 'quantity' => 1,
                'unit_amount' => 636, 'total_amount' => 636]);
        }

        $this->assertTrue($held->suppressesExternalNotifications());
        foreach (['INVOICE_SUMMARY', 'WEEKLY_REP_INVOICE'] as $trigger) {
            $this->assertSame([], $this->contexts($trigger));
        }

        $active = Invoice::factory()->create(array_merge($attributes, ['total' => 100]));
        foreach (['INVOICE_SUMMARY', 'WEEKLY_REP_INVOICE'] as $trigger) {
            $context = $this->contexts($trigger)[0];
            $this->assertSame(1, $context['summary_invoice_count']);
            $this->assertSame('100.00', $context['summary_total_invoiced']);
            $this->assertSame('100.00', $context['summary_total_outstanding']);
            $this->assertSame([$active->id], array_column($context['summary_invoices'], 'id'));
        }

        $shoot->update([$field => 'completed']);
        $this->assertFalse($held->fresh()->suppressesExternalNotifications());
        $this->assertSame(2, $this->contexts('INVOICE_SUMMARY')[0]['summary_invoice_count']);
    }

    private function contexts(string $trigger): array
    {
        return array_values(iterator_to_array(app(WeeklyDigestContexts::class)->forRule(new AutomationRule(['trigger_type' => $trigger]))));
    }

    public function test_queued_invoice_email_and_sms_are_cancelled_after_shoot_hold_or_cancellation(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        foreach (['on_hold', 'cancelled'] as $status) {
            $shoot = Shoot::factory()->create(['status' => $status, 'workflow_status' => $status]);
            $invoice = Invoice::factory()->create(['shoot_id' => $shoot->id]);
            foreach (['EMAIL', 'SMS'] as $channel) {
                $message = \App\Models\Message::create(['channel' => $channel, 'direction' => 'OUTBOUND',
                    'send_source' => 'AUTOMATION', 'provider' => $channel === 'EMAIL' ? 'LOCAL_SMTP' : 'TELNYX',
                    'status' => 'QUEUED', 'related_invoice_id' => $invoice->id,
                    'to_address' => $channel === 'EMAIL' ? 'client@example.com' : '+12025550111']);
                $service = app(\App\Services\Messaging\MessagingService::class);
                $result = $channel === 'EMAIL' ? $service->dispatchStoredEmailMessage($message) : $service->dispatchStoredSmsMessage($message);
                $this->assertSame('CANCELLED', $result->status);
            }
        }
        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    public function test_delayed_summary_is_cancelled_when_a_shoot_becomes_ineligible(): void
    {
        foreach (['INVOICE_SUMMARY', 'WEEKLY_REP_INVOICE'] as $trigger) {
            foreach (['on_hold', 'cancelled'] as $status) {
                $rule = AutomationRule::where('trigger_type', $trigger)->firstOrFail();
                $rule->update(['is_active' => true]);
                $shoot = Shoot::factory()->create(['status' => 'completed', 'workflow_status' => 'completed']);
                $invoice = Invoice::factory()->create(['shoot_id' => $shoot->id]);
                $run = \App\Models\AutomationRun::create(['automation_rule_id' => $rule->id,
                    'trigger_type' => $trigger, 'status' => 'waiting',
                    'context_json' => ['summary_invoices' => [['id' => $invoice->id]]]]);
                $step = \App\Models\AutomationRunStep::create(['automation_run_id' => $run->id,
                    'automation_rule_id' => $rule->id, 'node_id' => 'delay', 'node_type' => 'logic.delay',
                    'status' => 'waiting', 'scheduled_for' => now()->subMinute()]);
                $shoot->update(['workflow_status' => $status]);
                app(\App\Services\Messaging\AutomationWorkflowExecutor::class)->resumeDueSteps();
                $this->assertSame('cancelled', $run->fresh()->status);
                $this->assertSame('cancelled', $step->fresh()->status);
            }
        }
    }
}
