<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioRevisionDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['descriptor', 'request_key', 'request_hash'];

    protected $casts = ['actor_id' => 'integer', 'expected_version' => 'integer', 'descriptor' => 'array',
        'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function task()
    {
        return $this->belongsTo(ListingStudioRevisionTask::class, 'task_id');
    }
}
