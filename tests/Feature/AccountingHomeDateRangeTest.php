<?php

namespace Tests\Feature;

use App\Models\EditorPayout;
use App\Models\Invoice;
use App\Models\ListingStudioSubscription;
use App\Models\Payment;
use App\Models\PhotographerEquipment;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingHomeDateRangeTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'role' => 'client', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-05',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-07',
            'status' => 'sent', 'total' => 100, 'total_amount' => 100,
            'amount_paid' => 0, 'is_paid' => false, 'paid_at' => null,
        ], $attributes));
    }

    private function payment(Invoice $invoice, string $date, float $amount): Payment
    {
        return Payment::factory()->create([
            'invoice_id' => $invoice->id, 'shoot_id' => $invoice->shoot_id,
            'amount' => $amount, 'currency' => 'USD', 'status' => 'completed', 'processed_at' => $date,
            'stripe_payment_id' => 'pi_range_'.$invoice->id.'_'.$date,
        ]);
    }

    private function home(string $end = '2026-10-09')
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));

        return $this->getJson('/api/admin/accounting-home?start=2026-10-01&end='.$end)->assertOk();
    }

    public function test_collected_tracks_payment_dates_and_balances_use_selected_issue_dates(): void
    {
        $old = $this->invoice(['issue_date' => '2026-09-01', 'total' => 500, 'total_amount' => 500]);
        $this->payment($old, '2026-09-20', 300);
        $this->payment($old, '2026-10-02', 50);
        $selected = $this->invoice();
        $this->payment($selected, '2026-10-03', 25);
        $this->invoice(['issue_date' => '2026-11-01']);

        $this->home()->assertJsonPath('data.received', 75)->assertJsonPath('data.collected', 75)
            ->assertJsonPath('data.open', 75)->assertJsonPath('data.accounts', 1)->assertJsonPath('data.open_count', 1)
            ->assertJsonPath('data.aging.1–30 days', 75);
        $this->home('2026-10-02')->assertJsonPath('data.collected', 50)->assertJsonPath('data.open', 100)
            ->assertJsonPath('data.aging.Current', 100);
    }

    public function test_later_payment_and_refund_do_not_change_earlier_snapshot(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment($invoice, '2026-10-06', 100);
        $payment->refunds()->create([
            'shoot_id' => $invoice->shoot_id, 'amount' => 20, 'provider' => 'stripe',
            'provider_refund_id' => 're_range', 'operation_key' => 'range-refund', 'status' => 'succeeded',
            'created_at' => '2026-10-08',
        ]);
        $this->home('2026-10-05')->assertJsonPath('data.open', 100)->assertJsonPath('data.received', 0);
        $this->home('2026-10-07')->assertJsonPath('data.open', 0)->assertJsonPath('data.received', 100);
        $this->home()->assertJsonPath('data.open', 20)->assertJsonPath('data.received', 80)->assertJsonPath('data.collected', 80);
    }

    public function test_payout_periods_and_editor_completion_dates_scope_obligations_without_losing_cash(): void
    {
        $this->invoice(['role' => 'photographer', 'approval_status' => 'accounts_approved', 'total' => 500, 'total_amount' => 500]);
        $old = $this->invoice(['role' => 'photographer', 'approval_status' => 'accounts_approved', 'period_start' => '2026-08-01', 'period_end' => '2026-08-07', 'amount_paid' => 100, 'paid_at' => '2026-10-02', 'status' => 'paid']);
        $old->recordAuditEvent('paid', null, 'Legacy settled invoice', ['amount_paid' => 100]);
        $this->invoice(['role' => 'salesRep', 'approval_status' => 'pending_approval']);
        $editor = User::factory()->create(['role' => 'editor']);
        foreach ([['2026-09-01', false, null, 90], ['2026-10-04', true, '2026-10-12', 60], ['2026-09-02', true, '2026-10-03', 40]] as [$completed, $paid, $paidAt, $amount]) {
            EditorPayout::create(['editor_id' => $editor->id, 'shoot_id' => Shoot::factory()->create()->id, 'service_name' => 'HDR', 'payout_amount' => $amount, 'completed_at' => $completed, 'is_paid' => $paid, 'paid_at' => $paidAt]);
        }
        $this->home()->assertJsonPath('data.recipients.photographer.unpaid', 500)
            ->assertJsonPath('data.recipients.photographer.paid', 100)->assertJsonPath('data.recipients.rep.review', 1)
            ->assertJsonPath('data.recipients.editor.unpaid', 60)->assertJsonPath('data.recipients.editor.paid', 40)
            ->assertJsonPath('data.paid', 140);
    }

    public function test_equipment_and_subscription_billing_periods_follow_range(): void
    {
        PhotographerEquipment::create(['name' => 'Selected camera', 'issue_date' => '2026-10-02', 'status' => 'pending_verification']);
        PhotographerEquipment::create(['name' => 'Old camera', 'issue_date' => '2026-08-01', 'status' => 'pending_verification']);
        foreach (['2026-08-01', '2026-10-01'] as $date) {
            ListingStudioSubscription::create([
                'stripe_account_id' => 'acct_range', 'livemode' => false, 'stripe_subscription_id' => 'sub_'.$date,
                'stripe_customer_id' => 'cus_'.$date, 'status' => 'active', 'sync_status' => 'synced',
                'amount_cents' => 9900, 'currency' => 'USD', 'billing_interval' => 'month',
                'current_period_start' => $date, 'current_period_end' => substr($date, 0, 7).'-28',
            ]);
        }
        $this->home()->assertJsonPath('data.equipment.pending', 1)->assertJsonCount(1, 'data.subscriptions')
            ->assertJsonPath('data.subscriptions.0.end', '2026-10-28');
    }
}
