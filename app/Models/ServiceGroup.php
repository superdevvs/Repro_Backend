<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class ServiceGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function isFeatureAvailable(): bool
    {
        return Schema::hasTable('service_groups')
            && Schema::hasTable('service_group_service')
            && Schema::hasTable('service_group_user');
    }

    public function services()
    {
        return $this->belongsToMany(Service::class)
            ->withTimestamps()
            ->orderBy('name');
    }

    public function clients()
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps()
            ->where('role', 'client')
            ->orderBy('name');
    }
}
