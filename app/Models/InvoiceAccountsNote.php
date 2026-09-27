<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Deliberately separate from Invoice: payee serializers and exports never load this data.
class InvoiceAccountsNote extends Model
{
    protected $fillable = ['invoice_id', 'note', 'author_id'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
