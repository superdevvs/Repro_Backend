<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class IguideLabWorkflow extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected $casts = ['policy' => 'array', 'cancelled_at' => 'datetime', 'completed_at' => 'datetime'];
}
