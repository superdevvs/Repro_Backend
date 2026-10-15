<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootYearBuiltUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    private function paidFixture(string $actorRole = 'salesRep'): array
    {
        $rep = User::factory()->create(['role' => 'salesRep']);
        Sanctum::actingAs($actorRole === 'salesRep' ? $rep : User::factory()->create(['role' => $actorRole]));
        $shoot = Shoot::factory()->create([
            'rep_id' => $rep->id, 'status' => 'delivered', 'workflow_status' => 'delivered',
            'address' => '63 Inness Road', 'city' => 'Tenafly', 'state' => 'NJ', 'zip' => '07670',
            'scheduled_at' => '2026-10-13 14:00:00', 'scheduled_date' => '2026-10-13',
            'time' => '10:00:00', 'timezone' => 'America/New_York',
            'base_quote' => 824, 'tax_region' => 'NJ', 'tax_percent' => 0, 'tax_amount' => 0,
            'discount_type' => 'percent', 'discount_value' => 15, 'discount_amount' => 123.6,
            'total_quote' => 700.4, 'payment_status' => 'paid', 'mls_id' => 'MLS-KEPT',
            'property_details' => [
                'year_built' => 1940, 'yearBuilt' => 1940,
                'bedrooms' => 6, 'beds' => 6, 'bathrooms' => 7, 'baths' => 7,
                'sqft' => 4500, 'squareFeet' => 4500,
                'mls_id' => 'SOURCE-MLS', 'price' => 1450000,
                'field_sources' => ['year_built' => 'bridge_pub_assessments'],
            ],
            'tour_links' => ['property_mls' => 'TOUR-KEPT', 'property_price' => 1400000],
        ]);
        $service = Service::factory()->create(['price' => 824]);
        $shoot->services()->attach($service->id, [
            'price' => 824, 'quantity' => 1, 'photographer_id' => $shoot->photographer_id,
            'scheduled_at' => '2026-10-13 14:00:00', 'workflow_status' => 'completed',
        ]);
        $invoice = Invoice::factory()->create([
            'shoot_id' => $shoot->id, 'client_id' => $shoot->client_id,
            'user_id' => $shoot->client_id, 'role' => Invoice::ROLE_CLIENT,
            'subtotal' => 700.4, 'tax' => 0, 'total' => 700.4, 'total_amount' => 700.4,
            'amount_paid' => 700.4, 'balance_due' => 0, 'status' => 'paid', 'paid_at' => now(),
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'shoot_id' => $shoot->id,
            'type' => InvoiceItem::TYPE_CHARGE, 'description' => 'Original agreed service',
            'quantity' => 1, 'unit_amount' => 824, 'total_amount' => 824,
            'recorded_at' => now(), 'meta' => ['source' => 'original', 'snapshot' => 'keep'],
        ]);
        Payment::create([
            'shoot_id' => $shoot->id, 'invoice_id' => $invoice->id, 'amount' => 700.4,
            'currency' => 'USD', 'status' => Payment::STATUS_COMPLETED, 'processed_at' => now(),
        ]);

        return [$shoot->fresh(), $invoice->fresh()];
    }

    private function rows(string $model): array
    {
        return $model::orderBy('id')->get()->map(fn ($row) => $row->getRawOriginal())->all();
    }

    public static function yearCorrections(): array
    {
        return ['replace' => [2026], 'clear' => [null], 'unchanged' => [1940]];
    }

    #[DataProvider('yearCorrections')]
    public function test_year_built_correction_preserves_paid_ledger_and_booked_details(?int $year): void
    {
        [$shoot] = $this->paidFixture();
        $protected = ['address', 'city', 'state', 'zip', 'status', 'workflow_status',
            'scheduled_at', 'scheduled_date', 'time', 'timezone', 'photographer_id', 'rep_id',
            'mls_id', 'tour_links', 'base_quote', 'tax_region', 'tax_percent', 'tax_amount',
            'discount_type', 'discount_value', 'discount_amount', 'total_quote', 'payment_status'];
        $before = \Illuminate\Support\Arr::only($shoot->getRawOriginal(), $protected);
        $serviceBefore = $shoot->serviceItems()->sole()->getRawOriginal();
        $invoicesBefore = $this->rows(Invoice::class);
        $itemsBefore = $this->rows(InvoiceItem::class);
        $paymentsBefore = $this->rows(Payment::class);
        $details = $shoot->property_details;

        $this->patchJson('/api/shoots/'.$shoot->id, [
            'property_details' => array_merge($details, ['year_built' => $year, 'yearBuilt' => $year]),
            'bedrooms' => 6, 'bathrooms' => 7, 'sqft' => 4500,
            'client_id' => $shoot->client_id, 'state' => 'NJ',
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();

        $this->assertSame($year, $shoot->fresh()->property_details['year_built']);
        $this->assertSame($year, $shoot->fresh()->property_details['yearBuilt']);
        $this->assertSame($details['field_sources'], $shoot->fresh()->property_details['field_sources']);
        $this->assertSame($before, \Illuminate\Support\Arr::only($shoot->fresh()->getRawOriginal(), $protected));
        $this->assertSame($serviceBefore, $shoot->serviceItems()->sole()->getRawOriginal());
        $this->assertSame($invoicesBefore, $this->rows(Invoice::class));
        $this->assertSame($itemsBefore, $this->rows(InvoiceItem::class));
        $this->assertSame($paymentsBefore, $this->rows(Payment::class));
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_real_pricing_change_still_refreshes_invoice_alongside_year_built(): void
    {
        [$shoot, $invoice] = $this->paidFixture();
        $oldChargeId = $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->sole()->id;

        $this->patchJson('/api/shoots/'.$shoot->id, [
            'property_details' => ['year_built' => 2026, 'yearBuilt' => 2026],
            'discount_type' => 'percent', 'discount_value' => 10,
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();

        $this->assertSame(2026, $shoot->fresh()->property_details['year_built']);
        $this->assertSame(741.6, (float) $shoot->fresh()->total_quote);
        $this->assertSame(741.6, (float) $invoice->fresh()->total);
        $this->assertDatabaseMissing('invoice_items', ['id' => $oldChargeId]);
    }

    public static function staffRoles(): array
    {
        return [['admin'], ['superadmin']];
    }

    #[DataProvider('staffRoles')]
    public function test_staff_year_only_save_also_preserves_paid_ledger(string $role): void
    {
        [$shoot] = $this->paidFixture($role);
        $before = [$this->rows(Invoice::class), $this->rows(InvoiceItem::class), $this->rows(Payment::class)];

        $this->patchJson('/api/shoots/'.$shoot->id, [
            'property_details' => ['year_built' => 2026, 'yearBuilt' => 2026],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();

        $this->assertSame(2026, $shoot->fresh()->property_details['year_built']);
        $this->assertSame($before, [$this->rows(Invoice::class), $this->rows(InvoiceItem::class), $this->rows(Payment::class)]);
    }

    public function test_other_property_change_retains_normal_invoice_and_tour_refresh(): void
    {
        [$shoot, $invoice] = $this->paidFixture();
        $oldChargeId = $invoice->items()->where('type', InvoiceItem::TYPE_CHARGE)->sole()->id;

        $this->patchJson('/api/shoots/'.$shoot->id, [
            'property_details' => ['year_built' => 2026, 'yearBuilt' => 2026, 'price' => 1500000],
            'notify_client' => false, 'notify_photographer' => false,
        ])->assertOk();

        $this->assertSame(1500000, $shoot->fresh()->tour_links['property_price']);
        $this->assertSame(2026, $shoot->fresh()->property_details['year_built']);
        $this->assertDatabaseMissing('invoice_items', ['id' => $oldChargeId]);
    }
}
