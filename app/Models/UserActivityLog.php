<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'actor_user_id',
        'event_type',
        'title',
        'description',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public static function record(
        User $user,
        string $eventType,
        string $title,
        ?string $description = null,
        ?User $actor = null,
        array $metadata = []
    ): self {
        return self::create([
            'user_id' => $user->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
