<?php

namespace App\Models;

use App\Models\Concerns\IncrementsVersionOnSave;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, HasUuids, IncrementsVersionOnSave;

    protected $fillable = [
        'team_id', 'created_by', 'shoot_id', 'name', 'address',
        'source_type', 'workflow_id', 'status', 'request_id', 'template_id',
        'workflow_config', 'brand_state',
    ];

    protected $casts = [
        'team_id' => 'integer', 'created_by' => 'integer',
        'shoot_id' => 'integer', 'version' => 'integer',
        'workflow_config' => 'array', 'brand_state' => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProjectMedia::class);
    }

    public function aiEditingJobs(): HasMany
    {
        return $this->hasMany(AiEditingJob::class);
    }

    public function aiListingVideoJobs(): HasMany
    {
        return $this->hasMany(AiListingVideoJob::class);
    }

    public function aiReelJobs(): HasMany
    {
        return $this->hasMany(AiReelJob::class);
    }
}