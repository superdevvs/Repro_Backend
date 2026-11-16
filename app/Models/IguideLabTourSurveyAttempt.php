<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Private paid-attempt ledger row (the survey cap counter, D8); never returned by a public DTO. */
class IguideLabTourSurveyAttempt extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['attempt_index' => 'integer', 'http_status' => 'integer', 'duration_ms' => 'integer',
        'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
