<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Messaging\AiSms\SmsAiAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInboundSmsAiJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public readonly int $messageId)
    {
    }

    public function uniqueId(): string
    {
        return 'sms-ai:' . $this->messageId;
    }

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(SmsAiAgentService $agent): void
    {
        $message = Message::find($this->messageId);
        if (!$message) {
            return;
        }

        if (!empty(($message->metadata ?? [])['ai_processed'])) {
            return;
        }

        try {
            $agent->handleInbound($message);
        } catch (\Throwable $e) {
            Log::error('ProcessInboundSmsAiJob failed', [
                'message_id' => $this->messageId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
