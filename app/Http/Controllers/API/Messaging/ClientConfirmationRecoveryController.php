<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\Controller;
use App\Models\ShootEmailDelivery;
use App\Services\Messaging\ClientConfirmationRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientConfirmationRecoveryController extends Controller
{
    public function __construct(
        private readonly ClientConfirmationRecoveryService $clientConfirmationRecoveryService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:' . implode(',', [
                ShootEmailDelivery::STATUS_SENT,
                ShootEmailDelivery::STATUS_FAILED,
                ShootEmailDelivery::STATUS_SKIPPED,
            ])],
            'shoot_id' => ['nullable', 'integer', 'exists:shoots,id'],
            'client_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $deliveries = $this->clientConfirmationRecoveryService->listRecoveryCandidates($filters);
        $deliveries->setCollection(
            $deliveries->getCollection()->map(fn (ShootEmailDelivery $delivery) => $this->serializeDelivery($delivery))
        );

        return response()->json($deliveries);
    }

    public function replay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'delivery_ids' => ['required', 'array', 'min:1'],
            'delivery_ids.*' => ['integer', 'distinct'],
        ]);

        $result = $this->clientConfirmationRecoveryService->replay($validated['delivery_ids']);

        return response()->json([
            'replayed' => array_map(
                fn ($delivery) => $this->serializeDelivery($delivery),
                $result['replayed']
            ),
            'rejected' => array_map(function (array $rejection) {
                $delivery = $rejection['delivery'] ?? null;

                return [
                    'delivery_id' => $rejection['delivery_id'],
                    'reason' => $rejection['reason'],
                    'delivery' => $delivery instanceof ShootEmailDelivery
                        ? $this->serializeDelivery($delivery)
                        : null,
                ];
            }, $result['rejected']),
        ]);
    }

    private function serializeDelivery(ShootEmailDelivery $delivery): array
    {
        $shoot = $delivery->shoot;
        $client = $delivery->recipient ?? $shoot?->client;
        $message = $delivery->lastMessage;

        return [
            'id' => $delivery->id,
            'event_type' => $delivery->event_type,
            'recipient_type' => $delivery->recipient_type,
            'status' => $delivery->status,
            'source' => $delivery->source,
            'reason_code' => $delivery->reason_code,
            'attempt_count' => $delivery->attempt_count,
            'last_attempted_at' => $delivery->last_attempted_at?->toIso8601String(),
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'recovered_at' => $delivery->recovered_at?->toIso8601String(),
            'last_error_message' => $delivery->last_error_message,
            'last_message_id' => $delivery->last_message_id,
            'shoot' => $shoot ? [
                'id' => $shoot->id,
                'status' => $shoot->status,
                'workflow_status' => $shoot->workflow_status,
                'address' => $shoot->address,
                'city' => $shoot->city,
                'state' => $shoot->state,
                'zip' => $shoot->zip,
                'scheduled_at' => $shoot->scheduled_at?->toIso8601String(),
            ] : null,
            'client' => $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
            ] : null,
            'last_message' => $message ? [
                'id' => $message->id,
                'status' => $message->status,
                'send_source' => $message->send_source,
                'to_address' => $message->to_address,
                'sent_at' => $message->sent_at?->toIso8601String(),
                'failed_at' => $message->failed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
