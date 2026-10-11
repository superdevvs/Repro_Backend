<?php

namespace App\Models;

use App\Services\Invoices\PhotographerPayoutBasis;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Covers attach/sync as well as the execution model's direct saves. */
class ShootServicePivot extends Pivot
{
    public $incrementing = true;
    protected $table = 'shoot_service';
    protected $casts = ['payout_snapshot' => 'array', 'price' => 'decimal:2', 'photographer_pay' => 'decimal:2', 'quantity' => 'integer', 'contracted_photo_count' => 'integer'];
    protected $hidden = ['payout_snapshot'];

    protected static function booted(): void
    {
        static::saving(function (self $line) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('shoot_service', 'payout_snapshot')) return;
            if ($line->payout_snapshot && ! $line->isDirty(['service_id', 'price', 'photographer_pay', 'quantity', 'contracted_photo_count'])) return;
            $shoot = Shoot::find($line->shoot_id);
            $service = Service::find($line->service_id);
            if ($shoot && $service && ! $shoot->isComplimentaryReshoot()) app(PhotographerPayoutBasis::class)->capture($line, $shoot, $service);
        });
    }
}
