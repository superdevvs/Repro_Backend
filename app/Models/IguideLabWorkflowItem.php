<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class IguideLabWorkflowItem extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['source' => 'array', 'data' => 'array', 'position' => 'integer', 'attempt_count' => 'integer', 'completed_at' => 'datetime'];
}
