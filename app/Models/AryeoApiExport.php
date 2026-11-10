<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AryeoApiExport extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'remote_baseline' => 'array', 'outcomes' => 'array', 'receipt' => 'array', 'metrics' => 'array', 'approved_at' => 'datetime', 'editing_window_until' => 'datetime', 'submitted_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function destination()
    {
        return $this->belongsTo(AryeoApiDestination::class, 'destination_id');
    }

    public function assets()
    {
        return $this->hasMany(AryeoApiAsset::class, 'export_id');
    }

    public function operations()
    {
        return $this->hasMany(AryeoApiOperation::class, 'export_id');
    }

    public function cases()
    {
        return $this->hasMany(AryeoApiCase::class, 'export_id');
    }
}
