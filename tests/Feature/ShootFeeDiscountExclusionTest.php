<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\Coupon;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\ReproAi\Flows\InvoiceBillingFlow;
use App\Services\Shoots\ShootMutationSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootFeeDiscountExclusionTest extends TestCase
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

    private function lines(): array
    {
        return collect([
            ['Photos', 270, false],
            ['Onsite Cancellation/hold fee', 60, true],
            ['Travel fee - 90 Miles', 50, true],
        ])->map(function (array $line): array {
            $service = Service::factory()->create(['name' => $line[0], 'price' => $line[1], 'financial_only' => $line[2]]);
            return ['id' => $service->id, 'price' => $line[1], 'quantity' => 1];
        })->all();
    }

    public static function discounts(): array
    {
        return [['percent', 10, 27.0, 374.18], ['fixed', 500, 270.0, 116.6]];
    }

    #[DataProvider('discounts')]
    public function test_client_and_saved_discounts_leave_fees_whole(string $type, float $value, float $discount, float $total): void
    {
        $lines = $this->lines();
        $support = app(ShootMutationSupportService::class);
        $client = User::factory()->create(['client_discount_type' => $type, 'client_discount_value' => $value]);
        $shoot = Shoot::factory()->create(['discount_type' => $type, 'discount_value' => $value, 'state' => 'MD']);
        foreach ([$support->buildPricingCalculation($lines, $client, 'MD'), $support->buildPricingCalculationForExistingShoot($lines, $shoot)] as $pricing) {
            $this->assertSame(380.0, $pricing['service_subtotal']);
            $this->assertSame($discount, $pricing['discount_amount']);
            $this->assertSame($total, $pricing['total_quote']);
        }
    }

    public function test_stacked_coupon_cannot_consume_fees_or_exceed_remaining_eligible_services(): void
    {
        $client = User::factory()->create(['client_discount_type' => 'percent', 'client_discount_value' => 10]);
        Coupon::create(['code' => 'FEE-EXCLUSION', 'type' => 'fixed', 'amount' => 500, 'is_active' => true]);
        $pricing = app(ShootMutationSupportService::class)->buildPricingCalculation($this->lines(), $client, 'MD', null, 'FEE-EXCLUSION');
        $this->assertSame(27.0, $pricing['client_discount_amount']);
        $this->assertSame(243.0, $pricing['coupon_discount_amount']);
        $this->assertSame(110.0, $pricing['base_quote']);
        $this->assertSame(116.6, $pricing['total_quote']);
    }

    public function test_legacy_fee_names_are_excluded_without_trusting_request_metadata(): void
    {
        $support = app(ShootMutationSupportService::class);
        $client = User::factory()->create(['client_discount_type' => 'percent', 'client_discount_value' => 100]);
        foreach (['Travel fee - 60 Miles', 'Onsite Cancellation/hold fee', 'Hold fee', 'Mileage charge'] as $name) {
            $service = Service::factory()->create(['name' => $name, 'financial_only' => false, 'price' => 60]);
            $pricing = $support->buildPricingCalculation([['id' => $service->id, 'price' => 60, 'quantity' => 2, 'name' => 'Photos', 'financial_only' => false]], $client, 'MD');
            $this->assertSame(0.0, $pricing['discount_amount'], $name);
            $this->assertSame(127.2, $pricing['total_quote']);
        }
    }

    #[DataProvider('discountPaths')]
    public function test_edit_and_ai_paths_refresh_invoice_with_the_same_fee_exclusion(string $path): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($admin);
        $shoot = Shoot::factory()->create(['state' => 'MD', 'tax_region' => 'MD', 'base_quote' => 380, 'tax_amount' => 22.8, 'tax_percent' => 6, 'total_quote' => 402.8]);
        foreach ($this->lines() as $line) $shoot->services()->attach($line['id'], ['price' => $line['price'], 'quantity' => 1]);
        $invoice = app(InvoiceService::class)->generateForShoot($shoot);
        if ($path === 'overview') {
            $this->patchJson('/api/shoots/'.$shoot->id, ['discount_type' => 'percent', 'discount_value' => 10, 'notify_client' => false, 'notify_photographer' => false])->assertOk();
        } else {
            $session = AiChatSession::create(['user_id' => $admin->id, 'title' => 'Fee discount test', 'step' => 'apply_discount', 'state_data' => ['shoot_id' => $shoot->id, 'discount_percent' => 10]]);
            app(InvoiceBillingFlow::class)->handle($session, '10%');
        }
        $this->assertSame(27.0, (float) $shoot->fresh()->discount_amount);
        $this->assertSame(353.0, (float) $shoot->fresh()->base_quote);
        $this->assertSame(21.18, (float) $shoot->fresh()->tax_amount);
        $this->assertSame(374.18, (float) $shoot->fresh()->total_quote);
        $this->assertSame(374.18, (float) $invoice->fresh()->total);
    }

    public static function discountPaths(): array
    {
        return [['overview'], ['ai']];
    }
}
