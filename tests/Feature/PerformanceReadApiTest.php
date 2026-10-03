<?php
namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerformanceReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_matches_filtered_list_without_detail_graphs_and_preserves_access(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        foreach (range(1, 28) as $i) {
            Invoice::withoutEvents(fn () => Invoice::create([
                'period_start' => '2026-10-01', 'period_end' => '2026-10-02', 'invoice_number' => 'PERF-'.$i, 'client_id' => $client->id, 'user_id' => $client->id,
                'role' => 'client', 'status' => $i <= 26 ? 'sent' : 'paid',
                'total_amount' => $i * 10, 'amount_paid' => $i <= 26 ? 0 : $i * 10,
                'is_paid' => $i > 26, 'issue_date' => '2026-10-01', 'due_date' => '2099-12-31',
            ]));
        }
        Invoice::withoutEvents(fn () => Invoice::create(['period_start' => '2026-10-01', 'period_end' => '2026-10-02', 'invoice_number' => 'PRIVATE', 'client_id' => $other->id,
            'user_id' => $other->id, 'role' => 'client', 'status' => 'sent', 'total_amount' => 9999]));
        Sanctum::actingAs($client);
        $list = $this->getJson('/api/invoices?status=pending&per_page=25&sort=amount_desc&start=2026-10-01&end=2026-10-02')
            ->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('total', 26);
        $summary = $this->getJson('/api/invoices/summary?status=pending&start=2026-10-01&end=2026-10-02')
            ->assertOk()->assertJsonCount(26, 'data')->assertJsonPath('total', 26);
        $this->assertSame(260.0, (float) $list->json('data.0.total_amount'));
        $this->assertArrayNotHasKey('items', $summary->json('data.0'));
        $this->assertArrayNotHasKey('shoots', $summary->json('data.0'));
        $this->assertEquals(3510, collect($summary->json('data'))->sum('total_amount'));
        $this->getJson('/api/invoices?status=pending&per_page=25&page=2')->assertOk()->assertJsonCount(1, 'data');
        Sanctum::actingAs(User::factory()->create(['role' => 'editor']));
        $this->getJson('/api/invoices/summary')->assertForbidden();
    }

    public function test_card_projection_is_opt_in_and_cannot_bypass_client_scope(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $mine = Shoot::factory()->create(['client_id' => $client->id, 'status' => Shoot::STATUS_SCHEDULED]);
        Shoot::factory()->create(['status' => Shoot::STATUS_SCHEDULED]);
        Sanctum::actingAs($client);
        $full = $this->getJson('/api/shoots?tab=scheduled&no_cache=true')->assertOk();
        $card = $this->getJson('/api/shoots?tab=scheduled&view=card&include_filters=false')->assertOk();
        $this->assertSame([$mine->id], collect($card->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($full->json('data.0.status'), $card->json('data.0.status'));
        $this->assertArrayNotHasKey('files', $card->json('data.0'));
        $this->assertArrayNotHasKey('filters', $card->json('meta'));
        $this->getJson('/api/shoots/filters')->assertOk()->assertJsonStructure(['data' => ['clients', 'photographers', 'services']]);
        $this->assertNotEmpty($card->headers->get('Server-Timing'));
    }
}
