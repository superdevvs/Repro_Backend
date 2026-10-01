<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use App\Services\Messaging\ShootPaymentReminderEligibility;
use App\Services\Shoots\ShootSalesRepResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class OverdueClientsController extends Controller
{
    public function __invoke(Request $request, ShootPaymentReminderEligibility $eligibility, ShootSalesRepResolver $salesRepResolver)
    {
        $user = $request->user();
        $normalize = static fn ($role) => strtolower(str_replace(['_', '-'], '', (string) $role));
        $roles = array_map($normalize, [$user?->role, ...(array) $user?->secondary_roles]);
        $isSuperadmin = in_array('superadmin', $roles, true);
        abort_unless($isSuperadmin || array_intersect($roles, ['salesrep', 'rep', 'representative']), 403);
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $cutoff = now()->subDays(30);

        $query = Shoot::query()->excludeHistoricalImportsFromNewBilling()
            ->where('workflow_status', Shoot::STATUS_DELIVERED)
            ->where('delivery_status', 'delivered')
            ->whereRaw('COALESCE(completed_at, shoot_ready_notified_at) < ?', [$cutoff->toDateTimeString()])
            ->where(fn (Builder $payment) => $payment->whereNull('payment_status')
                ->orWhereNotIn('payment_status', ['paid', Shoot::PAYMENT_STATUS_NO_PAYMENT_REQUIRED]))
            ->where('total_quote', '>', 0.01)
            ->with(['client:id,name,email,phonenumber,metadata', 'rep', 'payments.refunds']);

        if (! $isSuperadmin) {
            // Match explicit shoot assignment first; account metadata is the fallback.
            // Match both imported string IDs and numeric JSON IDs, without name guessing.
            $query->where(function (Builder $scope) use ($user) {
                $scope->where('rep_id', $user->id)->orWhere(function (Builder $fallback) use ($user) {
                    $fallback->whereNull('rep_id')->whereHas('client', function (Builder $client) use ($user) {
                        $client->where(function (Builder $metadata) use ($user) {
                            foreach (['accountRepId', 'account_rep_id', 'repId', 'rep_id'] as $key) {
                                $metadata->orWhere("metadata->$key", (int) $user->id)
                                    ->orWhere("metadata->$key", (string) $user->id);
                            }
                        });
                    });
                });
            });
        }

        $clients = collect();
        foreach ($query->lazyById(200) as $shoot) {
            if (! $isSuperadmin && (int) $salesRepResolver->resolve($shoot)?->id !== (int) $user->id) {
                continue;
            }
            $completedAt = $eligibility->completionAnchor($shoot);
            $balance = $eligibility->balanceDue($shoot);
            if (! $shoot->client || ! $completedAt || ! $completedAt->lt($cutoff)
                || $balance <= 0.01 || $shoot->isComplimentaryReshoot()) {
                continue;
            }
            $clientId = (int) $shoot->client_id;
            $client = $clients->get($clientId, [
                'id' => $clientId,
                'name' => $shoot->client->name,
                'email' => $shoot->client->email,
                'phone' => $shoot->client->phonenumber,
                'balanceDue' => 0,
                'oldestDays' => 0,
                'shoots' => [],
            ]);
            $age = (int) floor($completedAt->diffInDays(now()));
            $client['balanceDue'] = round($client['balanceDue'] + $balance, 2);
            $client['oldestDays'] = max($client['oldestDays'], $age);
            $client['shoots'][] = [
                'id' => $shoot->id,
                'address' => implode(', ', array_filter([$shoot->address, $shoot->city, $shoot->state, $shoot->zip])),
                'completedAt' => $completedAt->toIso8601String(),
                'daysSinceCompletion' => $age,
                'balanceDue' => round($balance, 2),
            ];
            $clients->put($clientId, $client);
        }

        $clients = $clients->sortByDesc('oldestDays')->values();
        $perPage = 15;
        $lastPage = max(1, (int) ceil($clients->count() / $perPage));
        $page = min((int) ($filters['page'] ?? 1), $lastPage);

        return response()->json([
            'data' => $clients->forPage($page, $perPage)->values(),
            'meta' => [
                'total' => $clients->count(),
                'page' => $page,
                'lastPage' => $lastPage,
            ],
        ]);
    }
}
