<?php

namespace App\Services\Crm;

use App\Models\Crm\CrmContact;
use App\Models\User;
use App\Services\RolePermissionService;
use Illuminate\Support\Collection;

/**
 * Suggests who should own an unassigned lead, with the reason. Rule-based:
 * prefer reps who already work the lead's market (and segment), then the one
 * with the fewest open leads. The admin still makes the assignment.
 */
class CrmRepSuggester
{
    private ?Collection $reps = null;

    public function __construct(private readonly RolePermissionService $permissions) {}

    /** Users who can own CRM contacts: sales reps and admins with CRM access. */
    public function reps(): Collection
    {
        return $this->reps ??= User::query()
            ->whereIn('role', ['salesRep', 'sales_rep', 'rep', 'admin', 'superadmin'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $u) => $this->permissions->userCan($u, 'crm-contacts', 'view'))
            ->values();
    }

    public function suggest(CrmContact $contact): ?array
    {
        $reps = $this->reps()->filter(fn (User $u) => in_array(strtolower(str_replace(['_', '-'], '', (string) $u->role)), ['salesrep', 'rep'], true))->values();
        if ($reps->isEmpty()) {
            $reps = $this->reps();
        }
        if ($reps->isEmpty()) {
            return null;
        }

        $ids = $reps->pluck('id')->all();
        $open = CrmContact::unscopedForSystem()->whereIn('assigned_rep_id', $ids)->whereIn('stage', CrmContact::PIPELINE_STAGES)
            ->selectRaw('assigned_rep_id, COUNT(*) as n')->groupBy('assigned_rep_id')->pluck('n', 'assigned_rep_id');

        $marketCounts = collect();
        if ($contact->market_state) {
            $marketCounts = CrmContact::unscopedForSystem()->whereIn('assigned_rep_id', $ids)
                ->where('market_state', $contact->market_state)
                ->when($contact->market_city, fn ($q) => $q->where('market_city', $contact->market_city))
                ->selectRaw('assigned_rep_id, COUNT(*) as n')->groupBy('assigned_rep_id')->pluck('n', 'assigned_rep_id');
        }

        $segmentCounts = CrmContact::unscopedForSystem()->whereIn('assigned_rep_id', $ids)->where('segment', $contact->segment)
            ->selectRaw('assigned_rep_id, COUNT(*) as n')->groupBy('assigned_rep_id')->pluck('n', 'assigned_rep_id');

        $best = $reps->sortBy(fn (User $u) => [
            ((int) ($marketCounts[$u->id] ?? 0)) > 0 ? 0 : 1,
            -1 * min(1, (int) ($segmentCounts[$u->id] ?? 0)),
            (int) ($open[$u->id] ?? 0),
            $u->id,
        ])->first();

        $reasons = [];
        if (($marketCounts[$best->id] ?? 0) > 0) {
            $reasons[] = 'covers '.($contact->market_city ?: $contact->market_state);
        }
        if ($contact->segment === 'commercial' && ($segmentCounts[$best->id] ?? 0) > 0) {
            $reasons[] = 'works commercial';
        }
        $reasons[] = ((int) ($open[$best->id] ?? 0)).' open leads';

        return ['rep' => CrmPresenter::person($best), 'reason' => implode(', ', $reasons)];
    }
}
