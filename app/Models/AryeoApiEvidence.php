<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AryeoApiEvidence extends Model
{
    use HasUuids;

    protected $table = 'aryeo_api_evidence';

    protected $guarded = [];

    protected $casts = ['observed_at' => 'datetime'];
}
