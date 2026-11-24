<?php

namespace App\Models\Recruiting;

use Illuminate\Database\Eloquent\Model;

class RecruitStageChange extends Model
{
    protected $table = 'recruit_stage_changes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
