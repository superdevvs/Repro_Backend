<?php

namespace App\Models;

use App\Models\Concerns\IncrementsVersionOnSave;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMedia extends Model
{
    use HasFactory, IncrementsVersionOnSave;

    protected $table = 'project_media';

    protected $fillable = [
        'project_id', 'team_id', 'created_by', 'media_ref', 'kind',
    ];

    protected $casts = [
        'team_id' => 'integer',
        'created_by' => 'integer',
        'version' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}