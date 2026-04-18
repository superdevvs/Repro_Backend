<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\Shoot;
use App\Models\ShootEmailDelivery;
use App\Models\User;
use App\Services\MailService;
use App\Services\Shoots\ShootMutationSupportService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ClientConfirmationRecoveryService
{
    public function __construct(
        private readonly MailService $mailService,
        private readonly ShootMutationSupportService $shootMutationSupportService,
    ) {
    }

    public function hasDeliverableEmail(?User $user): bool
    {
        return $this->shootMutationSupportService->hasDeliverableEmail($user);
    }

    public function recordAutomationSent(Shoot $shoot, User $recipient, CarbonInterface $attemptStartedAt): ShootEmailDelivery
    {
        $message = $this->findLatestMessage(
            $shoot,
            $recipient,
            'AUTOMATION',
            $attemptStartedAt
        );

        return $this->upsertDelivery($shoot, $recipient, [
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_AUTOMATION,
            'reason_code' => null,
            'last_attempted_at' => now(),
            'sent_at' => $message?->sent_at ?? now(),
            'recovered_at' => null,
            'last_message_id' => $message?->id,
            'last_error_message' => null,
        ]);
    }

    public function recordFallbackSent(Shoot $shoot, User $recipient, CarbonInterface $attemptStartedAt): ShootEmailDelivery
    {
        $message = $this->findLatestMessage(
            $shoot,
            $recipient,
            'SHOOT_SCHEDULED',
            $attemptStartedAt
        );

        return $this->upsertDelivery($shoot, $recipient, [
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_FALLBACK,
            'reason_code' => null,
            'last_attempted_at' => now(),
            'sent_at' => $message?->sent_at ?? now(),
            'recovered_at' => null,
            'last_message_id' => $message?->id,
            'last_error_message' => null,
        ]);
    }

    public function recordReplaySent(Shoot $shoot, User $recipient, CarbonInterface $attemptStartedAt): ShootEmailDelivery
    {
        $message = $this->findLatestMessage(
            $shoot,
            $recipient,
            'SHOOT_SCHEDULED',
            $attemptStartedAt
        );

        return $this->upsertDelivery($shoot, $recipient, [
            'status' => ShootEmailDelivery::STATUS_SENT,
            'source' => ShootEmailDelivery::SOURCE_REPLAY,
            'reason_code' => null,
            'last_attempted_at' => now(),
            'sent_at' => $message?->sent_at ?? now(),
            'recovered_at' => now(),
            'last_message_id' => $message?->id,
            'last_error_message' => null,
        ]);
    }

    public function recordSkippedMissingEmail(Shoot $shoot, ?User $recipient, string $triggerType, string $source = ShootEmailDelivery::SOURCE_FALLBACK): ShootEmailDelivery
    {
        Log::warning('Skipping client confirmation delivery because client has no deliverable email.', [
            'shoot_id' => $shoot->id,
            'client_id' => $recipient?->id ?? $shoot->client_id,
            'trigger_type' => $triggerType,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
        ]);

        return $this->upsertDelivery($shoot, $recipient, [
            'status' => ShootEmailDelivery::STATUS_SKIPPED,
            'source' => $source,
            'reason_code' => ShootEmailDelivery::REASON_MISSING_EMAIL,
            'last_attempted_at' => now(),
            'sent_at' => null,
            'recovered_at' => null,
            'last_message_id' => null,
            'last_error_message' => null,
        ]);
    }

    public function recordNoDeliveryPath(Shoot $shoot, ?User $recipient, string $triggerType, string $source = ShootEmailDelivery::SOURCE_FALLBACK): ShootEmailDelivery
    {
        Log::warning('Skipping client confirmation delivery because no client delivery path could be resolved.', [
            'shoot_id' => $shoot->id,
            'client_id' => $recipient?->id ?? $shoot->client_id,
            'trigger_type' => $triggerType,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
        ]);

        return $this->upsertDelivery($shoot, $recipient, [
            'status' => ShootEmailDelivery::STATUS_SKIPPED,
            'source' => $source,
            'reason_code' => ShootEmailDelivery::REASON_NO_DELIVERY_PATH,
            'last_attempted_at' => now(),
            'sent_at' => null,
            'recovered_at' => null,
            'last_message_id' => null,
            'last_error_message' => null,
        ]);
    }

    public function recordProviderFailure(
        Shoot $shoot,
        ?User $recipient,
        string $source,
        CarbonInterface $attemptStartedAt,
        ?string $errorMessage = null
    ): ShootEmailDelivery {
        $message = $recipient
            ? $this->findLatestMessage($shoot, $recipient, 'SHOOT_SCHEDULED', $attemptStartedAt)
            : null;

        return $this->upsertDelivery($shoot, $recipient, [
            'status' => ShootEmailDelivery::STATUS_FAILED,
            'source' => $source,
            'reason_code' => ShootEmailDelivery::REASON_PROVIDER_ERROR,
            'last_attempted_at' => now(),
            'sent_at' => null,
            'recovered_at' => null,
            'last_message_id' => $message?->id,
            'last_error_message' => $message?->error_message
                ?? $errorMessage
                ?? 'Client confirmation send failed before a message record could be linked.',
        ]);
    }

    public function listRecoveryCandidates(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        return ShootEmailDelivery::query()
            ->with(['shoot.client', 'recipient', 'lastMessage'])
            ->where('event_type', ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION)
            ->where('recipient_type', ShootEmailDelivery::RECIPIENT_CLIENT)
            ->when(
                !empty($filters['status']),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                !empty($filters['shoot_id']),
                fn ($query) => $query->where('shoot_id', (int) $filters['shoot_id'])
            )
            ->when(
                !empty($filters['client_id']),
                fn ($query) => $query->where('recipient_user_id', (int) $filters['client_id'])
            )
            ->orderByDesc('last_attempted_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function replay(array $deliveryIds): array
    {
        $deliveries = ShootEmailDelivery::query()
            ->with(['shoot.client', 'shoot.photographer', 'shoot.rep', 'shoot.services.category', 'recipient', 'lastMessage'])
            ->where('event_type', ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION)
            ->where('recipient_type', ShootEmailDelivery::RECIPIENT_CLIENT)
            ->whereIn('id', $deliveryIds)
            ->get()
            ->keyBy('id');

        $replayed = [];
        $rejected = [];

        foreach ($deliveryIds as $deliveryId) {
            $delivery = $deliveries->get((int) $deliveryId);

            if (!$delivery) {
                $rejected[] = [
                    'delivery_id' => (int) $deliveryId,
                    'reason' => 'Delivery record was not found.',
                ];
                continue;
            }

            $shoot = $delivery->shoot;
            $client = $shoot?->client;

            $eligibilityError = $this->resolveReplayEligibilityError($shoot, $client);
            if ($eligibilityError !== null) {
                $rejected[] = [
                    'delivery_id' => $delivery->id,
                    'reason' => $eligibilityError,
                    'delivery' => $delivery->fresh(['shoot.client', 'recipient', 'lastMessage']),
                ];
                continue;
            }

            $attemptStartedAt = now();
            $paymentLink = $this->mailService->generatePaymentLink($shoot);
            $sent = $this->mailService->sendShootScheduledEmail($client, $shoot, $paymentLink, false);

            if ($sent) {
                $replayed[] = $this->recordReplaySent($shoot, $client, $attemptStartedAt)
                    ->load(['shoot.client', 'recipient', 'lastMessage']);

                continue;
            }

            $rejected[] = [
                'delivery_id' => $delivery->id,
                'reason' => 'Replay send failed.',
                'delivery' => $this->recordProviderFailure(
                    $shoot,
                    $client,
                    ShootEmailDelivery::SOURCE_REPLAY,
                    $attemptStartedAt,
                    'Replay send failed.'
                )->load(['shoot.client', 'recipient', 'lastMessage']),
            ];
        }

        return [
            'replayed' => $replayed,
            'rejected' => $rejected,
        ];
    }

    public function auditRows(): Collection
    {
        return ShootEmailDelivery::query()
            ->with(['shoot.client', 'recipient'])
            ->where('event_type', ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION)
            ->where('recipient_type', ShootEmailDelivery::RECIPIENT_CLIENT)
            ->whereIn('status', [
                ShootEmailDelivery::STATUS_FAILED,
                ShootEmailDelivery::STATUS_SKIPPED,
            ])
            ->orderByDesc('last_attempted_at')
            ->orderByDesc('id')
            ->get();
    }

    private function upsertDelivery(Shoot $shoot, ?User $recipient, array $attributes): ShootEmailDelivery
    {
        $delivery = ShootEmailDelivery::query()->firstOrNew([
            'shoot_id' => $shoot->id,
            'event_type' => ShootEmailDelivery::EVENT_SHOOT_SCHEDULED_CONFIRMATION,
            'recipient_type' => ShootEmailDelivery::RECIPIENT_CLIENT,
        ]);

        $delivery->fill($attributes);
        $delivery->recipient_user_id = $recipient?->id ?? $shoot->client_id;
        $delivery->attempt_count = ((int) $delivery->attempt_count) + 1;
        $delivery->save();

        return $delivery->fresh();
    }

    private function findLatestMessage(
        Shoot $shoot,
        User $recipient,
        string $sendSource,
        CarbonInterface $attemptStartedAt
    ): ?Message {
        $normalizedEmail = strtolower(trim((string) $recipient->email));

        return Message::query()
            ->where('channel', 'EMAIL')
            ->where('send_source', $sendSource)
            ->where('related_shoot_id', $shoot->id)
            ->whereRaw('LOWER(to_address) = ?', [$normalizedEmail])
            ->where('created_at', '>=', $attemptStartedAt->copy()->subSecond())
            ->latest('id')
            ->first();
    }

    private function resolveReplayEligibilityError(?Shoot $shoot, ?User $client): ?string
    {
        if (!$shoot) {
            return 'Shoot was not found for this delivery.';
        }

        if (!$client) {
            return 'Client account could not be resolved for this shoot.';
        }

        if (!$this->hasDeliverableEmail($client)) {
            return 'Client does not have a deliverable primary email.';
        }

        $status = $shoot->workflow_status ?: $shoot->status;
        $allowedStatuses = [
            Shoot::STATUS_SCHEDULED,
            Shoot::STATUS_UPLOADED,
            Shoot::STATUS_EDITING,
            Shoot::STATUS_READY,
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            return sprintf('Shoot status "%s" is not eligible for confirmation replay.', $status);
        }

        return null;
    }
}
