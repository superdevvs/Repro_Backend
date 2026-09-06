<?php

namespace App\Policies;

use App\Models\User;

class TaxDocumentPolicy
{
    public function view(?User $viewer, User $owner): bool
    {
        return $viewer && $viewer->isAccountEligibleForAuthentication()
            && !request()->attributes->get('is_impersonating', false)
            && ($viewer->getKey() === $owner->getKey() || in_array($viewer->role, ['admin', 'superadmin'], true));
    }

    public function upload(?User $viewer, User $owner): bool
    {
        return $viewer && $viewer->getKey() === $owner->getKey() && $this->view($viewer, $owner);
    }
}
