<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShootEditingDispatchItem extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['sources' => 'array'];

    public function dispatch() { return $this->belongsTo(ShootEditingDispatch::class, 'dispatch_id'); }

    public function present(): array
    {
        return $this->only(['id', 'input_key', 'workflow', 'sources', 'shoot_service_id', 'lane', 'destination', 'editor_id', 'workspace_id', 'status', 'primary_version_id', 'return_url', 'error']);
    }
}
