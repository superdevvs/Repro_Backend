<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ListingStudioSubscription;
use App\Services\ListingStudio\ListingStudioCreditService;
use App\Services\ListingStudioAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ListingStudioSubscriptionController extends Controller
{
    public function index(Request $request, ListingStudioAccess $access, ListingStudioCreditService $credits): JsonResponse
    {
        abort_unless(in_array($access->role($request->user()), ['admin', 'superadmin', 'salesrep'], true), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'trialing', 'paused', 'needs_attention'])],
        ]);
        $query = ListingStudioSubscription::with(['client', 'accountSetup']);
        if (! $access->isAdmin($request->user())) {
            $query->whereIn('client_id', $access->clients($request->user())->select('users.id'));
        }
        if (! empty($data['status'])) {
            $data['status'] === 'needs_attention'
                ? $query->where(fn ($scope) => $scope->where('sync_status', 'needs_attention')
                    ->orWhereHas('accountSetup', fn ($setup) => $setup->where('status', 'needs_attention')))
                : $query->where('status', $data['status']);
        }
        if ($search = trim($data['q'] ?? '')) {
            $query->where(function ($scope) use ($search) {
                foreach (['customer_name', 'customer_email', 'plan_name'] as $field) {
                    $scope->orWhere($field, 'like', '%'.$search.'%');
                }
                $scope->orWhereHas('client', fn ($client) => $client->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
            });
        }
        $page = $query->latest('updated_at')->latest('id')->paginate(25);
        $refunds = $page->isEmpty() ? collect() : DB::table('listing_studio_subscription_refunds as refunds')
            ->whereIn('refunds.stripe_subscription_id', $page->getCollection()->pluck('stripe_subscription_id'))
            ->whereNotExists(function ($newer) {
                $newer->selectRaw('1')->from('listing_studio_subscription_refunds as newer')
                    ->whereColumn('newer.stripe_account_id', 'refunds.stripe_account_id')
                    ->whereColumn('newer.livemode', 'refunds.livemode')
                    ->whereColumn('newer.stripe_subscription_id', 'refunds.stripe_subscription_id')
                    ->where(fn ($sort) => $sort->whereColumn('newer.refund_created_at', '>', 'refunds.refund_created_at')
                        ->orWhere(fn ($tie) => $tie->whereColumn('newer.refund_created_at', 'refunds.refund_created_at')->whereColumn('newer.id', '>', 'refunds.id')));
            })->get()->mapWithKeys(fn ($refund) => [
                $refund->stripe_account_id.':'.(int) $refund->livemode.':'.$refund->stripe_subscription_id => [
                    'status' => $refund->status, 'amount_cents' => (int) $refund->amount_cents,
                    'currency' => $refund->currency, 'created_at' => CarbonImmutable::parse($refund->refund_created_at, 'UTC')->toIso8601String(),
                ],
            ]);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ListingStudioSubscription $row) => [
                'id' => $row->id, 'client_id' => $row->client_id,
                'client' => $row->client ? $row->client->only(['id', 'name', 'email']) : null,
                'customer' => ['name' => $row->customer_name, 'email' => $row->customer_email],
                'latest_refund' => $refunds->get($row->stripe_account_id.':'.(int) $row->livemode.':'.$row->stripe_subscription_id),
                'credits' => $credits->summary($row),
                'account_setup_status' => $row->accountSetup ? (in_array($row->accountSetup->status, ['sent', 'needs_attention'], true) ? $row->accountSetup->status : 'pending') : null,
                'account_setup_attention' => $row->accountSetup?->status === 'needs_attention' ? $row->accountSetup->last_error : null,
                ...$row->only(['plan_code', 'plan_name', 'status', 'sync_status', 'attention_reason', 'amount_cents', 'currency',
                    'billing_interval', 'billing_interval_count', 'cancel_at_period_end', 'latest_invoice_status', 'account_created']),
                'current_period_start' => $row->current_period_start?->toIso8601String(),
                'current_period_end' => $row->current_period_end?->toIso8601String(),
                'canceled_at' => $row->canceled_at?->toIso8601String(), 'last_paid_at' => $row->last_paid_at?->toIso8601String(),
                'created_at' => $row->created_at?->toIso8601String(), 'updated_at' => $row->updated_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
