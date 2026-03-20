<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiVideoPreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'category',
        'prompt_template',
        'max_frames',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_frames' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Scope to only active presets
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get video generation jobs using this preset
     */
    public function videoGenerationJobs(): HasMany
    {
        return $this->hasMany(AiVideoGenerationJob::class, 'preset_id');
    }
}
