<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A timestamped note. Only its author may edit it; it is never deleted and edits keep earlier versions. */
class CrmNote extends Model
{
    protected $table = 'crm_notes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'via_robbie' => 'boolean'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CrmNoteVersion::class, 'note_id')->orderByDesc('id');
    }
}
