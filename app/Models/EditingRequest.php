<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EditingRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'requester_id',
        'tracking_code',
        'summary',
        'details',
        'priority',
        'status',
        'target_team',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}

