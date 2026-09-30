<?php

namespace Tests\Unit;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootClientReleaseAccessService;
use App\Services\Shoots\ShootPaymentStatusSupport;
use Mockery;
use Tests\TestCase;

class ShootClientReleaseAccessServiceNoChargeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_explicit_zero_dollar_shoot_is_unlocked_even_when_stored_status_is_no_payment_required(): void
    {
        $shoot = new Shoot([
            'total_quote' => 0,
            'payment_status' => Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED,
            'bypass_paywall' => false,
        ]);
        $access = $this->makeAccess();
        $client = new User;
        $client->role = 'client';

        $this->assertSame('paid', $access->resolvePaymentStatus($shoot));
        $this->assertFalse($access->isPublicReleaseLocked($shoot));
        $this->assertFalse($access->isClientReleaseLocked($shoot, $client));
        $this->assertFalse($access->isArchiveReleaseLocked($shoot, null, $client));
    }

    public function test_stale_unpaid_status_does_not_lock_an_explicit_zero_dollar_shoot(): void
    {
        $shoot = new Shoot(['total_quote' => '0.00', 'payment_status' => 'unpaid']);

        $this->assertFalse($this->makeAccess()->isPublicReleaseLocked($shoot));
    }

    public function test_unknown_quote_and_positive_unpaid_quote_remain_locked(): void
    {
        $access = $this->makeAccess();
        $unknown = new Shoot(['total_quote' => null, 'payment_status' => Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED]);
        $charged = new Shoot(['total_quote' => 299, 'payment_status' => 'partial']);

        $this->assertTrue($access->isPublicReleaseLocked($unknown));
        $this->assertTrue($access->isPublicReleaseLocked($charged));
    }

    private function makeAccess(): ShootClientReleaseAccessService
    {
        return new ShootClientReleaseAccessService(
            Mockery::mock(ShootPaymentStatusSupport::class)->shouldIgnoreMissing(),
        );
    }
}
