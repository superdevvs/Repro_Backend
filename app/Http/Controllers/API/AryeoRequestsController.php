<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AryeoConnection;
use App\Models\AryeoJob;
use App\Models\AryeoRequest;
use App\Models\Shoot;
use App\Services\RolePermissionService;
use App\Services\Shoots\ShootSalesRepFilter;
use Illuminate\Http\Request;

/** Read-only inbox. Delivery continues through the verified, shoot-specific Tours workflow. */
class AryeoRequestsController extends Controller
{
    public function __invoke(Request $request)
    {
        $roles = app(RolePermissionService::class)->normalizedUserRoles($request->user());
        $staff = $roles->intersect(['admin', 'superadmin', 'editing_manager'])->isNotEmpty();
        abort_unless($staff || $roles->contains('salesRep'), 403);
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'history' => 'sometimes|boolean',
        ]);
        $connections = AryeoConnection::where('enabled', true)->get();
        $shootQuery = Shoot::query();
        if (! $staff) {
            app(ShootSalesRepFilter::class)->apply($shootQuery, [$request->user()->id]);
        }
        $orders = AryeoRequest::whereIn('connection_id', $connections->pluck('id'))
            ->when(! $staff, fn ($q) => $q->whereIn('shoot_id', (clone $shootQuery)->select('shoots.id')))
            ->latest()->orderByDesc('id')->get();
        $shoots = (clone $shootQuery)->whereIn('id', $orders->pluck('shoot_id')->filter())
            ->with('client:id,name')->get()->keyBy('id');
        $jobs = AryeoJob::whereIn('request_record_id', $orders->pluck('id'))->latest()->orderByDesc('id')
            ->get(['id', 'request_record_id', 'status', 'created_at', 'updated_at'])->unique('request_record_id')->keyBy('request_record_id');

        $items = $orders->map(function ($order) use ($shoots, $jobs) {
            $shoot = $shoots->get($order->shoot_id);
            $job = $jobs->get($order->id);
            $delivered = $order->hasVerifiedProviderDelivery() || $job?->status === 'completed';

            return [
                'id' => $order->id,
                'address' => $order->discovery['address'] ?? $shoot?->address,
                'requesterName' => $order->discovery['requester_name'] ?? null,
                'requesterEmail' => $order->discovery['requester_email'] ?? null,
                'receivedAt' => $order->created_at,
                'required' => $order->discovery['required'] ?? null,
                'status' => $delivered ? 'delivered' : ($job?->status ?? ($shoot ? 'matched' : 'unmatched')),
                'providerCheckedAt' => $order->inventory['provider_status']['checked_at'] ?? null,
                'shoot' => $shoot ? ['id' => $shoot->id, 'address' => $shoot->address,
                    'clientName' => $shoot->client?->name, 'scheduledDate' => $shoot->scheduled_date?->format('Y-m-d'),
                    'workflowStatus' => $shoot->workflow_status ?: $shoot->status] : null,
            ];
        });
        $pendingCount = $items->where('status', '!=', 'delivered')->count();
        $items = $items->filter(fn ($item) => ($item['status'] === 'delivered') === $request->boolean('history'))->values();
        $lastPage = max(1, (int) ceil($items->count() / 20));
        $page = min((int) ($filters['page'] ?? 1), $lastPage);

        return response()->json([
            'data' => $items->forPage($page, 20)->values(),
            'meta' => ['total' => $items->count(), 'pendingCount' => $pendingCount, 'page' => $page, 'lastPage' => $lastPage],
            'connections' => $staff ? $connections->map(fn ($connection) => [
                'id' => $connection->id, 'name' => $connection->name,
                'online' => (bool) $connection->last_seen_at?->gt(now()->subMinutes(2)),
                'lastSeenAt' => $connection->last_seen_at,
                'processingEnabled' => $connection->processing_enabled,
                'executorReady' => data_get($connection->capabilities, 'processing_mode') === 'dashboard_jobs'
                    && data_get($connection->capabilities, 'shoot_executor') === true,
            ])->values() : [],
        ])->header('Cache-Control', 'private, no-store');
    }
}
