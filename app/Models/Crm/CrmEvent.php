<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled follow-up on a contact (or an interview with an applicant).
 * Planned until it is marked Done or Missed; cancelled when the contact is lost.
 */
class CrmEvent extends Model
{
    protected $table = 'crm_events';

    protected $guarded = ['id'];

    public const STATUSES = ['planned', 'done', 'missed', 'cancelled'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'completed_at' => 'datetime', 'via_robbie' => 'boolean'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CrmEventType::class, 'type_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Events the signed-in user may see: on contacts visible to them (the owner
     * scope applies), plus events assigned to them that have no contact, plus
     * interviews for people who can see recruiting.
     */
    public function scopeVisible(Builder $query, ?User $user): Builder
    {
        $recruiter = $user && app(\App\Services\Crm\CrmAccess::class)->can($user, 'crm-recruiting', 'view');

        return $query->where(function ($q) use ($user, $recruiter) {
            $q->whereIn('contact_id', CrmContact::query()->select('id'))
                ->orWhere(fn ($q) => $q->whereNull('contact_id')->whereNull('applicant_id')->where('assigned_user_id', $user?->id));
            if ($recruiter) {
                $q->orWhere(fn ($q) => $q->whereNull('contact_id')->whereNotNull('applicant_id'));
            }
        });
    }
}
