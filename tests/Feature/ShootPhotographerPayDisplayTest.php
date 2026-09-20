<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
use App\Services\PayoutReportService;
use App\Services\Shoots\ShootPresenter;
use App\Services\Shoots\ShootServiceItemSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShootPhotographerPayDisplayTest extends TestCase
{
    use RefreshDatabase;

    public static function payCases(): array
    {
        return [
            'inherit catalog' => [null, 78.75, 78.75],
            'explicit unpaid' => [0, 78.75, 0.0],
            'booked override' => [60, 78.75, 60.0],
            'unconfigured' => [null, null, null],
        ];
    }

    #[DataProvider('payCases')]
    public function test_service_pay_agrees_across_list_detail_and_resource(?float $booked, ?float $catalog, ?float $expected): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create(['price' => 175, 'photographer_pay' => $catalog]);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);
        $shoot->services()->attach($service->id, [
            'price' => 175, 'quantity' => 1,
            'photographer_pay' => $booked, 'photographer_id' => $photographer->id,
        ]);
        $this->actingAs($photographer);
        $summary = app(ShootServiceItemSupport::class)->summaries($shoot->fresh())[0];
        $this->assertSame($expected, $summary['photographer_pay']);
        $this->assertSame($expected, $summary['photographerPay']);

        $request = Request::create('/api/shoots/'.$shoot->id);
        $request->setUserResolver(fn () => $photographer);
        $resource = (new ShootResource($shoot->fresh()))->resolve($request);
        $this->assertSame($expected, $resource['services'][0]['photographer_pay']);

        $presenter = app(ShootPresenter::class);
        $detail = $presenter->transformShoot($shoot->fresh())->toArray();
        $this->assertSame($expected, $detail['services'][0]['photographer_pay']);
        $this->assertSame($booked, $shoot->fresh()->services->first()->pivot->photographer_pay === null
            ? null : (float) $shoot->fresh()->services->first()->pivot->photographer_pay);
    }

    public function test_percent_catalog_pay_is_resolved_when_the_pivot_has_no_override(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create([
            'price' => 200,
            'photographer_pay' => null,
            'photographer_pay_type' => Service::PAY_TYPE_PERCENT,
            'photographer_pay_percent' => 45,
        ]);
        $shoot = Shoot::factory()->create(['photographer_id' => $photographer->id]);
        $shoot->services()->attach($service->id, [
            'price' => 200,
            'quantity' => 1,
            'photographer_pay' => null,
            'photographer_id' => $photographer->id,
        ]);

        $this->actingAs($photographer);
        $summary = app(ShootServiceItemSupport::class)->summaries($shoot->fresh())[0];
        $this->assertSame(90.0, $summary['photographer_pay']);
        $this->assertSame(90.0, $summary['photographerPay']);

        $request = Request::create('/api/shoots/'.$shoot->id);
        $request->setUserResolver(fn () => $photographer);
        $resource = (new ShootResource($shoot->fresh()))->resolve($request);
        $this->assertSame(90.0, $resource['services'][0]['photographer_pay']);
        $this->assertSame(90.0, $resource['totalPhotographerPay']);
        $this->assertSame(90.0, (float) $shoot->fresh()->total_photographer_pay);

        $detail = app(ShootPresenter::class)->transformShoot($shoot->fresh())->toArray();
        $this->assertSame(90.0, $detail['services'][0]['photographer_pay']);
    }

    public function test_payout_report_uses_percent_catalog_pay_when_the_pivot_is_empty(): void
    {
        $photographer = User::factory()->create(['role' => 'photographer']);
        $service = Service::factory()->create([
            'price' => 200,
            'photographer_pay' => null,
            'photographer_pay_type' => Service::PAY_TYPE_PERCENT,
            'photographer_pay_percent' => 45,
        ]);
        $shoot = Shoot::factory()->create([
            'photographer_id' => $photographer->id,
            'workflow_status' => Shoot::WORKFLOW_COMPLETED,
            'completed_at' => now(),
        ]);
        $shoot->services()->attach($service->id, [
            'price' => 200,
            'quantity' => 1,
            'photographer_pay' => null,
            'photographer_id' => $photographer->id,
        ]);

        $summary = app(PayoutReportService::class)
            ->buildPhotographerSummaries(now()->subDay(), now()->addDay())
            ->firstWhere('id', $photographer->id);

        $this->assertNotNull($summary);
        $this->assertSame(90.0, (float) $summary['gross_total']);
        $this->assertSame(90.0, (float) $summary['payout_total']);
    }
}
