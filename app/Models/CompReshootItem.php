<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompReshootItem extends Model
{
    use HasFactory;

    public const RESPONSIBILITY_PHOTOGRAPHER = 'photographer';

    public const RESPONSIBILITY_COMPANY = 'company';

    public const RESPONSIBILITY_CLIENT = 'client';

    public const RESPONSIBILITY_WEATHER_ACCESS = 'weather_access';

    public const RESPONSIBILITY_OTHER = 'other';

    public const RESPONSIBILITIES = [
        self::RESPONSIBILITY_PHOTOGRAPHER,
        self::RESPONSIBILITY_COMPANY,
        self::RESPONSIBILITY_CLIENT,
        self::RESPONSIBILITY_WEATHER_ACCESS,
        self::RESPONSIBILITY_OTHER,
    ];

    protected $fillable = [
        'shoot_id',
        'shoot_service_id',
        'source_shoot_service_id',
        'service_id_snapshot',
        'service_name_snapshot',
        'source_service_id_snapshot',
        'source_service_name_snapshot',
        'nominal_unit_price_snapshot',
        'quantity_snapshot',
        'nominal_total_snapshot',
        'reason_code',
        'reason_note',
        'responsibility',
        'responsible_staff_id',
        'created_by',
    ];

    protected $casts = [
        'nominal_unit_price_snapshot' => 'decimal:2',
        'quantity_snapshot' => 'integer',
        'nominal_total_snapshot' => 'decimal:2',
    ];

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function serviceItem()
    {
        return $this->belongsTo(ShootService::class, 'shoot_service_id');
    }

    public function sourceServiceItem()
    {
        return $this->belongsTo(ShootService::class, 'source_shoot_service_id');
    }

    public function responsibleStaff()
    {
        return $this->belongsTo(User::class, 'responsible_staff_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
