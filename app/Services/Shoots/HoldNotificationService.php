<?php

namespace App\Services\Shoots;

use App\Models\Shoot;
use App\Models\User;
use App\Services\Messaging\ManualNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/** Explicit hold notifications run only after the workflow write has committed. */
class HoldNotificationService
{
    public function __construct(private readonly ManualNotificationService $manual) {}

    public static function rules(): array
    {
        return [
            'notify_client' => ['sometimes', 'boolean'],
            'notify_photographer' => ['sometimes', 'boolean'],
            'notification_channels' => ['sometimes', 'array', 'min:1', 'max:2'],
            'notification_channels.*' => ['required', 'string', 'distinct', Rule::in(['email', 'sms'])],
        ];
    }

    public function send(Shoot $shoot, User $sender, array $options): array
    {
        $summary = [
            'requested' => (bool) ($options['notify_client'] ?? false) || (bool) ($options['notify_photographer'] ?? false),
            'sent' => 0, 'queued' => 0, 'failed' => 0, 'skipped' => 0, 'deliveries' => [],
        ];
        $channels = $options['notification_channels'] ?? ['email'];

        foreach (['client', 'photographer'] as $type) {
            if (! ($options['notify_'.$type] ?? false)) {
                continue;
            }

            try {
                $recipients = $this->manual->listRecipients($shoot, $type, 'shoot_on_hold');
            } catch (\Throwable $exception) {
                $this->recordFailure($shoot, $type, $exception);
                foreach ($channels as $channel) {
                    $this->record($summary, $type, null, $channel, 'failed');
                }
                continue;
            }

            foreach ($channels as $channel) {
                if ($recipients === []) {
                    $this->record($summary, $type, null, $channel, 'skipped');
                    continue;
                }

                // Send separately so one bad address/provider failure cannot prevent
                // delivery to another assigned photographer or hide a partial result.
                foreach ($recipients as $recipient) {
                    try {
                        $message = $this->manual->send($shoot, 'shoot_on_hold', $type, $channel, $sender, $recipient['id']);
                        $outcome = match (strtoupper((string) $message->status)) {
                            'SENT', 'DELIVERED' => 'sent',
                            'QUEUED', 'PENDING', 'SENDING', 'SCHEDULED' => 'queued',
                            'BLOCKED', 'CANCELLED', 'SUPPRESSED' => 'skipped',
                            default => 'failed',
                        };
                        $this->record($summary, $type, $recipient['id'], $channel, $outcome);
                    } catch (\Throwable $exception) {
                        $this->recordFailure($shoot, $type, $exception);
                        $this->record($summary, $type, $recipient['id'], $channel, 'failed');
                    }
                }
            }
        }

        return $summary;
    }

    private function record(array &$summary, string $type, ?int $recipientId, string $channel, string $outcome): void
    {
        $summary[$outcome]++;
        $summary['deliveries'][] = [
            'recipient_type' => $type, 'recipient_id' => $recipientId,
            'channel' => $channel, 'status' => $outcome,
        ];
    }

    private function recordFailure(Shoot $shoot, string $type, \Throwable $exception): void
    {
        Log::error('Hold saved but notification dispatch failed.', [
            'shoot_id' => $shoot->id, 'recipient_type' => $type, 'error' => $exception->getMessage(),
        ]);
    }
}
