<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShootEditingDispatch extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['plan' => 'array'];

    public function items() { return $this->hasMany(ShootEditingDispatchItem::class, 'dispatch_id'); }

    public function present(): array
    {
        return $this->only(['id', 'shoot_id', 'scope', 'destination', 'workflow', 'instructions', 'status', 'error', 'created_at'])
            + ['items' => $this->items->map->present()->all(), 'plan' => $this->plan];
    }
}
