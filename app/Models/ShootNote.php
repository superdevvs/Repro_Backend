<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShootNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoot_id',
        'author_id',
        'type',
        'visibility',
        'content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Type constants
    const TYPE_SHOOT = 'shoot';
    const TYPE_COMPANY = 'company';
    const TYPE_PHOTOGRAPHER = 'photographer';
    const TYPE_EDITING = 'editing';

    // Visibility constants
    const VISIBILITY_INTERNAL = 'internal';
    const VISIBILITY_PHOTOGRAPHER_ONLY = 'photographer_only';
    const VISIBILITY_CLIENT_VISIBLE = 'client_visible';

    public function shoot()
    {
        return $this->belongsTo(Shoot::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Check if note is visible to a given role
     */
    public function isVisibleToRole(string $role): bool
    {
        // Super admin and admin see everything
        if (in_array($role, ['superadmin', 'admin'])) {
            return true;
        }

        // Client only sees client_visible notes
        if ($role === 'client') {
            return $this->visibility === self::VISIBILITY_CLIENT_VISIBLE && $this->type === self::TYPE_SHOOT;
        }

        // Photographer sees photographer notes and client-visible shoot notes
        if ($role === 'photographer') {
            return in_array($this->visibility, [
                self::VISIBILITY_CLIENT_VISIBLE,
                self::VISIBILITY_PHOTOGRAPHER_ONLY,
            ]) || $this->type === self::TYPE_PHOTOGRAPHER;
        }

        // Editor sees editing notes and internal notes
        if ($role === 'editor') {
            return $this->type === self::TYPE_EDITING || $this->visibility === self::VISIBILITY_INTERNAL;
        }

        return false;
    }
}

