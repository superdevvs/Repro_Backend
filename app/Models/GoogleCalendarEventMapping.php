<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoogleCalendarEventMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'shoot_service_id',
        'user_id',
        'calendar_id',
        'google_event_id',
        'sync_fingerprint',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function connection()
    {
        return $this->belongsTo(GoogleCalendarConnection::class, 'user_id', 'user_id');
    }

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function serviceItem()
    {
        return $this->belongsTo(ShootService::class, 'shoot_service_id');
    }
}
