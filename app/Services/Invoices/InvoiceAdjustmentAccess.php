<?php

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\User;
use App\Services\RolePermissionService;
use App\Services\Shoots\ShootSalesRepResolver;

/** Scope client-invoice corrections independently of staff payout administration. */
class InvoiceAdjustmentAccess
{
    public function __construct(
        private readonly RolePermissionService $permissions,
        private readonly InvoiceAdjustmentService $adjustments,
        private readonly ShootSalesRepResolver $salesReps,
    ) {}

    public function authorize(Invoice $invoice, ?User $user, bool $changesPayable): void
    {
        abort_unless($user && $this->permissions->userCan($user, 'invoices', 'update'), 403);
        $roles = $this->permissions->normalizedUserRoles($user);

        // Retain staff invoice/payout corrections, including secondary staff roles.
        if ($roles->intersect(['admin', 'superadmin', 'editing_manager'])->isNotEmpty()) {
            return;
        }

        abort_unless($roles->contains('salesRep') && $invoice->role === Invoice::ROLE_CLIENT, 403);
        abort_if($changesPayable && ! $this->permissions->userCan($user, 'shoot-pricing', 'update'), 403);

        $shoots = $this->adjustments->relatedShoots($invoice);
        abort_if($shoots->isEmpty(), 403);
        foreach ($shoots as $shoot) {
            abort_if($shoot->isImportDraft()
                || data_get($shoot->external_booking_payload, 'legacy_migration.historical_payments_only')
                || collect([$shoot->status, $shoot->workflow_status])->contains(fn ($status) => in_array(strtolower((string) $status), ['import_draft', 'cancelled', 'canceled', 'declined'], true)), 403);
            // Explicit assignments take precedence; only unassigned shoots inherit
            // the account's canonical rep. Mixed-rep invoices remain staff-only.
            $repId = $shoot->rep_id ?? $this->salesReps->resolve($shoot)?->id;
            abort_unless((string) $repId === (string) $user->id, 403);
        }
    }
}
