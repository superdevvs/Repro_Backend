<?php

namespace App\Models\Crm;

use App\Models\Crm\Scopes\OwnedThroughContactScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A priced quote for a contact. Large discounts need approval before it can be sent. */
#[ScopedBy(OwnedThroughContactScope::class)]
class CrmQuote extends Model
{
    protected $table = 'crm_quotes';

    protected $guarded = ['id'];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'sending', 'sent', 'accepted', 'declined', 'expired'];

    protected function casts(): array
    {
        return [
            'sqft' => 'integer', 'booking_shoot_id' => 'integer', 'subtotal' => 'float', 'discount_percent' => 'float', 'discount_amount' => 'float', 'total' => 'float',
            'valid_until' => 'date', 'approved_at' => 'datetime', 'sent_at' => 'datetime', 'viewed_at' => 'datetime',
            'accepted_at' => 'datetime', 'declined_at' => 'datetime', 'via_robbie' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CrmQuoteItem::class, 'quote_id')->orderBy('sort')->orderBy('id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }
}
