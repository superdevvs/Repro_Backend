<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SearchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['contextual-search.enabled' => true]);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_invoice_search_uses_summary_fields_without_running_invoice_pricing_or_loading_item_graphs(): void
    {
        $client = User::factory()->create(['role' => 'client', 'name' => 'Search Client']);
        $invoice = Invoice::withoutEvents(fn () => Invoice::factory()->create([
            'client_id' => $client->id, 'invoice_number' => 'INV-1447', 'status' => 'sent',
            'total_amount' => 300, 'balance_due' => 200,
        ]));
        $invoice->items()->create(['type' => 'charge', 'description' => 'Photo service', 'meta' => ['property_address' => '1447 Search Lane']]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->mock(\App\Services\Invoices\InvoicePricingBreakdown::class, fn ($mock) => $mock->shouldNotReceive('forInvoice'));

        DB::enableQueryLog();
        $this->getJson('/api/search?scope=invoices&q=1447')->assertOk()
            ->assertJsonPath('groups.0.results.0.id', (string) $invoice->id)
            ->assertJsonPath('groups.0.results.0.title', 'INV-1447')
            ->assertJsonPath('groups.0.results.0.metadata.Amount', '300.00')
            ->assertJsonPath('groups.0.results.0.metadata.Balance', 200);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        // Related rows are predicates, not full models loaded for editing UI.
        $this->assertFalse($queries->contains(fn ($sql) => str_starts_with($sql, 'select * from "invoice_items"')));
        $this->assertFalse($queries->contains(fn ($sql) => str_starts_with($sql, 'select * from "payments"')));
    }

    public function test_invoice_summary_search_still_applies_viewer_ownership_and_source_permission_middleware(): void
    {
        $viewer = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $own = Invoice::withoutEvents(fn () => Invoice::factory()->create(['client_id' => $viewer->id, 'invoice_number' => 'INV-Needle-Own']));
        Invoice::withoutEvents(fn () => Invoice::factory()->create(['client_id' => $other->id, 'invoice_number' => 'INV-Needle-Private']));
        Sanctum::actingAs($viewer);

        $this->getJson('/api/search?scope=invoices&q=Needle')->assertOk()
            ->assertJsonCount(1, 'groups.0.results')->assertJsonPath('groups.0.results.0.id', (string) $own->id);
        $viewer->forceFill(['permission_overrides' => ['allow' => [], 'deny' => ['invoices-view']]])->save();
        $this->getJson('/api/search?scope=invoices&q=Needle')->assertForbidden();
    }

    public function test_sqlite_relation_predicates_use_lookup_indexes_instead_of_scanning_every_related_row(): void
    {
        $invoiceQuery = Invoice::query()->whereHas('items', fn ($items) => $items->where('meta->property_address', 'like', '%1447%'));
        $invoicePlan = collect(DB::select('EXPLAIN QUERY PLAN '.$invoiceQuery->toSql(), $invoiceQuery->getBindings()))->pluck('detail')->implode("\n");
        $this->assertStringContainsString('invoice_items_invoice_lookup', $invoicePlan);
        $this->assertStringNotContainsString('SCAN invoice_items', $invoicePlan);

        $threadQuery = \App\Models\MessageThread::query()->whereHas('messages', fn ($messages) => app(\App\Services\Messaging\SmsInboxPolicy::class)->visibleMessages($messages));
        $threadPlan = collect(DB::select('EXPLAIN QUERY PLAN '.$threadQuery->toSql(), $threadQuery->getBindings()))->pluck('detail')->implode("\n");
        $this->assertStringContainsString('messages_thread_visibility_lookup', $threadPlan);
        $this->assertStringNotContainsString('SCAN messages', $threadPlan);
    }
}
