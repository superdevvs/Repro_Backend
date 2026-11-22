<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note for the editor that applies to every shoot of a client or brokerage. */
class StandingEditorNote extends Model
{
    public const SCOPE_CLIENT = 'client';
    public const SCOPE_BROKERAGE = 'brokerage';

    protected $fillable = [
        'scope',
        'client_id',
        'company_match',
        'content',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isBrokerage(): bool
    {
        return $this->scope === self::SCOPE_BROKERAGE;
    }

    /** Case-insensitive "company name contains" match used for brokerage notes. */
    public function matchesCompany(?string $companyName): bool
    {
        $needle = mb_strtolower(trim((string) $this->company_match));
        $haystack = mb_strtolower(trim((string) $companyName));

        return $needle !== '' && $haystack !== '' && str_contains($haystack, $needle);
    }
}
