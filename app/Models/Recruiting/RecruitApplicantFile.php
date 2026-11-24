<?php

namespace App\Models\Recruiting;

use Illuminate\Database\Eloquent\Model;

/** A private document or sample: résumé, portfolio sample, licence, insurance certificate. */
class RecruitApplicantFile extends Model
{
    protected $table = 'recruit_applicant_files';

    protected $guarded = ['id'];

    public const KINDS = ['resume', 'portfolio_sample', 'license', 'insurance', 'application', 'other'];
}
