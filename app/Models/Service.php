<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'price', 'delivery_time', 'category_id', 'icon'];
    
    protected $casts = [
        'price' => 'decimal:2',
        'delivery_time' => 'integer',
        'category_id' => 'integer',
    ];

    
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

}
