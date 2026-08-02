<?php

namespace Tests\Unit;

use App\Models\Service;
use PHPUnit\Framework\TestCase;

/**
 * Photographer pay resolution: flat amount vs percentage of the service price.
 *
 * The flat cases are regression guards — they must keep producing exactly what
 * they produced before percentages existed, because a change there silently
 * alters what every photographer is paid.
 */
class ServicePhotographerPayTest extends TestCase
{
    private function makeService(array $attributes): Service
    {
        $service = new Service();
        $service->forceFill(array_merge([
            'pricing_type' => 'fixed',
            'price' => 100.00,
            'photographer_pay' => null,
            'photographer_pay_type' => Service::PAY_TYPE_FIXED,
            'photographer_pay_percent' => null,
        ], $attributes));

        return $service;
    }

    public function test_flat_pay_is_returned_unchanged(): void
    {
        $service = $this->makeService(['photographer_pay' => 45.00]);

        $this->assertSame(45.00, $service->getPhotographerPayForSqft(null));
    }

    public function test_flat_pay_is_unaffected_by_a_stale_percent_value(): void
    {
        // A service switched back to fixed keeps paying the flat amount even if a
        // percentage was previously entered.
        $service = $this->makeService([
            'photographer_pay' => 45.00,
            'photographer_pay_percent' => 90.00,
            'photographer_pay_type' => Service::PAY_TYPE_FIXED,
        ]);

        $this->assertSame(45.00, $service->getPhotographerPayForSqft(null));
    }

    public function test_null_pay_stays_null_rather_than_zero(): void
    {
        $service = $this->makeService(['photographer_pay' => null]);

        $this->assertNull($service->getPhotographerPayForSqft(null));
    }

    public function test_percent_pay_resolves_against_the_service_price(): void
    {
        // The case from the meeting: 45% of a $100 service is $45.
        $service = $this->makeService([
            'price' => 100.00,
            'photographer_pay_type' => Service::PAY_TYPE_PERCENT,
            'photographer_pay_percent' => 45.00,
        ]);

        $this->assertSame(45.00, $service->getPhotographerPayForSqft(null));
    }

    public function test_percent_pay_rounds_to_cents(): void
    {
        $service = $this->makeService([
            'price' => 199.99,
            'photographer_pay_type' => Service::PAY_TYPE_PERCENT,
            'photographer_pay_percent' => 45.00,
        ]);

        // 199.99 * 0.45 = 89.9955 → 90.00
        $this->assertSame(90.00, $service->getPhotographerPayForSqft(null));
    }

    public function test_percent_type_without_a_percent_value_is_null(): void
    {
        $service = $this->makeService([
            'photographer_pay' => 45.00,
            'photographer_pay_type' => Service::PAY_TYPE_PERCENT,
            'photographer_pay_percent' => null,
        ]);

        $this->assertNull($service->getPhotographerPayForSqft(null));
    }

    public function test_zero_percent_resolves_to_zero(): void
    {
        $service = $this->makeService([
            'price' => 100.00,
            'photographer_pay_type' => Service::PAY_TYPE_PERCENT,
            'photographer_pay_percent' => 0,
        ]);

        $this->assertSame(0.00, $service->getPhotographerPayForSqft(null));
    }

    public function test_percent_never_exceeds_the_price_for_valid_input(): void
    {
        foreach ([1, 25, 45, 99.99, 100] as $percent) {
            $service = $this->makeService([
                'price' => 250.00,
                'photographer_pay_type' => Service::PAY_TYPE_PERCENT,
                'photographer_pay_percent' => $percent,
            ]);

            $pay = $service->getPhotographerPayForSqft(null);

            $this->assertNotNull($pay);
            $this->assertGreaterThanOrEqual(0, $pay);
            $this->assertLessThanOrEqual(250.00, $pay);
        }
    }
}
