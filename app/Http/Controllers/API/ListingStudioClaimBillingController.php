<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ListingStudioCheckoutAttempt;
use App\Models\ListingStudioSubscriptionReceipt;
use App\Services\ListingStudio\ListingStudioAccountService;
use App\Services\ListingStudio\ListingStudioCatalogService;
use App\Services\ListingStudio\StudioCheckoutService;
use App\Services\ListingStudio\StudioPaymentException;
use Illuminate\Http\Request;

/** Authenticated client billing on the verification-exempt photo handoff. */
class ListingStudioClaimBillingController extends Controller
{
    public function show(Request $request, ListingStudioAccountService $accounts, ListingStudioCatalogService $catalog)
    {
        $client = $request->user();
        $account = $accounts->account($client);
        $pending = ListingStudioCheckoutAttempt::where('client_id', $client->id)->where('kind', 'subscription')
            ->where('account_id', config('listing-studio.stripe_account_id'))->where('livemode', config('listing-studio.stripe_mode') === 'live')
            ->whereNotIn('status', ['expired', 'failed'])
            ->where(fn ($query) => $query->where('status', '!=', 'paid')->orWhere('updated_at', '>=', now()->subDay()))
            ->when($account['subscription'] !== null, fn ($query) => $query->where('status', '!=', 'paid'))
            ->orderByDesc('created_at')->first();
        $receipt = $account['subscription'] ? ListingStudioSubscriptionReceipt::where('client_id', $client->id)
            ->where('subscription_id', $account['subscription']['id'])->where('snapshot->planCode', $account['subscription']['planCode'])
            ->where('snapshot->periodEnd', $account['subscription']['periodEnd'])->orderByDesc('created_at')->first() : null;

        return response()->json(['success' => true, 'data' => ['account' => $account, 'catalog' => $catalog->present(),
            'pendingCheckout' => $pending ? ['planCode' => $pending->plan_code, 'status' => $pending->status,
                'requestKey' => $pending->idempotency_key, 'expiresAt' => $pending->expires_at?->toIso8601String()] : null,
            'confirmationEmail' => $receipt ? ['status' => $receipt->status, 'sentAt' => $receipt->sent_at?->toIso8601String()] : null,
        ]])->header('Cache-Control', 'no-store');
    }

    public function subscription(Request $request, StudioCheckoutService $checkout)
    {
        $data = $request->validate(['planCode' => ['required', 'in:starter,pro,studio']]);

        return $this->respond(fn () => $checkout->subscription($request->user(), $data['planCode'], (string) $request->header('Idempotency-Key'), true));
    }

    public function portal(Request $request, StudioCheckoutService $checkout)
    {
        return $this->respond(fn () => $checkout->portal($request->user(), true));
    }

    private function respond(callable $action)
    {
        try {
            return response()->json(['success' => true, 'data' => $action()])->header('Cache-Control', 'no-store');
        } catch (StudioPaymentException $error) {
            return response()->json(['success' => false, 'code' => $error->errorCode, 'message' => $error->getMessage(), 'data' => $error->data], $error->httpStatus)->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            return response()->json(['success' => false, 'code' => 'awaiting_reconciliation', 'message' => 'Billing could not be confirmed. Keep this checkout and try again later.'], 503)->header('Cache-Control', 'no-store');
        }
    }
}
