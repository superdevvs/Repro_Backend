<?php

namespace App\Services\Invoices;

use App\Models\AccountLink;
use App\Models\Shoot;
use App\Models\User;
use App\Services\Shoots\ShootAuthorizationSupport;

class InvoiceAuthorizationService
{
    public function __construct(
        private readonly ShootAuthorizationSupport $shootAuthorization
    ) {}

    public function canViewShootInvoice(Shoot $shoot, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->shootAuthorization->hasRole($user, ['admin', 'superadmin', 'editing_manager'])) {
            return true;
        }

        if ($this->shootAuthorization->hasRole($user, ['salesRep', 'rep', 'representative'])) {
            return (string) $shoot->rep_id === (string) $user->id;
        }

        if (! $this->shootAuthorization->isClientUser($user)) {
            return false;
        }

        if ((string) $shoot->client_id === (string) $user->id) {
            return true;
        }

        if (! $shoot->client_id) {
            return false;
        }

        return AccountLink::query()
            ->where('main_account_id', $user->id)
            ->where('linked_account_id', $shoot->client_id)
            ->where('status', 'active')
            ->get()
            ->contains(fn (AccountLink $link) => $link->sharesDetail('invoices'));
    }
}
