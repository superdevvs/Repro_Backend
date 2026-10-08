<?php

namespace App\Services\Shoots;

use Illuminate\Database\Eloquent\Builder;

class ShootPaymentFilter
{
    public function apply(Builder $query, mixed $status): void
    {
        if (! in_array($status, ['paid', 'unpaid'], true)) {
            return;
        }

        // Match the card's payment summary: explicit paid overrides, zero-charge
        // shoots and fully settled balances are paid; partial balances are unpaid.
        // Deduplicate provider records and subtract successful refunds before
        // pagination so combined filters, counts, calendar and exports agree.
        $key = fn (string $alias): string => "COALESCE(NULLIF('stripe_session:' || {$alias}.stripe_session_id, 'stripe_session:'), NULLIF('stripe_payment:' || {$alias}.stripe_payment_id, 'stripe_payment:'), NULLIF('square_payment:' || {$alias}.square_payment_id, 'square_payment:'), 'payment_id:' || {$alias}.id)";
        $paid = "COALESCE((SELECT MAX(ROUND(SUM(p.amount - COALESCE((SELECT SUM(r.amount) FROM payment_refunds r WHERE r.payment_id = p.id AND LOWER(COALESCE(NULLIF(r.status, ''), 'succeeded')) IN ('succeeded', 'completed')), 0)), 2), 0) FROM payments p WHERE p.shoot_id = shoots.id AND p.status = 'completed' AND p.id = (SELECT MIN(p2.id) FROM payments p2 WHERE p2.shoot_id = p.shoot_id AND p2.status = 'completed' AND ".$key('p2').' = '.$key('p').')), 0)';
        $settled = "(LOWER(COALESCE(shoots.payment_status, '')) IN ('paid', 'marked_paid', 'mark_paid', 'paid_in_full', 'fully_paid') OR COALESCE(shoots.total_quote, 0) <= 0.01 OR {$paid} >= shoots.total_quote)";
        $query->whereRaw($status === 'paid' ? $settled : "NOT {$settled}");
    }
}
