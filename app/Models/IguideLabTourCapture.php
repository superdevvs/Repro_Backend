<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One capture of a tour analysis; position is the import sources[] index (contract order, D9). */
class IguideLabTourCapture extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['survey' => 'array', 'applicability' => 'array', 'candidates' => 'array', 'defaults_flags' => 'array',
        'dispatch_allowed' => 'boolean', 'exterior_hint' => 'boolean', 'position' => 'integer', 'paid_survey_requests' => 'integer',
        'surveyed_at' => 'datetime'];

    public function surveyRecord(): BelongsTo { return $this->belongsTo(IguideLabTourSurvey::class, 'survey_id'); }
}
