<?php

namespace Tests\Feature;

use App\Contracts\ListingStudio\ListingStudioFulfillmentDispatcher;
use App\Contracts\ListingStudio\ListingStudioRefundDispatcher;
use App\Models\ListingStudioOutbox;
use App\Services\ListingStudio\ListingStudioOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingStudioOutboxTest extends TestCase
{
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = config('database.default');
        config(['database.default' => 'outbox_test', 'database.connections.outbox_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        (require database_path('migrations/2026_11_01_000000_create_listing_studio_financial_core.php'))->up();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
    }

    protected function tearDown(): void
    {
        DB::purge('outbox_test');
        config(['database.default' => $this->previousConnection]);
        parent::tearDown();
    }

    public function test_lost_dispatch_response_retries_the_same_identity_outside_transactions_once(): void
    {
        $event = $this->event('process_item');
        $dispatcher = new class implements ListingStudioFulfillmentDispatcher
        {
            public array $calls = [];

            public function dispatch(ListingStudioOutbox $event): void
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Network inside transaction.');
                }
                $this->calls[] = [$event->id, $event->payload];
                if (count($this->calls) === 1) {
                    throw new \RuntimeException('secret provider response must never enter logs');
                }
            }
        };
        $this->app->instance(ListingStudioFulfillmentDispatcher::class, $dispatcher);
        Log::spy();
        $outbox = app(ListingStudioOutboxService::class);
        $this->assertSame(['dispatched' => 0, 'deferred' => 1], $outbox->dispatch());
        $this->assertSame('pending', $event->fresh()->status);
        $this->assertSame(['dispatched' => 0, 'deferred' => 0], $outbox->dispatch());
        $this->travel(31)->seconds();
        $this->assertSame(['dispatched' => 1, 'deferred' => 0], $outbox->dispatch());
        $this->assertSame($dispatcher->calls[0], $dispatcher->calls[1]);
        $this->assertSame('dispatched', $event->fresh()->status);
        $this->assertSame(['dispatched' => 0, 'deferred' => 0], $outbox->dispatch());
        Log::shouldHaveReceived('error')->once()->with('Listing Studio durable dispatch was deferred.');
    }

    public function test_a_stale_dispatch_owner_cannot_finish_a_replacement_lease(): void
    {
        $event = $this->event('finalize_revision_delivery');
        $replacement = (string) Str::uuid();
        $dispatcher = new class($replacement) implements ListingStudioFulfillmentDispatcher
        {
            public function __construct(private string $replacement) {}

            public function dispatch(ListingStudioOutbox $event): void
            {
                ListingStudioOutbox::whereKey($event->id)->update(['lease_token' => $this->replacement, 'reserved_at' => now()]);
            }
        };
        $this->app->instance(ListingStudioFulfillmentDispatcher::class, $dispatcher);
        app(ListingStudioOutboxService::class)->dispatch(1);
        $this->assertSame('dispatching', $event->fresh()->status);
        $this->assertSame($replacement, $event->fresh()->lease_token);
        $this->assertNull($event->fresh()->dispatched_at);
    }

    public function test_stale_claims_recover_and_refund_routes_to_its_own_dispatcher(): void
    {
        $event = $this->event('refund_order');
        $event->update(['status' => 'dispatching', 'reserved_at' => now()->subMinutes(6), 'lease_token' => (string) Str::uuid()]);
        $dispatcher = new class implements ListingStudioRefundDispatcher
        {
            public int $calls = 0;

            public function dispatch(ListingStudioOutbox $event): void
            {
                if (DB::transactionLevel() !== 0) {
                    throw new \LogicException('Refund inside transaction.');
                }
                $this->calls++;
            }
        };
        $this->app->instance(ListingStudioRefundDispatcher::class, $dispatcher);
        $this->assertSame(['dispatched' => 1, 'deferred' => 0], app(ListingStudioOutboxService::class)->dispatch());
        $this->assertSame(1, $dispatcher->calls);
        $unknown = $this->event('unsupported');
        app(ListingStudioOutboxService::class)->dispatch();
        $this->assertSame('needs_attention', $unknown->fresh()->status);
        DB::beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            app(ListingStudioOutboxService::class)->dispatch();
        } finally {
            DB::rollBack();
        }
    }

    private function event(string $type): ListingStudioOutbox
    {
        $id = (string) Str::uuid();

        return ListingStudioOutbox::create(['type' => $type, 'aggregate_id' => $id, 'idempotency_key' => $type.':'.$id,
            'payload' => ['orderId' => $id, 'itemId' => (string) Str::uuid(), 'attemptId' => (string) Str::uuid()],
            'status' => 'pending', 'available_at' => now()]);
    }
}
