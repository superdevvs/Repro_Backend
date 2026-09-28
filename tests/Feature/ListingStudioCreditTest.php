<?php

namespace Tests\Feature;

use App\Models\ListingStudioSubscription;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioCreditService;
use App\Services\ListingStudio\StripeSubscriptionGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ListingStudioCreditTest extends TestCase
{
    use RefreshDatabase;

    private ListingStudioSubscription $subscription;

    private ListingStudioCreditService $credits;

    private int $start;

    private int $end;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-20T00:00:00Z'));
        $this->start = CarbonImmutable::parse('2026-09-01T00:00:00Z')->timestamp;
        $this->end = CarbonImmutable::parse('2026-10-01T00:00:00Z')->timestamp;
        $client = User::factory()->create(['role' => 'client']);
        $this->subscription = ListingStudioSubscription::create([
            'stripe_account_id' => 'acct_15OuBBLsiebrQZS4', 'livemode' => true,
            'stripe_subscription_id' => 'sub_credit', 'stripe_customer_id' => 'cus_credit',
            'client_id' => $client->id, 'plan_code' => 'starter', 'plan_name' => 'Starter',
            'status' => 'active', 'sync_status' => 'synced', 'currency' => 'usd',
            'current_period_start' => CarbonImmutable::createFromTimestampUTC($this->start),
            'current_period_end' => CarbonImmutable::createFromTimestampUTC($this->end),
        ]);
        $this->credits = app(ListingStudioCreditService::class);
    }

    public function test_paid_monthly_invoice_grants_once_and_expires_without_rollover(): void
    {
        $this->apply($this->invoice());
        $this->apply($this->invoice());
        $this->assertSame(6000, $this->balance());
        $this->assertSame(1, DB::table('listing_studio_credit_entries')->where('source_type', 'invoice')->count());
        $this->travelTo(CarbonImmutable::createFromTimestampUTC($this->end));
        $this->assertSame(0, $this->balance());
        $this->assertSame(1, DB::table('listing_studio_credit_entries')->where('source_type', 'invoice')->count());
    }

    public function test_renewal_uses_new_period_allowance_instead_of_carrying_old_balance(): void
    {
        $this->apply($this->invoice());
        $nextEnd = CarbonImmutable::parse('2026-11-01T00:00:00Z')->timestamp;
        $this->subscription->update(['plan_code' => 'pro', 'current_period_start' => CarbonImmutable::createFromTimestampUTC($this->end), 'current_period_end' => CarbonImmutable::createFromTimestampUTC($nextEnd)]);
        $renewal = $this->invoice('in_renewal', [$this->line('il_renewal', 'pro', 9900, false, $this->end, $nextEnd)]);
        $renewal['billing_reason'] = 'subscription_cycle';
        $renewal['amount_paid'] = 9900;
        $this->apply($renewal);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $this->assertSame(13500, $this->balance());
        $this->assertSame(13500, $this->credits->summary($this->subscription)['monthly_allowance_cents']);
    }

    public function test_upgrade_uses_signed_stripe_proration_lines_without_another_full_grant(): void
    {
        $this->apply($this->invoice());
        $this->subscription->update(['plan_code' => 'pro']);
        $invoice = $this->prorationInvoice();
        $this->apply($invoice);
        $this->apply($invoice);
        // $60 - $30 unused Starter + $67.50 remaining Pro.
        $this->assertSame(9750, $this->balance());
        $this->assertSame(3, DB::table('listing_studio_credit_entries')->where('source_type', 'invoice')->count());
    }

    public function test_downgrade_reduces_current_credits_using_negative_proration(): void
    {
        $this->subscription->update(['plan_code' => 'pro']);
        $original = $this->invoice('in_pro', [$this->line('il_pro', 'pro', 9900)]);
        $original['amount_paid'] = 9900;
        $this->apply($original);
        $downgrade = $this->invoice('in_down', [$this->line('il_old', 'pro', -4950, true), $this->line('il_new', 'starter', 2450, true)]);
        $downgrade['billing_reason'] = 'subscription_update';
        $downgrade['amount_paid'] = 0;
        $this->subscription->update(['plan_code' => 'starter']);
        $this->apply($downgrade);
        $this->assertSame(9750, $this->balance());
    }

    public function test_succeeded_partial_refunds_prorate_credits_and_duplicates_cannot_remove_them_twice(): void
    {
        $invoice = $this->invoice();
        $this->apply($invoice);
        $this->refund('re_half', 'in_initial', 2450, 'pending');
        $this->apply($invoice);
        $this->assertSame(6000, $this->balance());
        $this->refund('re_half', 'in_initial', 2450, 'succeeded');
        $this->apply($invoice);
        $this->apply($invoice);
        $this->assertSame(3000, $this->balance());
        $this->refund('re_rest', 'in_initial', 2450, 'succeeded');
        $this->apply($invoice);
        $this->assertSame(0, $this->balance());
    }

    public function test_refund_before_signup_is_applied_when_invoice_credits_are_later_created(): void
    {
        $this->refund('re_first', 'in_initial', 4900, 'succeeded');
        $this->apply($this->invoice());
        $this->assertSame(0, $this->balance());
    }

    public function test_refunded_upgrade_reverses_only_the_prorated_credit_increase(): void
    {
        $this->apply($this->invoice());
        $upgrade = $this->prorationInvoice();
        $this->apply($upgrade);
        $this->assertSame(9750, $this->balance());
        $this->refund('re_upgrade', 'in_upgrade', 2500, 'succeeded');
        $this->apply($upgrade);
        $this->assertSame(6000, $this->balance());
    }

    public function test_expired_invoice_refund_does_not_remove_new_period_credits(): void
    {
        $old = $this->invoice('in_old', [$this->line('il_old', 'starter', 4900, false, $this->start - 2678400, $this->start)]);
        $this->apply($old);
        $this->apply($this->invoice());
        $this->refund('re_old', 'in_old', 4900, 'succeeded');
        $this->apply($old);
        $this->assertSame(6000, $this->balance());
    }

    public function test_fully_discounted_paid_month_still_gets_full_credit_allowance(): void
    {
        $invoice = $this->invoice();
        $invoice['amount_paid'] = 0;
        $this->apply($invoice);
        $this->assertSame(6000, $this->balance());
    }

    public function test_unpaid_or_unlinked_subscriptions_do_not_receive_credits(): void
    {
        $invoice = $this->invoice();
        $invoice['status'] = 'open';
        $this->apply($invoice);
        $this->subscription->update(['client_id' => null]);
        $this->apply($this->invoice());
        $this->assertSame(0, $this->balance());
        $this->assertDatabaseCount('listing_studio_credit_entries', 0);
    }

    public function test_foreign_customer_invoice_is_rejected_without_credits(): void
    {
        $invoice = $this->invoice();
        $invoice['customer'] = 'cus_foreign';
        $this->expectException(\RuntimeException::class);
        $this->apply($invoice);
    }

    public function test_one_off_items_or_foreign_subscription_lines_cannot_mint_monthly_credits(): void
    {
        $manual = $this->line('il_manual', 'starter', 4900);
        $manual['type'] = 'invoiceitem';
        $manual['parent'] = ['type' => 'invoice_item_details', 'invoice_item_details' => ['subscription' => null, 'proration' => false]];
        $foreign = $this->line('il_foreign', 'starter', 4900);
        $foreign['parent']['subscription_item_details']['subscription'] = 'sub_foreign';
        $this->apply($this->invoice('in_manual', [$manual, $foreign]));
        $this->assertSame(0, $this->balance());
        $this->assertDatabaseCount('listing_studio_credit_entries', 0);
    }

    public function test_delayed_invoice_event_fetches_its_canonical_invoice_outside_transaction(): void
    {
        $older = $this->invoice('in_older');
        $gateway = Mockery::mock(StripeSubscriptionGateway::class);
        $baseline = DB::transactionLevel(); // RefreshDatabase owns an outer test transaction.
        $gateway->shouldReceive('invoice')->once()->with('in_older')->andReturnUsing(function () use ($older, $baseline) {
            $this->assertSame($baseline, DB::transactionLevel());

            return $older;
        });
        $service = new ListingStudioCreditService($gateway);
        $this->assertCount(2, $service->prepareInvoices(['type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_older']]], ['latest_invoice' => $this->invoice()]));
    }

    public function test_anchor_reset_does_not_keep_the_previous_unexpired_bucket_available(): void
    {
        $this->apply($this->invoice());
        $newStart = CarbonImmutable::parse('2026-09-20T00:00:00Z')->timestamp;
        $newEnd = CarbonImmutable::parse('2026-10-20T00:00:00Z')->timestamp;
        $this->subscription->update(['plan_code' => 'studio', 'current_period_start' => CarbonImmutable::createFromTimestampUTC($newStart), 'current_period_end' => CarbonImmutable::createFromTimestampUTC($newEnd)]);
        $invoice = $this->invoice('in_reset', [$this->line('il_reset', 'studio', 24900, false, $newStart, $newEnd)]);
        $invoice['billing_reason'] = 'subscription_update';
        $invoice['amount_paid'] = 24900;
        $this->apply($invoice);
        $this->assertSame(37500, $this->balance());
    }

    public function test_partial_invoice_line_page_is_retried_instead_of_granting_incomplete_credits(): void
    {
        $invoice = $this->invoice();
        $invoice['lines']['has_more'] = true;
        $this->expectException(\RuntimeException::class);
        $this->apply($invoice);
    }

    private function invoice(string $id = 'in_initial', ?array $lines = null): array
    {
        return ['id' => $id, 'livemode' => true, 'status' => 'paid', 'currency' => 'usd', 'amount_paid' => 4900,
            'customer' => 'cus_credit', 'parent' => ['subscription_details' => ['subscription' => 'sub_credit']],
            'billing_reason' => 'subscription_create', 'lines' => ['data' => $lines ?? [$this->line('il_initial', 'starter', 4900)], 'has_more' => false]];
    }

    private function prorationInvoice(): array
    {
        $invoice = $this->invoice('in_upgrade', [$this->line('il_old', 'starter', -2450, true), $this->line('il_new', 'pro', 4950, true)]);
        $invoice['billing_reason'] = 'subscription_update';
        $invoice['amount_paid'] = 2500;

        return $invoice;
    }

    private function line(string $id, string $plan, int $amount, bool $proration = false, ?int $start = null, ?int $end = null): array
    {
        return ['id' => $id, 'type' => 'subscription', 'quantity' => 1, 'amount' => $amount,
            'pricing' => ['price_details' => ['price' => config('listing-studio.plans.'.$plan.'.live_price')]],
            'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['subscription' => 'sub_credit', 'proration' => $proration]],
            'period' => ['start' => $start ?? $this->start, 'end' => $end ?? $this->end]];
    }

    private function refund(string $id, string $invoice, int $amount, string $status): void
    {
        DB::table('listing_studio_subscription_refunds')->updateOrInsert(['stripe_refund_id' => $id], [
            'stripe_account_id' => $this->subscription->stripe_account_id, 'livemode' => true,
            'stripe_subscription_id' => 'sub_credit', 'stripe_customer_id' => 'cus_credit', 'stripe_invoice_id' => $invoice,
            'stripe_payment_intent_id' => 'pi_credit', 'stripe_charge_id' => 'ch_credit', 'amount_cents' => $amount,
            'currency' => 'usd', 'status' => $status, 'refund_created_at' => now(), 'last_event_id' => 'evt_refund',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function apply(array $invoice): void
    {
        DB::transaction(fn () => $this->credits->reconcileInvoices($this->subscription, [$invoice]));
    }

    private function balance(): int
    {
        return $this->credits->summary($this->subscription)['available_cents'];
    }
}
