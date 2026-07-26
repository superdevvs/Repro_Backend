<?php

namespace App\Models;

use App\Models\Concerns\IncrementsVersionOnSave;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedAsset extends Model
{
    use HasFactory, IncrementsVersionOnSave;

    protected $fillable = [
        'team_id', 'created_by', 'instruction_index', 'instruction_text',
        'asset_path', 'placement', 'alt_text', 'status',
    ];

    protected $casts = [
        'team_id' => 'integer',
        'created_by' => 'integer',
        'instruction_index' => 'integer',
        'version' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}