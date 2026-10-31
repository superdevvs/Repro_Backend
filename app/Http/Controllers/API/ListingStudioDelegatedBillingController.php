<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PublicApiException;
use App\Http\Controllers\Controller;
use App\Models\ListingStudioBillingRequest;
use App\Models\ListingStudioOrder;
use App\Models\User;
use App\Services\ListingStudio\ListingStudioAccountService;
use App\Services\ListingStudio\ListingStudioBillingEmailService;
use App\Services\ListingStudio\ListingStudioBillingRequestService;
use App\Services\ListingStudio\ListingStudioCatalogService;
use App\Services\ListingStudio\ListingStudioDelegatedAccess;
use App\Services\ListingStudio\ListingStudioOrderService;
use App\Services\ListingStudio\ListingStudioPaidMedia;
use App\Services\ListingStudio\ListingStudioPaidStorage;
use App\Services\ListingStudio\ListingStudioQuoteService;
use App\Services\ListingStudio\StudioPaymentException;
use App\Services\ListingStudio\StudioSubscriptionActionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListingStudioDelegatedBillingController extends Controller
{
    public function clients(Request $request)
    {
        $data = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $query = User::where('role', 'client')->where(fn ($q) => $q->where('account_status', User::ACCOUNT_STATUS_ACTIVE)->orWhereNull('account_status'))->whereNull('locked_at');
        if (filled($data['q'] ?? null)) {
            $search = mb_strtolower(trim($data['q']));
            $query->where(fn ($q) => $q->whereRaw('instr(lower(name), ?) > 0', [$search])->orWhereRaw('instr(lower(email), ?) > 0', [$search]));
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name', 'email'], 'page', $data['page'] ?? 1);

        return $this->json(['clients' => $page->getCollection()->map(fn ($u) => ['id' => (string) $u->id, 'name' => $u->name, 'email' => $u->email])->all(), 'pagination' => $this->pagination($page)]);
    }

    public function catalog(Request $request, string $clientId, ListingStudioCatalogService $catalog)
    {
        $this->client($request, $clientId);

        return $this->json($catalog->present());
    }

    public function account(Request $request, string $clientId, ListingStudioAccountService $accounts, ListingStudioBillingRequestService $requests)
    {
        $client = $this->client($request, $clientId);
        $account = $accounts->account($client);
        $actor = $request->user();
        $canManage = $actor->role === 'superadmin' && (bool) config('listing-studio.subscription_controls_enabled', false);
        $summary = class_exists(StudioSubscriptionActionService::class) ? app(StudioSubscriptionActionService::class)->summary($client)
            : ['pendingChange' => null, 'capabilities' => ['canScheduleDowngrade' => false, 'canCancelRenewal' => false, 'canUndoCancel' => false, 'canUndoDowngrade' => false]];
        foreach ($summary['capabilities'] as $key => $value) {
            $summary['capabilities'][$key] = $canManage && $value;
        }
        $account['management'] = ['canPurchase' => config('listing-studio.delegated_enabled', false) && $account['capabilities']['canCreateOrder'],
            'canManageSubscription' => $canManage, 'subscription' => $summary];
        $account['client'] = ['id' => (string) $client->id, 'name' => $client->name, 'email' => $client->email];
        $account['capabilities']['canPayByCard'] = false;
        $account['capabilities']['canManageBilling'] = false;
        $account['pendingBilling'] = $requests->pending($client, $actor);

        return $this->json($account);
    }

    public function upload(Request $request, string $clientId, ListingStudioPaidMedia $media)
    {
        $client = $this->client($request, $clientId);
        $request->merge(['requestKey' => $request->header('Idempotency-Key')]);
        $data = $request->validate(['photo' => ['required', 'file', 'max:10240'], 'requestKey' => ['required', 'uuid']]);
        $upload = $media->upload($client, $request->file('photo'), $data['requestKey'], $request->user());

        return $this->json(['upload' => $media->presentUpload($upload)]);
    }

    public function uploadPreview(Request $request, string $clientId, string $uploadId, ListingStudioPaidMedia $media)
    {
        $client = $this->client($request, $clientId);
        ListingStudioDelegatedAccess::assertSource($request->user(), $client, 'upload', $uploadId);
        $upload = $media->ownedUpload($client, $uploadId);
        abort_unless($upload->preview_path && str_starts_with($upload->preview_path, 'uploads/'.$upload->id.'/'), 404);

        return $this->image($media->read(['disk' => ListingStudioPaidStorage::DISK, 'path' => $upload->preview_path]));
    }

    public function quote(Request $request, string $clientId, ListingStudioQuoteService $quotes)
    {
        $client = $this->client($request, $clientId);
        $quote = $quotes->create($client, $request->all(), $request->user());

        return $this->json(['quote' => $quotes->present($quote, $client)], 201);
    }

    public function storeOrder(Request $request, string $clientId, ListingStudioOrderService $orders)
    {
        $client = $this->client($request, $clientId);
        $data = $request->validate(['quoteId' => ['required', 'uuid'], 'fundingMethod' => ['required', Rule::in(['credits', 'card'])],
            'creditConsent' => ['required_if:fundingMethod,credits', 'accepted_if:fundingMethod,credits']]);
        $result = $orders->acceptQuote($client, $data['quoteId'], $data['fundingMethod'], (string) $request->header('Idempotency-Key'), $request->user());

        return $this->json(['order' => $this->staffOrder($result['order'], $orders), 'created' => $result['created'],
            'nextAction' => ['type' => $data['fundingMethod'] === 'card' ? 'create_payment_request' : 'await_processing']], $result['created'] ? 201 : 200);
    }

    public function orders(Request $request, string $clientId, ListingStudioOrderService $orders)
    {
        $client = $this->client($request, $clientId);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = ListingStudioOrder::where('client_id', $client->id)->where('actor_id', $request->user()->id)
            ->latest()->paginate($data['perPage'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return $this->json(['orders' => $page->getCollection()->map(fn ($order) => $this->staffOrder($order, $orders))->all(), 'pagination' => $this->pagination($page)]);
    }

    public function order(Request $request, string $clientId, string $orderId, ListingStudioOrderService $orders)
    {
        $order = ListingStudioDelegatedAccess::order($request->user(), $this->client($request, $clientId), $orderId);

        return $this->json(['order' => $this->staffOrder($order, $orders)]);
    }

    public function orderPreview(Request $request, string $clientId, string $orderId, ListingStudioPaidMedia $media)
    {
        $data = $request->validate(['variant' => ['sometimes', 'in:original,result']]);
        $order = ListingStudioDelegatedAccess::order($request->user(), $this->client($request, $clientId), $orderId);
        $item = $order->item;
        $descriptor = ($data['variant'] ?? 'result') === 'original' ? $item?->source_snapshot : ($item?->status === 'completed' ? $item->output_descriptor : null);
        abort_unless($descriptor, 404);

        return $this->image($media->previewBytes($media->read($descriptor)));
    }

    public function createRequest(Request $request, string $clientId, ListingStudioBillingRequestService $requests)
    {
        $client = $this->client($request, $clientId);
        $result = $requests->create($request->user(), $client, $request->all(), (string) $request->header('Idempotency-Key'));

        return $this->json(['request' => $requests->present($requests->refresh($result['request'])), 'created' => $result['created']], $result['created'] ? 201 : 200);
    }

    public function requests(Request $request, string $clientId, ListingStudioBillingRequestService $requests)
    {
        $client = $this->client($request, $clientId);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = ListingStudioBillingRequest::where('client_id', $client->id)->where('actor_id', $request->user()->id)->latest()
            ->paginate($data['perPage'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return $this->json(['requests' => $page->getCollection()->map(fn ($row) => $requests->present($requests->refresh($row)))->all(), 'pagination' => $this->pagination($page)]);
    }

    public function showRequest(Request $request, string $clientId, string $requestId, ListingStudioBillingRequestService $requests)
    {
        $row = $requests->managed($request->user(), $this->client($request, $clientId), $requestId);

        return $this->json(['request' => $requests->present($requests->refresh($row))]);
    }

    public function email(Request $request, string $clientId, string $requestId, ListingStudioBillingRequestService $requests, ListingStudioBillingEmailService $emails)
    {
        $client = $this->client($request, $clientId);
        $row = $requests->managed($request->user(), $client, $requestId);
        $delivery = $emails->request($request->user(), $client, $row, (string) $request->header('Idempotency-Key'));

        return $this->json(['request' => $requests->present($row->fresh()), 'delivery' => $delivery], 202);
    }

    public function cancelRequest(Request $request, string $clientId, string $requestId, ListingStudioBillingRequestService $requests)
    {
        $row = $requests->managed($request->user(), $this->client($request, $clientId), $requestId);

        return $this->json(['request' => $requests->present($requests->cancel($row, $request->user()))]);
    }

    public function subscriptionAction(Request $request, string $clientId)
    {
        $client = $this->client($request, $clientId);
        abort_unless($request->user()->role === 'superadmin', 403);
        $data = $request->validate(['action' => ['required', Rule::in(['schedule_downgrade', 'cancel_renewal', 'undo_cancel', 'undo_downgrade'])],
            'planCode' => ['sometimes', 'nullable', Rule::in(['starter', 'pro', 'studio'])]]);

        return $this->json($this->payment(fn () => app(StudioSubscriptionActionService::class)->execute($request->user(), $client, $data['action'], $data['planCode'] ?? '', (string) $request->header('Idempotency-Key'))));
    }

    private function client(Request $request, string $id): User
    {
        $client = User::findOrFail($id);
        ListingStudioDelegatedAccess::assert($request->user(), $client);

        return $client;
    }

    private function staffOrder(ListingStudioOrder $order, ListingStudioOrderService $orders): array
    {
        $data = $orders->present($order);
        // Staff never receives an action that opens the client's Checkout or billing portal.
        $data['actions']['canCheckout'] = false;
        $data['actions']['canCancel'] = false;
        $data['actions']['canDownload'] = false;
        $data['actions']['canRequestPayment'] = $order->funding_method === 'card' && $order->funding_status === 'pending'
            && $order->paid_amount_cents === 0 && $order->item?->status === 'awaiting_funding' && config('listing-studio.delegated_enabled', false);

        return $data;
    }

    private function image(string $bytes)
    {
        return response($bytes)->withHeaders(['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function pagination($page): array
    {
        return ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'hasMore' => $page->hasMorePages()];
    }

    private function payment(callable $callback): array
    {
        try {
            return $callback();
        } catch (StudioPaymentException $error) {
            throw new PublicApiException($error->getMessage(), $error->errorCode, $error->httpStatus, $error->data);
        }
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status)->header('Cache-Control', 'no-store');
    }
}
