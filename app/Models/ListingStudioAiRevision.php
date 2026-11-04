<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioAiRevision extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['request_key', 'request_hash', 'base_descriptor', 'output_descriptor', 'base_output_id', 'route'];

    protected $casts = ['sequence' => 'integer', 'base_descriptor' => 'array', 'output_descriptor' => 'array',
        'route' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
}
