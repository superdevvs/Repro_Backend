<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Private provider record; never returned by a public DTO. */
class IguideLabTourSurvey extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['data', 'protocol'];
    protected $casts = ['protocol' => 'array', 'data' => 'array', 'result' => 'array', 'usage' => 'array', 'generation' => 'integer',
        'dispatch_count' => 'integer', 'dispatched_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function attempts(): HasMany { return $this->hasMany(IguideLabTourSurveyAttempt::class, 'survey_id')->orderBy('attempt_index'); }
}
