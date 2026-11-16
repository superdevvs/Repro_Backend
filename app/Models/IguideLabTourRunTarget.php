<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One room of a tour run; "Use this edit anyway" lives only here (D13). */
class IguideLabTourRunTarget extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['candidate_ids' => 'array', 'reasons' => 'array', 'acceptance_log' => 'array', 'has_attempt' => 'boolean',
        'position' => 'integer', 'accepted_at' => 'datetime'];
}
