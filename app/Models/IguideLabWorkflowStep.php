<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class IguideLabWorkflowStep extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['input' => 'array', 'data' => 'array'];
}
