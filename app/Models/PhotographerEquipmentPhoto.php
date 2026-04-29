<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotographerEquipmentPhoto extends Model
{
    use HasFactory;

    public const TYPE_ADMIN_REFERENCE = 'admin_reference';
    public const TYPE_PHOTOGRAPHER_VERIFICATION = 'photographer_verification';

    protected $fillable = [
        'equipment_id',
        'uploaded_by',
        'type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(PhotographerEquipment::class, 'equipment_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
