<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel',
        'contact_id',
        'assigned_to_user_id',
        'last_message_at',
        'last_direction',
        'last_snippet',
        'status',
        'tags_json',
        'unread_for_user_ids_json',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'tags_json' => 'array',
        'unread_for_user_ids_json' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    /**
     * Mark thread as unread for all staff users
     */
    public function markUnreadForStaff(): void
    {
        $staffRoles = ['admin', 'superadmin', 'salesRep'];
        
        $userIds = User::query()
            ->whereIn('role', $staffRoles)
            ->pluck('id')
            ->all();

        $this->update([
            'unread_for_user_ids_json' => array_values(array_unique($userIds)),
        ]);
    }

    /**
     * Mark thread as read for a specific user
     */
    public function markReadForUser(int $userId): void
    {
        $current = $this->unread_for_user_ids_json ?? [];
        $updated = array_values(array_filter($current, fn($id) => (int) $id !== $userId));
        
        $this->update([
            'unread_for_user_ids_json' => $updated,
        ]);
    }

    /**
     * Check if thread is unread for a specific user
     */
    public function isUnreadForUser(int $userId): bool
    {
        return in_array($userId, $this->unread_for_user_ids_json ?? []);
    }
}

