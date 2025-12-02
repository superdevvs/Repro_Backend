<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootMediaAlbum extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'photographer_id',
        'source',
        'folder_path',
        'cover_image_path',
        'is_watermarked',
    ];

    protected $casts = [
        'is_watermarked' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Source constants
    const SOURCE_DROPBOX = 'dropbox';
    const SOURCE_LOCAL = 'local';

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }

    public function files()
    {
        return $this->hasMany(ShootFile::class, 'album_id');
    }
}

