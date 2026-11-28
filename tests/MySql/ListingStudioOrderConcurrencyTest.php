<?php

namespace Tests\MySql;

use App\Models\ListingStudioCreditReservation;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderItem;
use App\Models\ListingStudioOutbox;
use App\Models\ListingStudioSubscription;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioCreditService;
use App\Services\ListingStudio\ListingStudioQuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\FreshDatabaseOutsideTransaction;
use Tests\TestCase;

final class ListingStudioOrderConcurrencyTest extends TestCase
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
        return ['last credit' => ['credits', false, 'insufficient_credits'],
            'last unpaid slot' => ['card', false, 'unpaid_order_limit'],
            'same request' => ['credits', true, 'replayed']];
    }

    #[DataProvider('races')]
    public function test_independent_order_requests_preserve_credit_quota_and_exact_replay(string $funding, bool $sameRequest, string $otherOutcome): void
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
        $quotes = [];
        foreach ([1, 2] as $index) {
            $quotes[] = app(ListingStudioQuoteService::class)->create($client, ['kind' => 'ai_edit', 'service' => 'grass', 'source' => ['kind' => 'upload', 'id' => (string) Str::uuid()]])->id;
        }
        $keys = [(string) Str::uuid(), (string) Str::uuid()];
        if ($sameRequest) {
            $quotes[1] = $quotes[0];
            $keys[1] = $keys[0];
        }
        $directory = sys_get_temp_dir().'/repro-mysql-order-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/fixture.json', json_encode(['client' => $client->id, 'quotes' => $quotes, 'keys' => $keys, 'funding' => $funding], JSON_THROW_ON_ERROR));
        $workers = [];
        try {
            foreach ([1, 2] as $id) {
                $worker = new Process([PHP_BINARY, __DIR__.'/support/studio-order-worker.php', $directory, (string) $id]);
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
            $this->assertSame(['created', $otherOutcome], $outcomes);
            $this->assertSame(1, ListingStudioOrder::where('client_id', $client->id)->count());
            $this->assertSame(1, ListingStudioOrderItem::where('client_id', $client->id)->count());
            $order = ListingStudioOrder::where('client_id', $client->id)->sole();
            $this->assertSame($funding === 'credits' ? 700 : 0, (int) ListingStudioCreditReservation::where('order_id', $order->id)->sum('amount_cents'));
            $this->assertSame($funding === 'credits' ? 1 : 0, ListingStudioOutbox::where('aggregate_id', $order->id)->count());
            $this->assertSame($funding === 'credits' ? 0 : 700, app(ListingStudioCreditService::class)->summary($subscription)['available_cents']);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            } rmdir($directory);
        }
    }
}
