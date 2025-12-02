<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'user_id',
        'action',
        'description',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

