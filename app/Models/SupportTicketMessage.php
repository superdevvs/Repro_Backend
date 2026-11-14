<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicketMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['internal' => 'boolean', 'attachments_json' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function messageMedia(): HasMany
    {
        return $this->hasMany(MessageMedia::class, 'message_id')->where('message_kind', 'support')->orderBy('id');
    }
}
