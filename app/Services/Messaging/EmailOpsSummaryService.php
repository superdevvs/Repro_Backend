<?php

namespace App\Services\Messaging;

use App\Models\Message;
use App\Models\Shoot;
use App\Models\ShootEmailDelivery;
use Illuminate\Database\Eloquent\Builder;

class EmailOpsSummaryService
{
    public function __construct(
        private readonly SystemEmailHealthCheckService $systemEmailHealthCheckService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $sampleLimit = 5, int $queuedMinutes = 5): array
    {
        $sampleLimit = max(1, $sampleLimit);
        $queuedMinutes = max(1, $queuedMinutes);
        $queuedCutoff = now()->subMinutes($queuedMinutes);

        $missingEmailShootsQuery = Shoot::query()
            ->with('client')
            ->whereIn('workflow_status', [
                Shoot::STATUS_REQUESTED,
                Shoot::STATUS_SCHEDULED,
                Shoot::STATUS_UPLOADED,
                Shoot::STATUS_EDITING,
                Shoot::STATUS_READY,
            ])
            ->whereHas('client', function (Builder $query): void {
                $query->whereNull('email')
                    ->orWhereRaw("TRIM(COALESCE(email, '')) = ''");
            });

        $failedMessagesQuery = Message::query()
            ->where('channel', 'EMAIL')
            ->where('direction', 'OUTBOUND')
            ->where('status', 'FAILED');

        $queuedMessagesQuery = Message::query()
            ->where('channel', 'EMAIL')
            ->where('direction', 'OUTBOUND')
            ->where('status', 'QUEUED')
            ->where('created_at', '<=', $queuedCutoff);

        $failedDeliveriesQuery = ShootEmailDelivery::query()
            ->with(['shoot', 'recipient'])
            ->where('status', ShootEmailDelivery::STATUS_FAILED);

        $skippedDeliveriesQuery = ShootEmailDelivery::query()
            ->with(['shoot', 'recipient'])
            ->where('status', ShootEmailDelivery::STATUS_SKIPPED);

        $summary = [
            'health' => $this->systemEmailHealthCheckService->inspect(),
            'queued_retry_threshold_minutes' => $queuedMinutes,
            'counts' => [
                'live_shoots_blocked_by_missing_client_email' => (clone $missingEmailShootsQuery)->count(),
                'failed_outbound_messages' => (clone $failedMessagesQuery)->count(),
                'queued_outbound_messages_beyond_retry_threshold' => (clone $queuedMessagesQuery)->count(),
                'failed_client_confirmations' => (clone $failedDeliveriesQuery)->count(),
                'skipped_client_confirmations' => (clone $skippedDeliveriesQuery)->count(),
            ],
            'samples' => [
                'live_shoots_missing_client_email' => (clone $missingEmailShootsQuery)
                    ->orderByDesc('updated_at')
                    ->limit($sampleLimit)
                    ->get()
                    ->map(fn (Shoot $shoot) => $this->formatMissingEmailShoot($shoot))
                    ->values()
                    ->all(),
                'failed_messages' => (clone $failedMessagesQuery)
                    ->orderByDesc('failed_at')
                    ->orderByDesc('updated_at')
                    ->limit($sampleLimit)
                    ->get()
                    ->map(fn (Message $message) => $this->formatMessageIssue($message, 'failed'))
                    ->values()
                    ->all(),
                'queued_messages' => (clone $queuedMessagesQuery)
                    ->orderBy('created_at')
                    ->limit($sampleLimit)
                    ->get()
                    ->map(fn (Message $message) => $this->formatMessageIssue($message, 'queued'))
                    ->values()
                    ->all(),
                'failed_client_confirmations' => (clone $failedDeliveriesQuery)
                    ->orderByDesc('last_attempted_at')
                    ->limit($sampleLimit)
                    ->get()
                    ->map(fn (ShootEmailDelivery $delivery) => $this->formatDeliveryIssue($delivery))
                    ->values()
                    ->all(),
                'skipped_client_confirmations' => (clone $skippedDeliveriesQuery)
                    ->orderByDesc('last_attempted_at')
                    ->limit($sampleLimit)
                    ->get()
                    ->map(fn (ShootEmailDelivery $delivery) => $this->formatDeliveryIssue($delivery))
                    ->values()
                    ->all(),
            ],
        ];

        $summary['blocking_issues_present'] = $this->hasBlockingIssues($summary);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function hasBlockingIssues(array $summary): bool
    {
        if (($summary['health']['healthy'] ?? false) !== true) {
            return true;
        }

        foreach (($summary['counts'] ?? []) as $count) {
            if ((int) $count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMissingEmailShoot(Shoot $shoot): array
    {
        return [
            'kind' => 'live_shoot_missing_client_email',
            'shoot_id' => $shoot->id,
            'client_id' => $shoot->client?->id,
            'recipient_type' => 'client',
            'trigger_source' => 'missing_primary_email',
            'latest_reason' => 'Client account has no deliverable primary email.',
            'workflow_status' => $shoot->workflow_status,
            'status' => $shoot->status,
            'updated_at' => $shoot->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMessageIssue(Message $message, string $kind): array
    {
        return [
            'kind' => $kind === 'queued' ? 'queued_outbound_message' : 'failed_outbound_message',
            'message_id' => $message->id,
            'shoot_id' => $message->related_shoot_id,
            'client_id' => $message->related_account_id,
            'recipient_type' => $message->related_account_id !== null ? 'client' : 'unknown',
            'trigger_source' => $message->send_source ?? 'unknown',
            'latest_reason' => trim((string) ($message->error_message ?: 'Queued beyond retry threshold.')),
            'status' => $message->status,
            'created_at' => $message->created_at?->toIso8601String(),
            'updated_at' => $message->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDeliveryIssue(ShootEmailDelivery $delivery): array
    {
        return [
            'kind' => 'client_confirmation_delivery',
            'delivery_id' => $delivery->id,
            'shoot_id' => $delivery->shoot_id,
            'client_id' => $delivery->recipient_user_id,
            'recipient_type' => $delivery->recipient_type,
            'trigger_source' => $delivery->event_type,
            'source' => $delivery->source,
            'latest_reason' => trim((string) ($delivery->last_error_message ?: $delivery->reason_code ?: 'unknown')),
            'status' => $delivery->status,
            'last_attempted_at' => $delivery->last_attempted_at?->toIso8601String(),
        ];
    }
}
