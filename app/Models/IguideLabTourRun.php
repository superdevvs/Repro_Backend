<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One tour run of a lighting preset (D1); its workflows are created in sequential chunks (D3). */
class IguideLabTourRun extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['request_hash', 'monitor_token'];
    protected $casts = ['estimate' => 'array', 'selection_revision' => 'integer', 'chunk_size' => 'integer', 'consecutive_chunk_failures' => 'integer',
        'not_ready_ticks' => 'integer', 'monitor_seq' => 'integer', 'monitor_heartbeat_at' => 'datetime', 'cancelled_at' => 'datetime',
        'completed_at' => 'datetime'];

    public function targets(): HasMany { return $this->hasMany(IguideLabTourRunTarget::class, 'run_id')->orderBy('position'); }
    public function chunks(): HasMany { return $this->hasMany(IguideLabTourRunChunk::class, 'run_id')->orderBy('position'); }
}
