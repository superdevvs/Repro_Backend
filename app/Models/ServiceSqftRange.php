<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceSqftRange extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'sqft_from',
        'sqft_to',
        'duration',
        'price',
        'photographer_pay',
        'photo_count',
    ];

    protected $casts = [
        'service_id' => 'integer',
        'sqft_from' => 'integer',
        'sqft_to' => 'integer',
        'duration' => 'integer',
        'price' => 'decimal:2',
        'photographer_pay' => 'decimal:2',
        'photo_count' => 'integer',
    ];

    /**
     * Get the service that owns this sqft range.
     */
    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
