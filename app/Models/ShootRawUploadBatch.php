<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShootRawUploadBatch extends Model
{
    protected $table = 'shoot_raw_upload_batches';

    protected $guarded = [];

    protected $casts = [
        'shoot_id' => 'integer', 'actor_id' => 'integer', 'service_scope' => 'integer',
        'bracket_mode' => 'integer', 'total_files' => 'integer', 'start_position' => 'integer',
        'prepared' => 'boolean',
    ];
}
