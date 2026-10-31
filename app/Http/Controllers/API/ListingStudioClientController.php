<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ListingStudioOrder;
use App\Models\ListingStudioOrderItem;
use App\Services\ListingStudio\ListingStudioAccountService;
use App\Services\ListingStudio\ListingStudioCatalogService;
use App\Services\ListingStudio\ListingStudioOrderService;
use App\Services\ListingStudio\ListingStudioQuoteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListingStudioClientController extends Controller
{
    public function catalog(ListingStudioCatalogService $catalog)
    {
        return $this->json($catalog->present());
    }

    public function account(Request $request, ListingStudioAccountService $accounts)
    {
        return $this->json($accounts->account($request->user()));
    }

    public function index(Request $request, ListingStudioAccountService $accounts)
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'kind' => ['sometimes', Rule::in(['ai_edit', 'human_revision'])],
            'service' => ['sometimes', Rule::in(array_keys(config('listing-studio.services', [])))],
            'status' => ['sometimes', Rule::in(ListingStudioOrderItem::STATUSES)]]);

        return $this->json($accounts->orders($request->user(), $data));
    }

    public function transactions(Request $request, ListingStudioAccountService $accounts)
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'perPage' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return $this->json($accounts->transactions($request->user(), $data['page'] ?? 1, $data['perPage'] ?? 20));
    }

    public function quote(Request $request, ListingStudioQuoteService $quotes)
    {
        $quote = $quotes->create($request->user(), $request->all());

        return $this->json(['quote' => $quotes->present($quote, $request->user())], 201);
    }

    public function store(Request $request, ListingStudioOrderService $orders)
    {
        $data = $request->validate(['quoteId' => ['required', 'uuid'], 'fundingMethod' => ['required', Rule::in(['credits', 'card'])]]);
        $result = $orders->acceptQuote($request->user(), $data['quoteId'], $data['fundingMethod'], (string) $request->header('Idempotency-Key'));

        return $this->json(['order' => $orders->present($result['order']), 'created' => $result['created'],
            'nextAction' => $orders->nextAction($result['order'])], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, string $orderId, ListingStudioOrderService $orders)
    {
        $order = ListingStudioOrder::where('client_id', $request->user()->id)->findOrFail($orderId);

        return $this->json(['order' => $orders->present($order)]);
    }

    public function cancel(Request $request, string $orderId, ListingStudioOrderService $orders)
    {
        $order = $orders->cancelUnpaidOrder($request->user(), $orderId);

        return $this->json(['order' => $orders->present($order), 'nextAction' => $orders->nextAction($order)]);
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status)->header('Cache-Control', 'no-store');
    }
}
