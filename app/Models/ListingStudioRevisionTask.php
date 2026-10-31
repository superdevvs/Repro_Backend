<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioRevisionTask extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['team_id' => 'integer', 'assignee_id' => 'integer', 'version' => 'integer',
        'assigned_at' => 'datetime', 'started_at' => 'datetime', 'delivered_at' => 'datetime'];

    public function item()
    {
        return $this->belongsTo(ListingStudioOrderItem::class, 'order_item_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function deliveryAttempt()
    {
        return $this->belongsTo(ListingStudioRevisionDelivery::class, 'delivery_attempt_id');
    }
}
