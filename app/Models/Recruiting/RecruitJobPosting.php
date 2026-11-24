<?php

namespace App\Models\Recruiting;

use Illuminate\Database\Eloquent\Model;

/** A job posting for one role in one region, with a public apply page. */
class RecruitJobPosting extends Model
{
    protected $table = 'recruit_job_postings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
