<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Shoot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CubiCasaShootTrackerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'missing');
        if (!in_array($status, ['missing', 'linked', 'all'], true)) {
            $status = 'missing';
        }

        $search = trim((string) $request->query('search', ''));
        $base = $this->eligibleShoots($request, $search);

        $missing = (clone $base)->tap(fn (Builder $query) => $this->whereMissing($query));
        $linked = (clone $base)->tap(fn (Builder $query) => $this->whereLinked($query));
        $counts = [
            'missing' => (clone $missing)->count(),
            'linked' => (clone $linked)->count(),
        ];

        $listed = match ($status) {
            'linked' => $linked,
            'all' => $base,
            default => $missing,
        };

        $page = (clone $listed)
            ->with(['client:id,name', 'photographer:id,name', 'service:id,name', 'services.category'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Shoot $shoot) => $this->present($shoot))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => $counts,
        ]);
    }

    private function eligibleShoots(Request $request, string $search): Builder
    {
        $blocked = [Shoot::STATUS_CANCELLED, Shoot::STATUS_DECLINED, Shoot::STATUS_REQUESTED];

        $query = Shoot::query()
            ->cubicasaEligible()
            ->whereNotNull('scheduled_at')
            ->where(function (Builder $status) use ($blocked) {
                $status->whereNull('status')->orWhereNotIn('status', $blocked);
            })
            ->where(function (Builder $workflow) use ($blocked) {
                $workflow->whereNull('workflow_status')->orWhereNotIn('workflow_status', $blocked);
            })
            ->where(function (Builder $type) {
                $type->whereNull('shoot_type')->orWhere('shoot_type', '!=', Shoot::SHOOT_TYPE_INTERNAL_TEST);
            });

        if ($request->user()?->role === 'photographer') {
            $query->where('photographer_id', $request->user()->id);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $address) use ($like) {
                $address->where('address', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('state', 'like', $like)
                    ->orWhere('zip', 'like', $like);
            });
        }

        return $query;
    }

    private function whereMissing(Builder $query): void
    {
        $query->where(function (Builder $id) {
            $id->whereNull('cubicasa_order_id')->orWhere('cubicasa_order_id', '');
        })->where(function (Builder $id) {
            $id->whereNull('cubicasa_external_id')->orWhere('cubicasa_external_id', '');
        });
    }

    private function whereLinked(Builder $query): void
    {
        $query->where(function (Builder $ids) {
            $ids->where(function (Builder $id) {
                $id->whereNotNull('cubicasa_order_id')->where('cubicasa_order_id', '!=', '');
            })->orWhere(function (Builder $id) {
                $id->whereNotNull('cubicasa_external_id')->where('cubicasa_external_id', '!=', '');
            });
        });
    }

    private function present(Shoot $shoot): array
    {
        $parts = array_filter([
            $shoot->address,
            $shoot->city,
            trim(((string) $shoot->state).' '.((string) $shoot->zip)),
        ], fn ($part) => is_string($part) && trim($part) !== '');

        $orderId = trim((string) $shoot->cubicasa_order_id);
        $externalId = trim((string) $shoot->cubicasa_external_id);

        return [
            'id' => $shoot->id,
            'address' => implode(', ', $parts),
            'scheduled_at' => optional($shoot->scheduled_at)->toIso8601String(),
            'client_name' => $shoot->client?->name,
            'photographer_name' => $shoot->photographer?->name,
            'services' => $this->matchedServiceNames($shoot),
            'cubicasa_order_id' => $orderId !== '' ? $orderId : null,
            'cubicasa_external_id' => $externalId !== '' ? $externalId : null,
            'cubicasa_status' => $shoot->cubicasa_status,
            'cubicasa_sync_status' => $shoot->cubicasa_sync_status,
            'cubicasa_last_sync_error' => $shoot->cubicasa_last_sync_error,
            'cubicasa_tour_url' => $shoot->cubicasa_tour_url,
            'linked' => $orderId !== '' || $externalId !== '',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function matchedServiceNames(Shoot $shoot): array
    {
        $names = [];
        if (Shoot::textMatchesCubicasaService($shoot->service_category)) {
            $names[] = (string) $shoot->service_category;
        }
        if ($shoot->service && Shoot::textMatchesCubicasaService($shoot->service->name)) {
            $names[] = (string) $shoot->service->name;
        }
        foreach ($shoot->services as $service) {
            if (Shoot::textMatchesCubicasaService($service->name)) {
                $names[] = (string) $service->name;
            }
            if (Shoot::textMatchesCubicasaService($service->category?->name)) {
                $names[] = (string) $service->category->name;
            }
        }

        return array_values(array_unique(array_filter($names, fn ($name) => $name !== '')));
    }
}
