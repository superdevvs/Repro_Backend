<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a contact's history: stage moves, notes, emails, calls, assignments. */
class CrmActivity extends Model
{
    protected $table = 'crm_activities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'datetime', 'via_robbie' => 'boolean'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
