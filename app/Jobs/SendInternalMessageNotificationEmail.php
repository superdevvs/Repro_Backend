<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\InternalMessageNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendInternalMessageNotificationEmail implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $messageId,
        public readonly int $recipientId,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return $this->messageId . ':' . $this->recipientId;
    }

    public function handle(InternalMessageNotificationService $notifications): void
    {
        $message = Message::query()->find($this->messageId);
        $recipient = User::query()->find($this->recipientId);

        if (!$message || !$recipient) {
            Log::info('Skipping internal-message email notification because its source or recipient no longer exists.', [
                'internal_message_id' => $this->messageId,
                'recipient_user_id' => $this->recipientId,
            ]);

            return;
        }

        try {
            $notifications->deliver($message, $recipient);
        } catch (\InvalidArgumentException|\LogicException $exception) {
            // Invalid payload/recipient state is permanent and should not consume
            // retries intended for temporary provider or network failures.
            Log::error('Internal-message email notification has a permanent delivery error.', [
                'internal_message_id' => $this->messageId,
                'recipient_user_id' => $this->recipientId,
                'error' => $exception->getMessage(),
            ]);

            $this->fail($exception);
        } catch (\Throwable $exception) {
            Log::warning('Internal-message email notification delivery failed; the queue will retry.', [
                'internal_message_id' => $this->messageId,
                'recipient_user_id' => $this->recipientId,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Internal-message email notification exhausted its retries.', [
            'internal_message_id' => $this->messageId,
            'recipient_user_id' => $this->recipientId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
