<?php

namespace App\Models\Recruiting;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone applying to photograph or sell for R/E Pro Photos. Never deleted:
 * "Not moving forward" keeps them on record and they can be reopened.
 */
class RecruitApplicant extends Model
{
    protected $table = 'recruit_applicants';

    protected $guarded = ['id'];

    public const STAGES = ['applied', 'screened', 'interview', 'trial', 'offer', 'hired'];
    public const EXIT = 'not_moving_forward';
    public const STAGE_LABELS = ['applied' => 'Sourced / Applied', 'screened' => 'Screened', 'interview' => 'Interview', 'trial' => 'Trial', 'offer' => 'Offer', 'hired' => 'Hired', 'not_moving_forward' => 'Not moving forward'];
    public const ROLES = ['photographer', 'sales_rep'];
    public const SOURCES = ['job_board', 'referral', 'social', 'website', 'other'];
    public const SPECIALTIES = ['residential', 'commercial', 'both'];

    protected function casts(): array
    {
        return [
            'states' => 'array', 'gear' => 'array', 'scores' => 'array', 'flies_drone' => 'boolean',
            'part107_expiry' => 'date', 'insurance_expiry' => 'date', 'stage_changed_at' => 'datetime', 'score' => 'float',
        ];
    }
}
