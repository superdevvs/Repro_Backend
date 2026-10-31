<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioBillingEmail extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['recipient', 'request_key'];

    protected $casts = ['client_id' => 'integer', 'actor_id' => 'integer',
        'submission_started_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
}
