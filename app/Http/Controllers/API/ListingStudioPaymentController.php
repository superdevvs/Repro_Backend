<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ListingStudio\StudioCheckoutService;
use App\Services\ListingStudio\StudioPaymentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingStudioPaymentController extends Controller
{
    public function order(Request $request, string $orderId, StudioCheckoutService $service): JsonResponse
    {
        return $this->respond(fn () => $service->order($request->user(), $orderId, (string) $request->header('Idempotency-Key')));
    }

    public function subscription(Request $request, StudioCheckoutService $service): JsonResponse
    {
        $data = $request->validate(['planCode' => ['required', 'string', 'in:starter,pro,studio']]);

        return $this->respond(fn () => $service->subscription($request->user(), $data['planCode'], (string) $request->header('Idempotency-Key')));
    }

    public function portal(Request $request, StudioCheckoutService $service): JsonResponse
    {
        return $this->respond(fn () => $service->portal($request->user()));
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $callback()])->header('Cache-Control', 'no-store');
        } catch (StudioPaymentException $error) {
            return response()->json(['success' => false, 'code' => $error->errorCode, 'message' => $error->getMessage(), 'data' => $error->data], $error->httpStatus)->header('Cache-Control', 'no-store');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
            throw $error;
        } catch (\Throwable) {
            return response()->json(['success' => false, 'code' => 'awaiting_reconciliation', 'message' => 'Billing could not be confirmed. Keep this checkout and try again later.'], 503)->header('Cache-Control', 'no-store');
        }
    }
}
