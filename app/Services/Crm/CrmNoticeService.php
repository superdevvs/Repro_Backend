<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmNotice;
use App\Models\User;
use App\Services\RolePermissionService;

/** Notices for one user, shown on the CRM Home page and counted in the Robbie strip. */
class CrmNoticeService
{
    public function __construct(private readonly RolePermissionService $permissions) {}

    public function notify(int $userId, string $type, string $title, ?string $body = null, ?int $contactId = null, array $data = []): CrmNotice
    {
        return CrmNotice::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($title, 0, 190),
            'body' => $body !== null ? mb_substr($body, 0, 500) : null,
            'contact_id' => $contactId,
            'data' => $data ?: null,
        ]);
    }

    /** Notify every active user who can see all CRM contacts (admins and the super admin). */
    public function notifyCrmAdmins(string $type, string $title, ?string $body = null, ?int $contactId = null, array $data = []): void
    {
        User::query()
            ->whereIn('role', ['admin', 'superadmin'])
            ->get()
            ->filter(fn (User $user) => ($user->account_status ?? 'active') === 'active' || $user->account_status === null)
            ->filter(fn (User $user) => $this->permissions->userCan($user, 'crm-contacts', 'view-all'))
            ->each(fn (User $user) => $this->notify($user->id, $type, $title, $body, $contactId, $data));
    }

    /** Notify everyone who can see recruiting: the super admin and anyone they granted. */
    public function notifyRecruiters(string $type, string $title, ?string $body = null, array $data = []): void
    {
        User::query()
            ->whereIn('role', ['admin', 'superadmin'])
            ->get()
            ->filter(fn (User $user) => ($user->account_status ?? 'active') === 'active' || $user->account_status === null)
            ->filter(fn (User $user) => $this->permissions->userCan($user, 'crm-recruiting', 'view'))
            ->each(fn (User $user) => $this->notify($user->id, $type, $title, $body, null, $data));
    }
}
