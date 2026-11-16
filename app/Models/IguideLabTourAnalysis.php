<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Shared tour analysis record per (project, import) (D9). Services scope every query by project_id. */
class IguideLabTourAnalysis extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['request_hash', 'monitor_token'];
    protected $casts = ['selections' => 'array', 'record_revision' => 'integer', 'monitor_seq' => 'integer', 'monitor_idle' => 'boolean',
        'monitor_heartbeat_at' => 'datetime', 'cancelled_at' => 'datetime', 'completed_at' => 'datetime'];

    public function captures(): HasMany { return $this->hasMany(IguideLabTourCapture::class, 'analysis_id')->orderBy('position'); }
    public function runs(): HasMany { return $this->hasMany(IguideLabTourRun::class, 'analysis_id'); }
}
