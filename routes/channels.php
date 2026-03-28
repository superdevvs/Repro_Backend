<?php

use Illuminate\Support\Facades\Broadcast;

$normalizeNotificationRole = static function ($role): string {
    $normalized = strtolower((string) $role);
    $normalized = str_replace('-', '_', $normalized);

    return match ($normalized) {
        'sales_rep', 'salesrep', 'rep', 'representative' => 'salesrep',
        default => $normalized,
    };
};

Broadcast::channel('sms.thread-list', function ($user) {
    return !is_null($user);
});

Broadcast::channel('sms.thread.{threadId}', function ($user, $threadId) {
    return !is_null($user);
});

// Admin notifications channel - admins, superadmins, editing managers, and sales reps can subscribe
Broadcast::channel('admin.notifications', function ($user) use ($normalizeNotificationRole) {
    return $user && in_array($normalizeNotificationRole($user->role ?? null), ['admin', 'superadmin', 'editing_manager', 'salesrep'], true);
});

// Individual shoot channel - authenticated users with access to the shoot
Broadcast::channel('shoot.{shootId}', function ($user, $shootId) use ($normalizeNotificationRole) {
    if (!$user) {
        return false;
    }
    // Admins can listen to all shoots
    if (in_array($normalizeNotificationRole($user->role ?? null), ['admin', 'superadmin', 'editing_manager', 'salesrep'], true)) {
        return true;
    }
    // Check if user is related to this shoot (client, photographer, etc.)
    $shoot = \App\Models\Shoot::find($shootId);
    if (!$shoot) {
        return false;
    }
    return $shoot->client_id === $user->id ||
           $shoot->photographer_id === $user->id ||
           $shoot->editor_id === $user->id ||
           $shoot->created_by === $user->id;
});

// Client notifications channel - only the client themselves can subscribe
Broadcast::channel('client.{userId}.notifications', function ($user, $userId) {
    return $user && $user->role === 'client' && $user->id === (int) $userId;
});

// Photographer notifications channel - only the photographer themselves can subscribe
Broadcast::channel('photographer.{userId}.notifications', function ($user, $userId) {
    return $user && $user->role === 'photographer' && $user->id === (int) $userId;
});

// Editor notifications channel - only the editor themselves can subscribe
Broadcast::channel('editor.{userId}.notifications', function ($user, $userId) {
    return $user && $user->role === 'editor' && $user->id === (int) $userId;
});

// Email inbox channel - admins receive all inbound emails
Broadcast::channel('email.inbox', function ($user) use ($normalizeNotificationRole) {
    return $user && in_array($normalizeNotificationRole($user->role ?? null), ['admin', 'superadmin', 'editing_manager', 'salesrep'], true);
});

// Email user channel - users receive their own email notifications by user ID
Broadcast::channel('email.user.{userId}', function ($user, $userId) {
    return $user && $user->id === (int) $userId;
});

Broadcast::channel('system-overview.superadmin', function ($user) {
    return $user && $user->role === 'superadmin';
});
