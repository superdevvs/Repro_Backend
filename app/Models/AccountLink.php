<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class AccountLink extends Model
{
    protected $fillable = [
        'main_account_id',
        'linked_account_id', 
        'shared_details',
        'notes',
        'status',
        'linked_at',
        'unlinked_at',
        'created_by',
    ];

    protected $casts = [
        'shared_details' => 'array',
        'linked_at' => 'datetime',
        'unlinked_at' => 'datetime',
    ];

    /**
     * Get the main account (parent)
     */
    public function mainAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'main_account_id');
    }

    /**
     * Get the linked account (child)
     */
    public function linkedAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_account_id');
    }

    /**
     * Get the user who created this link
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if a specific detail type is shared
     */
    public function sharesDetail(string $detail): bool
    {
        return ($this->shared_details[$detail] ?? false) === true;
    }

    /**
     * Update shared details
     */
    public function updateSharedDetails(array $details): bool
    {
        $this->shared_details = array_merge($this->shared_details ?? [], $details);
        return $this->save();
    }

    /**
     * Get formatted shared details for frontend
     */
    public function getFormattedSharedDetails(): array
    {
        $defaults = [
            'shoots' => false,
            'invoices' => false,
            'clients' => false,
            'availability' => false,
            'settings' => false,
            'profile' => false,
            'documents' => false,
        ];

        return array_merge($defaults, $this->shared_details ?? []);
    }

    /**
     * Scope: Active links only
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: Links for a specific account (either main or linked)
     */
    public function scopeForAccount($query, $accountId)
    {
        return $query->where(function($q) use ($accountId) {
            $q->where('main_account_id', $accountId)
              ->orWhere('linked_account_id', $accountId);
        });
    }

    /**
     * Get all linked account IDs for a given account
     */
    public static function getLinkedAccountIds(int $accountId): array
    {
        return self::forAccount($accountId)
            ->active()
            ->get()
            ->flatMap(function($link) use ($accountId) {
                if ($link->main_account_id === $accountId) {
                    return [$link->linked_account_id];
                } else {
                    return [$link->main_account_id];
                }
            })
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Check if two accounts are linked
     */
    public static function areLinked(int $accountId1, int $accountId2): bool
    {
        return self::where(function($query) use ($accountId1, $accountId2) {
                $query->where('main_account_id', $accountId1)
                      ->where('linked_account_id', $accountId2);
            })
            ->orWhere(function($query) use ($accountId1, $accountId2) {
                $query->where('main_account_id', $accountId2)
                      ->where('linked_account_id', $accountId1);
            })
            ->active()
            ->exists();
    }
}
