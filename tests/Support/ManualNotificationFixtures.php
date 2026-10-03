<?php

namespace Tests\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Shoot;
use App\Models\ShootActivityLog;

/** Recorded events needed when testing dispatch independently of event eligibility. */
final class ManualNotificationFixtures
{
    public static function recordEvents(Shoot $shoot): void
    {
        $shoot->forceFill([
            'approved_at' => now(), 'declined_at' => now(),
            'declined_reason' => 'Fixture decline', 'cancellation_reason' => 'Fixture cancellation',
        ])->saveQuietly();
        Invoice::factory()->create([
            'shoot_id' => $shoot->id, 'client_id' => $shoot->client_id, 'user_id' => $shoot->client_id,
            'total' => 1000, 'subtotal' => 1000, 'tax' => 0,
        ]);
        $payment = Payment::factory()->create([
            'shoot_id' => $shoot->id, 'invoice_id' => null, 'amount' => 50, 'payment_method' => 'card',
        ]);
        PaymentRefund::create([
            'shoot_id' => $shoot->id, 'payment_id' => $payment->id, 'amount' => 1,
            'status' => 'succeeded', 'provider' => 'manual',
        ]);
        ShootActivityLog::create([
            'shoot_id' => $shoot->id, 'action' => 'shoot_updated', 'metadata' => ['changes' => [
                'address' => ['from' => 'Before', 'to' => $shoot->address],
                'photographer_id' => ['from' => $shoot->rep_id, 'to' => $shoot->photographer_id],
            ]],
        ]);
    }
}
