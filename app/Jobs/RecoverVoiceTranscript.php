<?php

namespace App\Jobs;

use App\Services\Voice\VoiceTranscriptRecoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecoverVoiceTranscript implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 65;

    public bool $failOnTimeout = true;

    public function __construct(public string $recoveryId)
    {
        $this->onConnection(config('voice_calls.transcript_connection'));
        $this->onQueue(config('voice_calls.transcript_queue'));
        $this->afterCommit();
    }

    public function handle(VoiceTranscriptRecoveryService $service): void
    {
        $service->run($this->recoveryId);
    }

    public function failed(?\Throwable $exception): void
    {
        \App\Models\VoiceTranscriptRecovery::whereKey($this->recoveryId)->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'uncertain', 'error' => 'The worker did not confirm the transcription result. Retry explicitly after checking the call.', 'completed_at' => now()]);
    }
}
