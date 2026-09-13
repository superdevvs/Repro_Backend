<?php

namespace Tests\Feature;

use App\Http\Resources\ShootResource;
use App\Models\Service;
use App\Models\Shoot;
use App\Models\User;
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
}
