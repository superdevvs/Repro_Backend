<?php

namespace App\Models\Crm;

use App\Models\Crm\Scopes\OwnedByRepScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person the sales team is working: a lead in the pipeline, a client in the
 * pool, or a lost contact. Contacts are never deleted.
 *
 * Every query goes through OwnedByRepScope, so a sales rep only ever sees the
 * contacts assigned to them. System code that must look across all contacts
 * (duplicate checks, jobs, webhooks) calls unscopedForSystem() explicitly.
 *
 * assigned_rep_id is deliberately not fillable: it changes only through
 * CrmAssignmentService, which checks the assign permission and logs the change.
 */
#[ScopedBy(OwnedByRepScope::class)]
class CrmContact extends Model
{
    protected $table = 'crm_contacts';

    public const STAGE_OUTREACH = 'outreach';
    public const STAGE_INQUIRY = 'inquiry';
    public const STAGE_ESTIMATE = 'estimate';
    public const STAGE_BOOKED = 'booked';
    public const STAGE_CLIENT_POOL = 'client_pool';
    public const STAGE_LOST = 'lost';

    /** The four sales stages shown as board columns, in order. */
    public const PIPELINE_STAGES = [self::STAGE_OUTREACH, self::STAGE_INQUIRY, self::STAGE_ESTIMATE, self::STAGE_BOOKED];

    public const STAGES = [...self::PIPELINE_STAGES, self::STAGE_CLIENT_POOL, self::STAGE_LOST];

    public const STAGE_LABELS = [
        self::STAGE_OUTREACH => 'Outreach',
        self::STAGE_INQUIRY => 'Inquiry',
        self::STAGE_ESTIMATE => 'Estimate / Quoted',
        self::STAGE_BOOKED => 'Booked 1st shoot',
        self::STAGE_CLIENT_POOL => 'Client pool',
        self::STAGE_LOST => 'Lost',
    ];

    public const SEGMENTS = ['residential', 'commercial'];
    public const CLIENT_TYPES = ['one_off', 'recurring'];
    public const LOST_REASONS = ['price', 'competitor', 'no_response', 'other'];
    public const LOST_REASON_LABELS = ['price' => 'Price', 'competitor' => 'Chose competitor', 'no_response' => 'No response', 'other' => 'Other'];
    public const LEAD_SOURCES = ['cold_outreach', 'referral', 'conference', 'website', 'other'];
    public const LEAD_SOURCE_LABELS = ['cold_outreach' => 'Cold outreach', 'referral' => 'Referral', 'conference' => 'Conference', 'website' => 'Website', 'other' => 'Other'];

    protected $fillable = [
        'name', 'company', 'title', 'email', 'phone', 'address', 'website',
        'market_state', 'market_city', 'segment', 'client_type', 'lead_source',
    ];

    protected function casts(): array
    {
        return [
            'lost_at' => 'datetime',
            'first_shoot_date' => 'date',
            'email_opt_out_at' => 'datetime',
            'stage_changed_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'first_contact_due_at' => 'datetime',
            'review_reasons' => 'array',
            'needs_review' => 'boolean',
            'sms_consent' => 'boolean',
            'assigned_rep_id' => 'integer',
            'client_user_id' => 'integer',
            'company_id' => 'integer',
            'sms_consent_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'health_changed_at' => 'datetime',
            'last_shoot_on' => 'date',
        ];
    }

    /** The company text links the contact to a shared company record (created on first use). */
    protected static function booted(): void
    {
        static::saving(function (CrmContact $contact) {
            if ($contact->exists && $contact->isDirty('email_normalized')) {
                $contact->forceFill(['marketing_consent_at' => null, 'marketing_consent_email' => null, 'marketing_consent_source' => null]);
            }
            if ($contact->exists && $contact->isDirty('phone_digits')) {
                $contact->forceFill(['sms_consent' => false, 'sms_consent_phone' => null, 'sms_consent_at' => null, 'sms_consent_source' => null]);
            }
            // Read the column directly: "company" is also the name of the relation.
            $name = $contact->getAttributes()['company'] ?? null;
            if ($contact->isDirty('company') || ($name && ! $contact->company_id)) {
                $contact->company_id = app(\App\Services\Crm\CrmCompanyService::class)->resolveId($name, $contact->segment);
            }
        });
    }

    /** Look across every contact, regardless of who is signed in. System use only. */
    public static function unscopedForSystem(): Builder
    {
        return static::query()->withoutGlobalScope(OwnedByRepScope::class);
    }

    public function assignedRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_rep_id');
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'company_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CrmNote::class, 'contact_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'contact_id');
    }

    public function stageChanges(): HasMany
    {
        return $this->hasMany(CrmStageChange::class, 'contact_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CrmEvent::class, 'contact_id');
    }

    public function isLost(): bool
    {
        return $this->stage === self::STAGE_LOST;
    }

    public function isInPipeline(): bool
    {
        return in_array($this->stage, self::PIPELINE_STAGES, true);
    }

    public function stageLabel(): string
    {
        return self::STAGE_LABELS[$this->stage] ?? $this->stage;
    }

    public function scopeNotLost(Builder $query): Builder
    {
        return $query->where('stage', '!=', self::STAGE_LOST);
    }
}
