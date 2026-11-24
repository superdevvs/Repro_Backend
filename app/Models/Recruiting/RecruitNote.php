<?php

namespace App\Models\Recruiting;

use Illuminate\Database\Eloquent\Model;

/** A note or interview feedback (with scores) on an applicant. */
class RecruitNote extends Model
{
    protected $table = 'recruit_notes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['scores' => 'array'];
    }
}
