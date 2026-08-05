<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShootListingHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $client;
    protected User $photographer;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Admin User',
            'email' => 'admin-listing@test.com',
        ]);

        $this->client = User::factory()->create([
            'role' => 'client',
            'name' => 'Client User',
            'email' => 'client-listing@test.com',
        ]);

        $this->photographer = User::factory()->create([
            'role' => 'photographer',
            'name' => 'Photo User',
            'email' => 'photo-listing@test.com',
        ]);

        $this->service = Service::factory()->create([
            'name' => 'HDR Photos',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_load_the_operational_listing_after_refactor(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_date' => now()->addDay()->toDateString(),
            'address' => '123 Main St',
            'city' => 'Baltimore',
            'state' => 'MD',
            'zip' => '21201',
            'payment_status' => 'unpaid',
            'created_by' => (string) $this->admin->id,
        ]);
        $shoot->services()->attach($this->service->id, [
            'price' => 100,
            'quantity' => 1,
            'photographer_pay' => 30,
            'photographer_id' => $this->photographer->id,
        ]);

        $response = $this->getJson('/api/shoots?tab=scheduled&no_cache=1');

        $response->assertOk()
            ->assertJsonPath('meta.tab', 'scheduled')
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $shoot->id)
            ->assertJsonPath('data.0.services_list.0', 'HDR Photos')
            ->assertJsonPath('data.0.created_by', 'Admin User');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function photographer_listing_includes_service_level_assignments(): void
    {
        Sanctum::actingAs($this->photographer);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => null,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_SCHEDULED,
            'workflow_status' => Shoot::STATUS_SCHEDULED,
            'scheduled_date' => now()->addDay()->toDateString(),
            'address' => '789 Service Assignment Ave',
            'city' => 'Rockville',
            'state' => 'MD',
            'zip' => '20850',
            'payment_status' => 'unpaid',
            'created_by' => (string) $this->admin->id,
        ]);
        $shoot->services()->attach($this->service->id, [
            'price' => 120,
            'quantity' => 1,
            'photographer_pay' => 45,
            'photographer_id' => $this->photographer->id,
        ]);

        $response = $this->getJson('/api/shoots?tab=scheduled&no_cache=1');

        $response->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.id', $shoot->id)
            ->assertJsonPath('data.0.services.0.resolved_photographer_id', (string) $this->photographer->id)
            ->assertJsonPath('data.0.services.0.photographer.name', 'Photo User');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_load_history_and_export_csv_after_refactor(): void
    {
        Sanctum::actingAs($this->admin);

        $shoot = Shoot::factory()->create([
            'client_id' => $this->client->id,
            'photographer_id' => $this->photographer->id,
            'service_id' => $this->service->id,
            'status' => Shoot::STATUS_DELIVERED,
            'workflow_status' => Shoot::STATUS_DELIVERED,
            'scheduled_date' => now()->subDays(2)->toDateString(),
            'admin_verified_at' => now()->subDay(),
            'address' => '456 Market St',
            'city' => 'Washington',
            'state' => 'DC',
            'zip' => '20001',
            'base_quote' => 200,
            'tax_amount' => 12,
            'total_quote' => 212,
            'payment_type' => 'card',
            'created_by' => (string) $this->admin->id,
        ]);
        $shoot->services()->attach($this->service->id, [
            'price' => 200,
            'quantity' => 1,
            'photographer_pay' => 50,
            'photographer_id' => $this->photographer->id,
        ]);

        $invoice = Invoice::factory()->create([
            'shoot_id' => $shoot->id,
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
        ]);

        Payment::factory()->create([
            'shoot_id' => $shoot->id,
            'invoice_id' => $invoice->id,
            'amount' => 212,
            'status' => Payment::STATUS_COMPLETED,
            'processed_at' => now()->subHours(3),
        ]);

        $historyResponse = $this->getJson('/api/shoots/history?per_page=25');

        $historyResponse->assertOk()
            ->assertJsonPath('meta.group_by', 'shoot')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $shoot->id)
            ->assertJsonPath('data.0.client.name', 'Client User')
            ->assertJsonPath('data.0.services.0', 'HDR Photos');

        $historyPayload = $historyResponse->json();
        $this->assertSame(212.0, (float) data_get($historyPayload, 'data.0.financials.totalPaid'));

        $exportResponse = $this->get('/api/shoots/history/export');

        $exportResponse->assertOk();
        $csv = $exportResponse->streamedContent();
        $this->assertStringContainsString('text/csv', (string) $exportResponse->headers->get('content-type'));
        $this->assertStringContainsString('HDR Photos', $csv);
        $this->assertStringContainsString('Client User', $csv);
    }
}
