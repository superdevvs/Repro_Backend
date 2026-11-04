<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListingStudioSubscriptionReceipt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['recipient', 'snapshot', 'invoice_id'];

    protected $casts = ['snapshot' => 'array', 'submission_started_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
}
