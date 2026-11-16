<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Reserved workflow slot of a tour run, bound to its workflow after LabWorkflows::start() (spec 4.6). */
class IguideLabTourRunChunk extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['case_ids' => 'array', 'diagnostic' => 'array', 'position' => 'integer', 'start_attempts' => 'integer'];
}
