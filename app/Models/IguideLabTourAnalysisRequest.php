<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Every create requestId accepted for a tour analysis record, the creator's included (spec 4.3a). */
class IguideLabTourAnalysisRequest extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $hidden = ['request_hash'];
}
