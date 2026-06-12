<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeaturedShootImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'shoot_file_id',
        'sort_order',
        'alt_text',
        'focal_point',
        'variant_640_path',
        'variant_1280_path',
        'variant_1920_path',
        'width',
        'height',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function file()
    {
        return $this->belongsTo(ShootFile::class, 'shoot_file_id');
    }
}
