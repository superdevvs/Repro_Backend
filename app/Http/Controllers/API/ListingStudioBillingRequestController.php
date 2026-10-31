<?php

namespace App\Http\Controllers\API;

use App\Exceptions\PublicApiException;
use App\Http\Controllers\Controller;
use App\Services\ListingStudio\ListingStudioBillingRequestService;
use App\Services\ListingStudio\StudioPaymentException;
use Illuminate\Http\Request;

class ListingStudioBillingRequestController extends Controller
{
    public function show(Request $request, string $requestId, ListingStudioBillingRequestService $requests)
    {
        return $this->json($this->payment(fn () => $requests->review($request->user(), $requestId)));
    }

    public function confirm(Request $request, string $requestId, ListingStudioBillingRequestService $requests)
    {
        $data = $request->validate(['reviewId' => ['required', 'uuid'], 'confirmationToken' => ['required', 'string', 'max:256'], 'acceptCharges' => ['required', 'boolean']]);

        return $this->json($this->payment(fn () => $requests->confirm($request->user(), $requestId, $data)));
    }

    public function cancel(Request $request, string $requestId, ListingStudioBillingRequestService $requests)
    {
        $row = $requests->owned($request->user(), $requestId);

        return $this->json(['request' => $requests->present($requests->cancel($row, $request->user()), true)]);
    }

    private function payment(callable $callback): array
    {
        try {
            return $callback();
        } catch (StudioPaymentException $error) {
            throw new PublicApiException($error->getMessage(), $error->errorCode, $error->httpStatus, $error->data);
        }
    }

    private function json(array $data)
    {
        return response()->json(['success' => true, 'data' => $data])->header('Cache-Control', 'no-store');
    }
}
