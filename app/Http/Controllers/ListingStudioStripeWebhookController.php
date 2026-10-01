<?php

namespace App\Http\Controllers;

use App\Services\ListingStudio\StripeSubscriptionSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class ListingStudioStripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeSubscriptionSync $sync): JsonResponse
    {
        $secret = config('listing-studio.webhook_secret');
        if (! config('listing-studio.stripe_enabled') || ! $secret) {
            return response()->json(['error' => 'Listing Studio webhook is not configured.'], 503);
        }
        try {
            $event = Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature'), $secret);
        } catch (\Throwable) {
            return response()->json(['error' => 'Invalid Stripe webhook.'], 400);
        }

        try {
            $eventData = $event->toArray();

            return response()->json(app(\App\Services\ListingStudio\StripeSubscriptionRefundSync::class)->handle($eventData)
                ?? $sync->handle($eventData) ?? ['status' => 'success', 'handled' => false, 'outcome' => 'ignored']);
        } catch (\Throwable $exception) {
            Log::error('Listing Studio Stripe synchronization failed.', [
                'event_hash' => hash('sha256', $event->id), 'error_class' => $exception::class,
            ]);

            return response()->json(['status' => 'retry', 'handled' => false, 'error' => 'Listing Studio synchronization failed.'], 500);
        }
    }
}
