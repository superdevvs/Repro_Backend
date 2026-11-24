<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

class CrmQuoteItem extends Model
{
    protected $table = 'crm_quote_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'float', 'total' => 'float'];
    }
}
