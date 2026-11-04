<?php

namespace Tests\MySql;

use App\Models\ListingStudioCreditReservation;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderFundingEffect;
use App\Models\ListingStudioOutbox;
use App\Models\ListingStudioSubscription;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioOrderService;
use App\Services\ListingStudio\ListingStudioQuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class ListingStudioLifecycleConcurrencyTest extends TestCase
{
    use FreshDatabaseOutsideTransaction;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/support/studio-financial-fixtures.php';
        Support\configureStudioFinancialRace();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function races(): array
    {
        return [
            'one worker lease' => ['worker_claim'],
            'completion against cancellation' => ['completion_cancellation'],
            'same payment twice' => ['payment_replay'],
            'two partial refunds' => ['partial_refunds'],
            'payment against unpaid cancellation' => ['payment_cancellation'],
        ];
    }

    #[DataProvider('races')]
    public function test_independent_lifecycle_requests_preserve_one_financial_outcome(string $scenario): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $client = User::withoutEvents(fn () => User::factory()->create(['role' => 'client', 'account_status' => 'active']));
        $subscription = ListingStudioSubscription::create(['stripe_account_id' => config('listing-studio.stripe_account_id'), 'livemode' => true,
            'stripe_subscription_id' => 'sub_'.Str::uuid(), 'stripe_customer_id' => 'cus_race_'.$client->id, 'client_id' => $client->id,
            'plan_code' => 'starter', 'plan_name' => 'Starter', 'status' => 'active', 'sync_status' => 'synced', 'currency' => 'usd',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->addMonth()->startOfMonth()]);
        DB::table('listing_studio_credit_entries')->insert(['subscription_id' => $subscription->id, 'stripe_invoice_id' => 'in_race',
            'source_type' => 'invoice', 'source_key' => 'in_race', 'plan_code' => 'starter', 'amount_cents' => 700, 'currency' => 'usd',
            'period_start' => $subscription->current_period_start, 'expires_at' => $subscription->current_period_end, 'created_at' => now(), 'updated_at' => now()]);
        $quote = app(ListingStudioQuoteService::class)->create($client, ['kind' => 'ai_edit', 'service' => 'grass',
            'source' => ['kind' => 'upload', 'id' => (string) Str::uuid()]]);
        $card = in_array($scenario, ['payment_replay', 'partial_refunds', 'payment_cancellation'], true);
        $service = app(ListingStudioOrderService::class);
        $order = $service->acceptQuote($client, $quote->id, $card ? 'card' : 'credits', (string) Str::uuid())['order'];
        $item = $order->item;
        $fixture = ['order' => $order->id, 'client' => $client->id, 'item' => $item->id, 'attempt' => $item->processing_attempt_id];
        if ($scenario === 'worker_claim') {
            $fixture['actions'] = ['claim', 'claim'];
        } elseif ($scenario === 'completion_cancellation') {
            // A human-revision output avoids any filesystem/provider call while
            // retaining the real delivery, credit capture and cancellation code.
            $item->update(['kind' => 'human_revision', 'status' => 'processing']);
            $media = ['disk' => 'listing_studio_orders', 'sha256' => str_repeat('b', 64), 'mime' => 'image/jpeg',
                'width' => 1600, 'height' => 1000, 'byteSize' => 4000];
            $fixture['actions'] = ['complete', 'cancel'];
            $fixture['descriptor'] = ['itemId' => $item->id, 'clientId' => $client->id, 'path' => 'revision-deliveries/race.jpg', ...$media,
                'preview' => ['path' => 'items/'.$item->id.'/result-preview.jpg', ...$media]];
        } else {
            $fixture['evidence'] = ['provider' => 'stripe', 'eventId' => 'evt_race_'.$client->id,
                'accountId' => config('listing-studio.stripe_account_id'), 'liveMode' => true,
                'checkoutAttemptId' => (string) Str::uuid(), 'checkoutSessionId' => 'cs_race_'.$client->id,
                'paymentIntentId' => 'pi_race_'.$client->id, 'customerId' => 'cus_race_'.$client->id,
                'currency' => 'usd', 'amountPaidCents' => $order->total_cents, 'paidAt' => now()->toIso8601String(),
                'refundsVerified' => true, 'refunds' => []];
            $fixture['actions'] = $scenario === 'payment_cancellation' ? ['pay', 'cancel_unpaid'] : ['pay', 'pay'];
            if ($scenario === 'partial_refunds') {
                $service->confirmPayment($order->id, $fixture['evidence']);
                $fixture['actions'] = ['refund', 'refund'];
                foreach ([100, 200] as $index => $amount) {
                    $fixture['refunds'][] = ['provider' => 'stripe', 'eventId' => 'evt_refund_'.$index.'_'.$client->id,
                        'accountId' => config('listing-studio.stripe_account_id'), 'liveMode' => true,
                        'refundAttemptId' => (string) Str::uuid(), 'refundOperationId' => (string) Str::uuid(),
                        'paymentIntentId' => 'pi_race_'.$client->id, 'refundId' => 're_race_'.$index.'_'.$client->id,
                        'currency' => 'usd', 'amountCents' => $amount, 'status' => 'succeeded', 'occurredAt' => now()->toIso8601String()];
                }
            }
        }

        $outcomes = $this->runWorkers($fixture);
        $item->refresh();
        $order->refresh();
        if ($scenario === 'worker_claim') {
            $this->assertSame(['busy', 'claimed'], $outcomes);
            $this->assertSame('processing', $item->status);
            $this->assertNotNull($item->processing_lease_token);
            $this->assertTrue($item->processing_lease_expires_at->isFuture());
        } elseif ($scenario === 'completion_cancellation') {
            $reservation = ListingStudioCreditReservation::where('order_id', $order->id)->sole();
            $used = -(int) DB::table('listing_studio_credit_entries')->where('subscription_id', $subscription->id)->where('source_type', 'usage')->sum('amount_cents');
            $completed = $item->status === 'completed';
            $this->assertSame($completed ? ['completed', 'revision_state_conflict'] : ['cancelled', 'revision_state_conflict'], $outcomes);
            $this->assertSame($completed ? 'settled' : 'released', $order->funding_status);
            $this->assertSame($completed ? 'captured' : 'released', $reservation->status);
            $this->assertSame($completed ? 700 : 0, $used);
            $this->assertSame($completed, $item->output_descriptor !== null);
        } else {
            $this->assertSame(1, ListingStudioOrderFundingEffect::where('order_id', $order->id)->where('kind', 'payment')->count());
            $this->assertSame($order->total_cents, $order->paid_amount_cents);
            if ($scenario === 'payment_replay') {
                $this->assertSame(['settled', 'settled'], $outcomes);
                $this->assertSame('settled', $order->funding_status);
                $this->assertSame('queued', $item->status);
                $this->assertSame(1, ListingStudioOutbox::where('aggregate_id', $order->id)->where('type', 'process_item')->count());
            } elseif ($scenario === 'partial_refunds') {
                $this->assertSame(['refunded', 'refunded'], $outcomes);
                $this->assertSame(2, ListingStudioOrderFundingEffect::where('order_id', $order->id)->where('kind', 'refund')->count());
                $this->assertSame(300, $order->refunded_amount_cents);
                $this->assertSame('partially_refunded', $order->funding_status);
            } elseif ($item->status === 'cancelled') {
                $this->assertSame(['cancelled', 'settled'], $outcomes);
                $this->assertSame('refund_pending', $order->funding_status);
                $this->assertSame(1, ListingStudioOutbox::where('aggregate_id', $order->id)->where('type', 'refund_order')->count());
                $this->assertSame(0, ListingStudioOutbox::where('aggregate_id', $order->id)->where('type', 'process_item')->count());
            } else {
                $this->assertSame(['order_cancellation_unavailable', 'settled'], $outcomes);
                $this->assertSame('settled', $order->funding_status);
                $this->assertSame('queued', $item->status);
                $this->assertSame(0, ListingStudioOutbox::where('aggregate_id', $order->id)->where('type', 'refund_order')->count());
                $this->assertSame(1, ListingStudioOutbox::where('aggregate_id', $order->id)->where('type', 'process_item')->count());
            }
        }
        $this->assertSame(1, ListingStudioOrder::where('client_id', $client->id)->count());
    }

    private function runWorkers(array $fixture): array
    {
        $directory = sys_get_temp_dir().'/repro-mysql-lifecycle-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR));
        chmod($directory.'/fixture.json', 0600);
        $workers = [];
        try {
            foreach ([1, 2] as $id) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/studio-lifecycle-worker.php', $directory, (string) $id]);
                $worker->setTimeout(25);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while ((! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertFileExists($directory.'/ready-1');
            $this->assertFileExists($directory.'/ready-2');
            file_put_contents($directory.'/start', '1');
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $outcomes[] = trim($worker->getOutput());
            }
            sort($outcomes);

            return $outcomes;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
