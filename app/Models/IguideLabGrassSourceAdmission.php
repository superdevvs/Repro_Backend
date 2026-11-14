<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
/** Actual material assessment, distinct from a scene proposal and final visual QA. */
class IguideLabGrassSourceAdmission extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['data' => 'array', 'result' => 'array', 'provider_attempts' => 'array', 'usage' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
