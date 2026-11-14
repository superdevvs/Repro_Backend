<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class IguideLabLifestyleFraming extends Model
{
    use HasUuids;
    protected $guarded=[];
    protected $casts=['data'=>'array','result'=>'array','provider_attempts'=>'array','usage'=>'array','started_at'=>'datetime','completed_at'=>'datetime'];
}
