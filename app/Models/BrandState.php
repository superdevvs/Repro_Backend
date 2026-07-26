<?php

namespace App\Models;

use App\Models\Concerns\IncrementsVersionOnSave;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandState extends Model
{
    use HasFactory, IncrementsVersionOnSave;

    protected $table = 'brand_state';
    protected $primaryKey = 'team_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['team_id', 'created_by', 'updated_by', 'settings'];

    protected $casts = [
        'team_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'settings' => 'array',
        'version' => 'integer',
    ];

    public static function latestCommittedForTeam(int $teamId): ?self
    {
        return static::query()->whereKey($teamId)->first();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}