<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhotographerAvailability extends Model
{
    use HasFactory;

    protected $fillable = [
        'photographer_id',
        'date',
        'day_of_week',
        'start_time',
        'end_time',
        'status',
    ];

    /**
     * Keep blocked/available days as YYYY-MM-DD. A UTC datetime would render
     * as the previous calendar day for photographers west of UTC.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                if ($value instanceof DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $value, $matches)) {
                    return $matches[1];
                }

                return null;
            },
        );
    }

    public function photographer()
    {
        return $this->belongsTo(User::class, 'photographer_id');
    }
}
