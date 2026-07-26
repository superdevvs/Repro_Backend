<?php

namespace App\Models;

use App\Models\Concerns\IncrementsVersionOnSave;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    use HasFactory, HasUuids, IncrementsVersionOnSave;

    protected $fillable = [
        'team_id', 'created_by', 'name', 'workflow_id', 'config',
    ];

    protected $casts = [
        'team_id' => 'integer',
        'created_by' => 'integer',
        'config' => 'array',
        'version' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}