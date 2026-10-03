<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShootUploadBatch extends Model
{
    protected $fillable = [
        'shoot_id',
        'actor_id',
        'upload_batch_id',
        'upload_type',
        'shoot_service_id',
        'bracket_mode',
        'upload_batch_total',
        'reserved_offset',
        'parallel_uploads',
    ];

    protected $casts = [
        'shoot_service_id' => 'integer',
        'bracket_mode' => 'integer',
        'upload_batch_total' => 'integer',
        'reserved_offset' => 'integer',
        'parallel_uploads' => 'integer',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function serviceItem()
    {
        return $this->belongsTo(ShootService::class, 'shoot_service_id');
    }
}
