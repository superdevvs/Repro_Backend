<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ClientDeliveryNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientDeliveryNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || strtolower((string) $user->role) !== 'client') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $baseQuery = ClientDeliveryNotification::query()->where('user_id', $user->id);
        $unseenCount = (clone $baseQuery)->whereNull('seen_at')->count();
        $entries = $baseQuery
            ->with('shoot:id,address,city,state,zip')
            ->latest('delivered_at')
            ->limit(20)
            ->get()
            ->map(fn (ClientDeliveryNotification $notification) => $this->serialize($notification));

        return response()->json([
            'data' => [
                'unseen_count' => $unseenCount,
                'entries' => $entries,
            ],
        ]);
    }

    public function seen(Request $request, ClientDeliveryNotification $notification): JsonResponse
    {
        $user = $request->user();
        if (
            ! $user
            || strtolower((string) $user->role) !== 'client'
            || (string) $notification->user_id !== (string) $user->id
        ) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $notification->seen_at) {
            $notification->forceFill(['seen_at' => now()])->save();
        }

        return response()->json([
            'data' => $this->serialize($notification->loadMissing('shoot:id,address,city,state,zip')),
        ]);
    }

    private function serialize(ClientDeliveryNotification $notification): array
    {
        $shoot = $notification->shoot;
        $address = $shoot
            ? trim(implode(', ', array_filter([
                $shoot->address,
                trim(implode(' ', array_filter([$shoot->city, $shoot->state]))),
                $shoot->zip,
            ])))
            : null;

        return [
            'id' => $notification->id,
            'shoot_id' => $notification->shoot_id,
            'address' => $address ?: 'Delivered shoot',
            'delivered_at' => $notification->delivered_at?->toIso8601String(),
            'seen_at' => $notification->seen_at?->toIso8601String(),
        ];
    }
}
