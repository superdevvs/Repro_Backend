<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Private operator authority; never returned by a public Lab DTO. */
class IguideLabTourBudget extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['limits'=>'array','reserved'=>'array','max_items'=>'integer','reserved_items'=>'integer',
        'expires_at'=>'datetime','revoked_at'=>'datetime'];
}
